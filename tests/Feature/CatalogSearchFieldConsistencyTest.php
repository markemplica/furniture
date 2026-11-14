<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSearchFieldConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_routes_match_each_field_with_partial_and_repeated_letter_searches(): void
    {
        $customer = User::factory()->create();
        $expected = [];
        foreach (['name', 'category', 'style', 'description', 'tags'] as $field) {
            $product = $this->product([
                'slug' => 'field-'.$field,
                $field => $field === 'tags' ? ['Sequoia'] : 'Sequoia',
            ]);
            $expected[] = $product->id;
        }
        $this->product(['slug' => 'unrelated']);
        $this->product(['slug' => 'inactive', 'description' => 'Sequoia', 'status' => 'inactive']);

        foreach ([false, true] as $signedIn) {
            if ($signedIn) $this->actingAs($customer);
            foreach ([' Sequoia ', 'Sequ', 'Seequoia'] as $term) {
                $html = $this->get(route('catalog.index', ['q' => $term]))->assertOk();
                $this->assertEqualsCanonicalizing($expected, $html->viewData('products')->pluck('id')->all());
                $json = $this->getJson(route('catalog.search', ['q' => $term]))->assertOk()->assertJsonCount(5);
                $this->assertEqualsCanonicalizing(
                    $html->viewData('products')->map(fn ($product) => route('catalog.show', $product))->all(),
                    array_column($json->json(), 'url'),
                );
            }
        }
    }

    public function test_description_matches_stay_inside_active_status_and_explicit_filters(): void
    {
        $match = $this->product(['slug' => 'matching-chair', 'description' => 'Sequoia wood']);
        $this->product(['slug' => 'wrong-category', 'category' => 'Door', 'description' => 'Sequoia wood']);
        $this->product(['slug' => 'over-budget', 'price' => 5000, 'description' => 'Sequoia wood']);
        $this->product(['slug' => 'hidden', 'status' => 'inactive', 'description' => 'Sequoia wood']);
        $response = $this->get(route('catalog.index', ['q' => 'Sequoia', 'category' => 'Chair', 'max_price' => 1000]))->assertOk();
        $this->assertSame([$match->id], $response->viewData('products')->pluck('id')->all());
        $this->getJson(route('catalog.search', ['q' => 'NoSuchDescription']))->assertOk()->assertExactJson([]);
    }

    private function product(array $attributes): FurnitureProduct
    {
        return FurnitureProduct::create(array_replace([
            'name' => 'Plain furniture', 'category' => 'Chair', 'description' => 'Neutral text',
            'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern',
            'price' => 1000, 'status' => 'active', 'tags' => ['neutral'],
        ], $attributes));
    }
}
