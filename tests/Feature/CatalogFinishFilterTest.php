<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogFinishFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_finish_selector_uses_active_catalog_options_for_guests_and_customers(): void
    {
        $this->product('natural-chair', 'Natural');
        $this->product('brown-chair', 'Chocolate Brown');
        $this->product('hidden-chair', 'Hidden finish', ['status' => 'inactive']);
        $customer = User::factory()->create();
        foreach ([false, true] as $signedIn) {
            if ($signedIn) $this->actingAs($customer);
            $this->get(route('catalog.index'))->assertOk()
                ->assertSee('for="finish">Finish</label>', false)
                ->assertSee('id="finish" name="varnish_finish"', false)
                ->assertSee('Chocolate Brown')->assertDontSee('Hidden finish')
                ->assertSee('Catalog listings do not confirm stock.');
        }
    }

    public function test_finish_combines_with_other_filters_and_remains_selected(): void
    {
        $match = $this->product('matching-chair', 'Chocolate Brown');
        $this->product('wrong-finish', 'Natural');
        $this->product('wrong-category', 'Chocolate Brown', ['category' => 'Door']);
        $this->product('over-budget', 'Chocolate Brown', ['price' => 2000]);
        $this->product('inactive', 'Chocolate Brown', ['status' => 'inactive']);
        foreach ([false, true] as $signedIn) {
            if ($signedIn) $this->actingAs(User::factory()->create());
            $response = $this->get(route('catalog.index', [
                'q' => 'Chair', 'category' => 'Chair', 'wood_type' => 'Gmelina', 'style' => 'Modern',
                'varnish_finish' => 'Chocolate Brown', 'min_price' => '999.99', 'max_price' => '1000.01',
            ]))->assertOk()->assertSee('<option selected>Chocolate Brown</option>', false);
            $this->assertSame([$match->id], $response->viewData('products')->pluck('id')->all());
        }
    }

    public function test_finish_is_preserved_on_later_pages_and_can_be_cleared(): void
    {
        foreach (range(1, 17) as $index) $this->product('natural-'.$index, 'Natural');
        $this->product('brown-chair', 'Chocolate Brown');
        $first = $this->get(route('catalog.index', ['varnish_finish' => 'Natural']))->assertOk();
        $this->assertSame(17, $first->viewData('products')->total());
        $this->assertCount(16, $first->viewData('products'));
        $this->assertStringContainsString('varnish_finish=Natural', $first->viewData('products')->nextPageUrl());
        $second = $this->get(route('catalog.index', ['varnish_finish' => 'Natural', 'page' => 2]))
            ->assertOk()->assertSee('<option selected>Natural</option>', false);
        $this->assertCount(1, $second->viewData('products'));
        $this->assertSame(18, $this->get(route('catalog.index'))->assertOk()->viewData('products')->total());
    }

    private function product(string $slug, string $finish, array $attributes = []): FurnitureProduct
    {
        return FurnitureProduct::create(array_replace([
            'name' => 'Test Chair '.$slug, 'slug' => $slug, 'category' => 'Chair',
            'description' => 'A chair', 'wood_type' => 'Gmelina', 'varnish_finish' => $finish,
            'style' => 'Modern', 'price' => 1000, 'status' => 'active',
        ], $attributes));
    }
}
