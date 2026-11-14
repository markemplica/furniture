<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\Reservation;
use App\Models\User;
use App\Services\RecommendationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecommendationDataLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cold_start_uses_candidate_popularity_from_favorites_and_non_cancelled_reservations(): void
    {
        $customer = User::factory()->create();
        $popular = $this->product('popular-table');
        $lessPopular = $this->product('less-popular-table');
        $otherCategory = $this->product('popular-chair', ['category' => 'Dining Chair']);
        $fan = User::factory()->create();
        $fan->favorites()->attach([$popular->id, $lessPopular->id, $otherCategory->id]);
        Reservation::create([
            'reference' => 'FC-POPULAR-001', 'user_id' => $fan->id,
            'furniture_product_id' => $popular->id, 'branch' => 'Lingayen', 'status' => 'pending',
        ]);
        Reservation::create([
            'reference' => 'FC-CANCELLED-POPULAR-001', 'user_id' => $fan->id,
            'furniture_product_id' => $lessPopular->id, 'branch' => 'Lingayen', 'status' => 'cancelled',
        ]);
        $preference = $customer->preference()->create([
            'furniture_type' => 'Table', 'budget_min' => 0, 'budget_max' => 50000,
        ]);

        $recommendation = app(RecommendationService::class)->generate($customer, $preference, null, 'Table');
        $scores = $recommendation->items->pluck('collaborative_score', 'furniture_product_id');

        $this->assertTrue($recommendation->is_cold_start);
        $this->assertCount(2, $scores);
        $this->assertEquals(100, $scores[$popular->id]);
        $this->assertEquals(0, $scores[$lessPopular->id]);
        $this->assertEquals(59, $recommendation->items->firstWhere('furniture_product_id', $popular->id)->total_score);
    }

    public function test_popularity_fallback_applies_when_customer_history_has_no_similar_users(): void
    {
        $customer = User::factory()->create();
        $customer->favorites()->attach($this->product('customer-history', ['status' => 'inactive']));
        $popular = $this->product('unshared-popular');
        $unpopular = $this->product('unshared-unpopular');
        User::factory()->create()->favorites()->attach($popular);
        $preference = $customer->preference()->create([
            'furniture_type' => 'Table', 'budget_min' => 0, 'budget_max' => 50000,
        ]);

        $recommendation = app(RecommendationService::class)->generate($customer, $preference, null, 'Table');
        $scores = $recommendation->items->pluck('collaborative_score', 'furniture_product_id');

        $this->assertTrue($recommendation->is_cold_start);
        $this->assertEquals(100, $scores[$popular->id]);
        $this->assertEquals(0, $scores[$unpopular->id]);
    }

    public function test_old_cold_start_recommendation_gets_popularity_scores_when_opened(): void
    {
        $customer = User::factory()->create();
        $popular = $this->product('upgrade-popular');
        $unpopular = $this->product('upgrade-unpopular');
        User::factory()->create()->favorites()->attach($popular);
        $preference = $customer->preference()->create([
            'furniture_type' => 'Table', 'budget_min' => 0, 'budget_max' => 50000,
        ]);
        $recommendation = app(RecommendationService::class)->generate($customer, $preference, null, 'Table');
        $recommendation->update(['algorithm_version' => 'hybrid-v5-photo-confidence']);
        $recommendation->items()->update(['collaborative_score' => 0]);

        $this->actingAs($customer)->get(route('recommendations.show', $recommendation))->assertOk();

        $updated = $recommendation->fresh()->load('items');
        $this->assertSame(RecommendationService::ALGORITHM_VERSION, $updated->algorithm_version);
        $this->assertTrue($updated->is_cold_start);
        $this->assertEquals(100, $updated->items->firstWhere('furniture_product_id', $popular->id)->collaborative_score);
        $this->assertEquals(0, $updated->items->firstWhere('furniture_product_id', $unpopular->id)->collaborative_score);
    }

    public function test_similar_users_keep_collaborative_scores_even_when_unrelated_products_are_popular(): void
    {
        $customer = User::factory()->create();
        $shared = $this->product('shared-for-popularity');
        $suggested = $this->product('suggested-by-neighbor');
        $globallyPopular = $this->product('popular-with-strangers');
        $customer->favorites()->attach($shared);
        $neighbor = User::factory()->create();
        $neighbor->favorites()->attach([$shared->id, $suggested->id]);
        for ($number = 0; $number < 3; $number++) {
            User::factory()->create()->favorites()->attach($globallyPopular);
        }
        $preference = $customer->preference()->create([
            'furniture_type' => 'Table', 'budget_min' => 0, 'budget_max' => 50000,
        ]);

        $recommendation = app(RecommendationService::class)->generate($customer, $preference, null, 'Table');
        $scores = $recommendation->items->pluck('collaborative_score', 'furniture_product_id');

        $this->assertFalse($recommendation->is_cold_start);
        $this->assertEquals(100, $scores[$suggested->id]);
        $this->assertEquals(0, $scores[$globallyPopular->id]);
    }

    public function test_unrelated_users_do_not_change_scores_or_the_number_of_scoring_queries(): void
    {
        $customer = User::factory()->create();
        $similar = User::factory()->create();
        $cancelledOnly = User::factory()->create();
        $shared = $this->product('shared');
        $suggested = $this->product('suggested');
        $unrelated = $this->product('unrelated');
        $noiseProduct = $this->product('noise', ['status' => 'inactive']);
        $customer->favorites()->attach($shared);
        Reservation::create([
            'reference' => 'FC-SIMILAR-001',
            'user_id' => $similar->id,
            'furniture_product_id' => $shared->id,
            'branch' => 'Lingayen',
            'status' => 'pending',
        ]);
        $similar->favorites()->attach($suggested);
        Reservation::create([
            'reference' => 'FC-CANCELLED-001',
            'user_id' => $cancelledOnly->id,
            'furniture_product_id' => $shared->id,
            'branch' => 'Lingayen',
            'status' => 'cancelled',
        ]);
        $cancelledOnly->favorites()->attach($unrelated);
        $preference = $customer->preference()->create([
            'furniture_type' => 'Table', 'budget_min' => 0, 'budget_max' => 50000,
        ]);

        $selects = [];
        DB::listen(function (QueryExecuted $query) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $selects[] = strtolower($query->sql);
            }
        });
        $service = app(RecommendationService::class);
        $baseline = $service->generate($customer, $preference, null, 'Table');
        $baselineScores = $baseline->items->pluck('collaborative_score', 'furniture_product_id')->all();
        $baselineSelectCount = count($selects);
        $this->assertFalse($baseline->is_cold_start);
        $this->assertEquals(100, $baselineScores[$suggested->id]);
        $this->assertEquals(0, $baselineScores[$unrelated->id]);

        $userRows = [];
        for ($number = 0; $number < 300; $number++) {
            $userRows[] = [
                'name' => 'Unrelated customer',
                'email' => "unrelated-{$number}@example.test",
                'password' => $customer->password,
                'role' => 'customer',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($userRows, 50) as $batch) {
            DB::table('users')->insert($batch);
        }
        $favoriteRows = DB::table('users')->where('email', 'like', 'unrelated-%@example.test')->pluck('id')
            ->map(fn ($id) => [
                'user_id' => $id,
                'furniture_product_id' => $noiseProduct->id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();
        foreach (array_chunk($favoriteRows, 100) as $batch) {
            DB::table('favorites')->insert($batch);
        }

        $selects = [];
        $afterGrowth = $service->generate($customer, $preference, null, 'Table');
        $this->assertSame($baselineScores, $afterGrowth->items->pluck('collaborative_score', 'furniture_product_id')->all());
        $this->assertSame($baselineSelectCount, count($selects));
        $this->assertLessThanOrEqual(16, count($selects));
        $normalizedSelects = collect($selects)->map(fn (string $sql) => str_replace(['"', '`'], '', $sql));
        $this->assertTrue($normalizedSelects
            ->filter(fn (string $sql) => str_contains($sql, 'from favorites') || str_contains($sql, 'from reservations'))
            ->every(fn (string $sql) => str_contains($sql, ' where ')));
        $this->assertTrue($normalizedSelects->contains(fn (string $sql) => preg_match('/\bin\s*\(+\s*select\b/', $sql) === 1 && str_contains($sql, 'favorites')));
    }

    public function test_affinity_history_contains_only_the_most_recent_200_views_and_favorites(): void
    {
        $customer = User::factory()->create();
        $oldProduct = $this->product('old-history', ['style' => 'Traditional']);
        $recentProduct = $this->product('recent-history');
        $viewRows = [];
        for ($number = 0; $number < 210; $number++) {
            $viewRows[] = [
                'user_id' => $customer->id,
                'furniture_product_id' => $number < 11 ? $oldProduct->id : $recentProduct->id,
                'created_at' => $number < 11 ? now()->subDays(2) : now()->subDay(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($viewRows, 100) as $batch) {
            DB::table('furniture_view_events')->insert($batch);
        }
        $customer->favorites()->attach($recentProduct);

        $history = (new \ReflectionMethod(RecommendationService::class, 'affinityHistory'))
            ->invoke(app(RecommendationService::class), $customer);

        $this->assertCount(200, $history);
        $this->assertTrue($history->every(fn (array $entry) => $entry['product']->id === $recentProduct->id));
        $this->assertSame(1, $history->where('weight', 2)->count());
    }

    private function product(string $name, array $overrides = []): FurnitureProduct
    {
        return FurnitureProduct::create(array_merge([
            'name' => $name,
            'slug' => $name,
            'category' => 'Dining Table',
            'description' => 'A furniture product.',
            'wood_type' => 'Gmelina',
            'varnish_finish' => 'Natural',
            'style' => 'Modern',
            'price' => 20000,
            'width_cm' => 140,
            'depth_cm' => 80,
            'height_cm' => 75,
            'status' => 'active',
        ], $overrides));
    }
}
