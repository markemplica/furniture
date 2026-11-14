<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogInputValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_malformed_catalog_queries_render_recoverable_forms_for_guests_and_customers(): void
    {
        $cases = [
            ['q' => ['door']], ['category' => ['Chair']], ['style' => ['Modern']],
            ['wood_type' => ['Gmelina']], ['varnish_finish' => ['Natural']],
            ['min_price' => ['1']], ['max_price' => ['2']], ['page' => ['1']],
            ['q' => str_repeat('a', 256)], ['category' => str_repeat('a', 256)],
            ['min_price' => 'abc'], ['min_price' => '-1'], ['max_price' => '1e100'],
            ['min_price' => '100', 'max_price' => '50'], ['page' => '0'],
            ['page' => '1.5'], ['page' => '999999999999999999999'],
        ];
        $customer = User::factory()->create();
        foreach ([false, true] as $signedIn) {
            if ($signedIn) $this->actingAs($customer);
            foreach ($cases as $query) {
                $this->get('/catalog?'.http_build_query($query))
                    ->assertStatus(422)->assertSee('Please check the form.')
                    ->assertSee('id="catalog-filter-form"', false);
            }
            $this->get('/catalog')->assertOk()->assertDontSee('Please check the form.');
        }
    }

    public function test_json_validation_reports_invalid_fields_on_both_search_routes(): void
    {
        foreach (['/catalog', '/catalog/search'] as $path) {
            foreach ([['q' => ['door']], ['q' => str_repeat('a', 256)]] as $query) {
                $this->getJson($path.'?'.http_build_query($query))
                    ->assertUnprocessable()->assertJsonValidationErrors('q');
            }
        }
        $this->getJson('/catalog?min_price=100&max_price=50')
            ->assertUnprocessable()->assertJsonValidationErrors('max_price');
        $this->getJson('/catalog/search?q=x')->assertOk()->assertExactJson([]);
        $this->getJson('/catalog/search')->assertOk()->assertExactJson([]);
        $this->get('/catalog/search?q%5B%5D=door')->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_valid_filters_and_decimal_price_boundaries_keep_working(): void
    {
        FurnitureProduct::create([
            'name' => 'Boundary Chair', 'slug' => 'boundary-chair', 'category' => 'Chair',
            'description' => 'A chair', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural',
            'style' => 'Modern', 'price' => '1000.50', 'status' => 'active',
        ]);
        $query = ['q' => 'Chair', 'category' => 'Chair', 'style' => 'Modern',
            'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural',
            'min_price' => '1000.50', 'max_price' => '1000.50', 'page' => '1'];
        $this->get('/catalog?'.http_build_query($query))->assertOk()->assertSee('Boundary Chair');
        $this->get('/catalog?max_price=1000.49')->assertOk()->assertDontSee('Boundary Chair');
        $this->get('/catalog?max_price=9999999999.99')->assertOk()->assertSee('Boundary Chair');
        $this->get('/catalog?category=Unknown')->assertOk()->assertSee('No furniture matches these filters.');
        $this->getJson('/catalog/search?q=Chair')->assertOk()->assertJsonPath('0.name', 'Boundary Chair');
        $this->get('/catalog?category=Chair&q%5B%5D=bad')->assertStatus(422)
            ->assertSee('Boundary Chair')->assertSee('<option selected>Chair</option>', false);
    }
}
