<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\User;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferenceBudgetSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        FurnitureProduct::create(['name' => 'Boundary Chair', 'slug' => 'boundary-chair',
            'category' => 'Chair', 'description' => 'A chair', 'wood_type' => 'Gmelina',
            'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => '10000.50', 'status' => 'active']);
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_invalid_styles_and_unsafe_budget_values_are_rejected_without_writes(): void
    {
        $this->customer();
        $base = ['furniture_type' => 'Chair', 'budget_min' => '0', 'budget_max' => '20000'];
        foreach ([
            ['style' => 'Nonexistent style'], ['style' => ['Modern']],
            ['budget_max' => '1e30'], ['budget_max' => '10000000000'],
            ['budget_min' => '-0.01'], ['budget_min' => '0.001'], ['budget_max' => '100.501'],
            ['budget_min' => ['0']], ['budget_max' => ['100']],
            ['budget_min' => '100', 'budget_max' => '100'], ['budget_max' => '0'],
            ['budget_range' => '0-10000000000'], ['budget_range' => str_repeat('1', 80).'-100'],
            ['budget_range' => ['1000-10000']], ['budget_range' => '1000-2000.001'],
        ] as $override) {
            $this->postJson(route('preferences.update'), array_replace($base, $override))->assertUnprocessable();
            $this->assertDatabaseCount('customer_preferences', 0);
        }
    }

    public function test_database_decimal_boundaries_and_catalog_styles_are_accepted(): void
    {
        $user = $this->customer();
        $this->postJson(route('preferences.update'), ['furniture_type' => 'Chair', 'style' => 'Modern',
            'budget_min' => '0', 'budget_max' => '9999999999.99'])->assertOk()
            ->assertJsonPath('preference.budget_min', '0.00')->assertJsonPath('preference.budget_max', '9999999999.99');
        $this->postJson(route('preferences.update'), ['furniture_type' => 'Chair', 'style' => null,
            'budget_range' => '9999999999.98-9999999999.99'])->assertOk();
        $this->assertSame('9999999999.98', $user->preference()->first()->budget_min);
    }

    public function test_presets_cover_every_centavo_between_the_first_and_last_bounds(): void
    {
        $this->customer();
        $response = $this->get(route('preferences.edit'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $options = (new \DOMXPath($document))->query('//select[@id="budget_range"]/option[@value!=""]');
        $lastMaximum = null;
        foreach ($options as $option) {
            [$min, $max] = array_map(fn ($value) => (int) round((float) $value * 100), explode('-', $option->getAttribute('value')));
            if ($lastMaximum === null) $this->assertSame(100000, $min);
            else $this->assertSame($lastMaximum + 1, $min);
            $this->assertGreaterThan($min, $max);
            $lastMaximum = $max;
        }
        $this->assertSame(50000000, $lastMaximum);
        $this->assertCount(11, $options);
    }

    public function test_centavo_gap_product_is_recommended_by_the_correct_preset(): void
    {
        $user = $this->customer();
        $this->postJson(route('preferences.update'), ['furniture_type' => 'Chair', 'budget_range' => '10000.01-20000'])->assertOk();
        $recommendation = app(RecommendationService::class)->generate($user, $user->preference()->first(), null, 'Chair');
        $this->assertSame([FurnitureProduct::first()->id], $recommendation->items()->pluck('furniture_product_id')->all());
    }

    public function test_saved_fractional_and_legacy_budgets_keep_their_exact_values_on_reload(): void
    {
        $user = $this->customer();
        foreach (['6000.50-8000.75', '10001-20000'] as $range) {
            [$min, $max] = explode('-', $range);
            $this->postJson(route('preferences.update'), ['furniture_type' => 'Chair', 'budget_range' => $range])->assertOk();
            $this->get(route('preferences.edit'))->assertOk()
                ->assertSee('data-saved-budget>PHP '.number_format((float) $min, 2).' – '.number_format((float) $max, 2).'</span>', false)
                ->assertSee('value="'.(float) $min.'-'.(float) $max.'" selected', false);
            $this->assertSame(number_format((float) $min, 2, '.', ''), $user->preference()->first()->budget_min);
        }
    }

    public function test_malformed_budget_old_input_does_not_break_the_recovery_page(): void
    {
        $this->customer();
        $this->from(route('preferences.edit'))->post(route('preferences.update'), [
            'furniture_type' => 'Chair', 'budget_min' => ['bad'], 'budget_max' => ['bad'],
        ])->assertRedirect(route('preferences.edit'))->assertSessionHasErrors(['budget_min', 'budget_max']);
        $this->get(route('preferences.edit'))->assertOk()->assertSee('Budget range');
    }
}
