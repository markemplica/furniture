<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ReportsController;
use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\RoomImage;
use App\Models\RoomPlacementEvaluation;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Tests\TestCase;

class AdminAggregationTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_and_admin_activity_rankings_match_the_previous_collection_calculation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $products = collect();
        for ($number = 0; $number < 10; $number++) {
            $product = $this->product("ranking-{$number}", ['status' => $number === 9 ? 'inactive' : 'active']);
            $products->push($product);
            for ($view = 0; $view < $number; $view++) {
                DB::table('furniture_view_events')->insert([
                    'user_id' => $customer->id, 'furniture_product_id' => $product->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($number % 2 === 0) {
                $customer->favorites()->attach($product);
            }
            if ($number % 3 === 0) {
                $other->favorites()->attach($product);
            }
            Reservation::create([
                'reference' => "FC-RANK-{$number}", 'user_id' => $customer->id,
                'furniture_product_id' => $product->id, 'branch' => 'Lingayen',
                'status' => $number % 2 === 0 ? 'pending' : 'confirmed',
            ]);
        }
        $score = fn ($product) => $product->views_count + ($product->favorites_count * 3) + ($product->reservations_count * 5);
        $expectedAdmin = FurnitureProduct::withCount(['views', 'favorites', 'reservations'])->get()
            ->sortByDesc($score)->take(5)->pluck('id')->all();
        $expectedPublic = FurnitureProduct::active()->withCount(['views', 'favorites', 'reservations'])->get()
            ->sortByDesc($score)->take(3)->pluck('id')->all();

        $this->assertSame($expectedPublic, $this->get(route('home'))->assertOk()->viewData('topProducts')->pluck('id')->all());
        $response = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->assertSame($expectedAdmin, $response->viewData('topProducts')->pluck('id')->all());
        $this->assertSame([
            'customers' => User::where('role', 'customer')->count(),
            'products' => FurnitureProduct::count(),
            'pending_reservations' => Reservation::where('status', 'pending')->count(),
            'recommendations' => Recommendation::count(),
        ], $response->viewData('stats'));
        $this->assertSame(10, $response->viewData('recentReservations')->total());
        $this->assertCount(8, $response->viewData('recentReservations'));
        $this->assertCount(2, $this->get(route('admin.dashboard', ['page' => 2]))->assertOk()->viewData('recentReservations'));
    }

    public function test_report_count_branch_totals_and_pagination_match_the_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        $product = $this->product('report-product');
        for ($number = 0; $number < 35; $number++) {
            Reservation::create([
                'reference' => "FC-PAGE-{$number}", 'user_id' => $customer->id,
                'furniture_product_id' => $product->id,
                'branch' => $number < 25 ? 'Lingayen' : 'Binmaley',
                'status' => $number % 2 === 0 ? 'confirmed' : 'pending',
            ]);
        }
        $filters = ['report' => 'reservations', 'q' => 'FC-PAGE'];
        $records = Reservation::where('reference', 'like', 'FC-PAGE%')->get();
        $expectedBranches = $records->groupBy('branch')->map(fn ($group) => [
            'reservations' => $group->count(),
            'confirmed' => $group->where('status', 'confirmed')->count(),
        ])->all();

        $response = $this->actingAs($admin)->get(route('admin.reports.index', $filters))->assertOk();
        $this->assertSame($records->count(), $response->viewData('recordCount'));
        $this->assertCount(30, $response->viewData('rows'));
        $this->assertSame($records->count(), $response->viewData('rows')->total());
        $actualBranches = $response->viewData('branchStats')->mapWithKeys(fn ($branch) => [
            $branch->branch => ['reservations' => (int) $branch->reservation_count, 'confirmed' => (int) $branch->confirmed_count],
        ])->all();
        $this->assertEqualsCanonicalizing($expectedBranches, $actualBranches);
        $this->assertCount(5, $this->get(route('admin.reports.index', $filters + ['page' => 2]))->assertOk()->viewData('rows'));
    }

    public function test_pdf_rows_are_read_in_batches_and_match_the_full_report_query(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $users = [];
        for ($number = 0; $number < 205; $number++) {
            $users[] = [
                'name' => "Report customer {$number}", 'email' => "report-{$number}@example.test",
                'password' => $admin->password, 'role' => 'customer', 'is_active' => true,
                'created_at' => now()->subSeconds($number), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($users, 40) as $batch) {
            DB::table('users')->insert($batch);
        }
        $controller = app(ReportsController::class);
        $query = (new \ReflectionMethod($controller, 'reportQuery'))->invoke($controller, 'customers', []);
        $expectedIds = (clone $query)->get()->pluck('id')->all();
        $rows = (new \ReflectionMethod($controller, 'pdfRows'))->invoke($controller, $query, ['id']);
        $this->assertInstanceOf(LazyCollection::class, $rows);

        $selects = [];
        DB::listen(function (QueryExecuted $event) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($event->sql)), 'select')) {
                $selects[] = $event->sql;
            }
        });
        $this->assertSame($expectedIds, array_column($rows->all(), 0));
        $this->assertCount(2, $selects);
    }

    public function test_room_evaluation_aggregates_match_the_previous_collection_math(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create();
        foreach ([true, false, null] as $number => $geminiUsed) {
            $room = RoomImage::create([
                'user_id' => $owner->id,
                'image_path' => "rooms/evaluation-{$number}.png",
                'analysis' => ['gemini_used' => $geminiUsed],
                'analyzed_at' => now(),
            ]);
            RoomPlacementEvaluation::create([
                'room_image_id' => $room->id, 'evaluator_id' => $admin->id,
                'floor_detection_correct' => $number !== 1,
                'zone_appropriate' => $number === 0,
                'clearance_respected' => $number === 2 ? null : $number === 0,
                'scale_plausible' => $number !== 2,
            ]);
            if ($number === 0) {
                RoomPlacementEvaluation::create([
                    'room_image_id' => $room->id, 'evaluator_id' => $reviewer->id,
                    'floor_detection_correct' => false, 'zone_appropriate' => true,
                    'clearance_respected' => null, 'scale_plausible' => true,
                ]);
            }
        }
        $all = RoomPlacementEvaluation::with('roomImage')->get();
        $expectedSources = [
            'gemini' => $all->filter(fn ($evaluation) => data_get($evaluation->roomImage?->analysis, 'gemini_used') === true)->count(),
            'fallback' => $all->filter(fn ($evaluation) => data_get($evaluation->roomImage?->analysis, 'gemini_used') !== true)->count(),
        ];
        $evaluation = $this->actingAs($admin)->get(route('admin.room-placement-evaluation.index'))->assertOk();
        $this->assertSame($expectedSources, $evaluation->viewData('sourceCounts'));
        foreach (['floor_detection_correct', 'zone_appropriate', 'clearance_respected', 'scale_plausible'] as $dimension) {
            $judged = $all->whereNotNull($dimension);
            $actual = $evaluation->viewData('metrics')[$dimension];
            $this->assertSame($judged->count(), $actual['count']);
            $this->assertSame($judged->where($dimension, true)->count(), $actual['correct']);
            $this->assertEqualsWithDelta($judged->where($dimension, true)->count() / $judged->count() * 100, $actual['rate'], 0.0001);
        }
    }

    private function product(string $slug, array $overrides = []): FurnitureProduct
    {
        return FurnitureProduct::create(array_merge([
            'name' => $slug, 'slug' => $slug, 'category' => 'Dining Table',
            'description' => 'A table.', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural',
            'style' => 'Modern', 'price' => 20000, 'width_cm' => 140, 'depth_cm' => 80,
            'height_cm' => 75, 'status' => 'active',
        ], $overrides));
    }
}
