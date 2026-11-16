<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogCategoryOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $category, string $status = 'active'): FurnitureProduct
    {
        return FurnitureProduct::create([
            'name' => $category.' sample', 'slug' => Str::slug($category).'-'.$status,
            'category' => $category, 'description' => 'Catalog category fixture',
            'wood_type' => 'Mahogany', 'varnish_finish' => 'Natural', 'style' => 'Traditional',
            'price' => 5000, 'status' => $status,
        ]);
    }

    public function test_nine_category_choices_filter_exact_matches_for_guests_and_customers(): void
    {
        $categories = ['Plain Door', 'Special Door', 'Full Curving', 'Customized Door', 'Butterfly Door', 'Dining Set', 'Sala Set', 'Curving Bed', 'Console'];
        $products = collect($categories)->mapWithKeys(fn ($category) => [$category => $this->product($category)]);
        $this->product('Plain Door', 'inactive');
        foreach ([false, true] as $signedIn) {
            if ($signedIn) $this->actingAs(User::factory()->create());
            foreach ($categories as $category) {
                $response = $this->get(route('catalog.index', ['category' => $category]))->assertOk();
                $this->assertSame($categories, $response->viewData('options')['categories']->all());
                $this->assertSame([$products[$category]->id], $response->viewData('products')->pluck('id')->all());
                $response->assertSee('<option selected>'.$category.'</option>', false);
            }
        }
    }

    public function test_legacy_products_remain_visible_without_reclassification(): void
    {
        $legacy = $this->product('Wooden Door');
        $this->get(route('catalog.index'))->assertOk()->assertSee($legacy->name);
        $this->assertSame('Wooden Door', $legacy->fresh()->category);
    }

    public function test_admin_category_selector_keeps_existing_category_until_owner_assigns_one(): void
    {
        $legacy = $this->product('Bed Frame');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.products.create'))->assertOk()->assertSee('Choose category')->assertSee('Butterfly Door');
        $this->get(route('admin.products.edit', $legacy))->assertOk()
            ->assertSee('<option value="Bed Frame" selected>Bed Frame (current category)</option>', false)
            ->assertSee('Curving Bed');
        $this->assertSame('Bed Frame', $legacy->fresh()->category);
    }
}
