<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_browse_active_products_but_account_actions_require_login(): void
    {
        $product = FurnitureProduct::create(['name' => 'Public Chair', 'slug' => 'public-chair', 'description' => 'Wooden chair', 'category' => 'Chair', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 1000, 'status' => 'active']);
        $hidden = FurnitureProduct::create(['name' => 'Hidden Chair', 'slug' => 'hidden-chair', 'description' => 'Wooden chair', 'category' => 'Chair', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 1000, 'status' => 'inactive']);
        $this->get(route('catalog.index'))->assertOk()->assertSee('Public Chair')->assertDontSee('Hidden Chair')->assertSee('Log in');
        $this->getJson(route('catalog.search', ['q' => 'Chair']))->assertOk()->assertJsonCount(1)->assertJsonPath('0.saved', false);
        $this->get(route('catalog.show', $product))->assertOk()->assertSee('Public Chair')->assertSee('Log in to request reservation')->assertDontSee('data-reservation-form', false);
        $this->get(route('catalog.show', $hidden))->assertNotFound();
        $this->postJson(route('catalog.favorite', $product))->assertUnauthorized();
        $this->postJson(route('reservations.store'), ['furniture_product_id' => $product->id])->assertUnauthorized();
        $this->getJson(route('favorites.index'))->assertUnauthorized();
        $this->assertDatabaseCount('favorites', 0);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_typeahead_ranks_a_strong_match_after_the_first_thirty_candidates(): void
    {
        for ($index = 1; $index <= 30; $index++) {
            FurnitureProduct::create([
                'name' => "Formal Dining Table Option {$index}",
                'slug' => "formal-dining-table-option-{$index}",
                'description' => 'Dining table option.',
                'category' => 'Dining Table',
                'wood_type' => 'Gmelina',
                'varnish_finish' => 'Natural',
                'style' => 'Modern',
                'price' => 1000,
                'status' => 'active',
            ]);
        }

        $bestMatch = FurnitureProduct::create([
            'name' => 'Dining Table Signature',
            'slug' => 'dining-table-signature',
            'description' => 'Dining table signature option.',
            'category' => 'Dining Table',
            'wood_type' => 'Gmelina',
            'varnish_finish' => 'Natural',
            'style' => 'Modern',
            'price' => 1000,
            'status' => 'active',
        ]);

        $response = $this->getJson(route('catalog.search', ['q' => 'dining']))
            ->assertOk()
            ->assertJsonCount(8);

        $this->assertSame($bestMatch->name, $response->json('0.name'));
        $this->assertContains($bestMatch->name, collect($response->json())->pluck('name')->all());
    }
}
