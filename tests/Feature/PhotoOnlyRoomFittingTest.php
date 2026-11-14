<?php

namespace Tests\Feature;

use App\Models\CustomerPreference;
use App\Models\FurnitureProduct;
use App\Models\RoomImage;
use App\Models\User;
use App\Services\FurniturePlacementService;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class PhotoOnlyRoomFittingTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_fit_weight_follows_dimension_confidence_and_optional_override(): void
    {
        $service = app(RecommendationService::class);
        $product = new FurnitureProduct([
            'price' => 500,
            'width_cm' => 200,
            'depth_cm' => 200,
        ]);
        $preference = new CustomerPreference(['budget_min' => 0, 'budget_max' => 1000]);
        $contextMethod = new ReflectionMethod(RecommendationService::class, 'roomFitContext');
        $scoreMethod = new ReflectionMethod(RecommendationService::class, 'contentScore');

        foreach (['high' => 78.75, 'medium' => 70.0, 'low' => 52.5, 'unavailable' => 52.5] as $confidence => $expected) {
            $room = new RoomImage([
                'analysis_status' => 'completed',
                'analysis' => [
                    'estimated_width_m' => 4,
                    'estimated_depth_m' => 4,
                    'dimension_confidence' => $confidence,
                    'gemini_used' => true,
                ],
            ]);
            $context = $contextMethod->invoke($service, $preference, $room);
            $this->assertSame($expected, $scoreMethod->invoke($service, $product, $preference, new Collection, $context));
        }

        $preference->room_width_m = 4;
        $preference->room_depth_m = 4;
        $manualContext = $contextMethod->invoke($service, $preference, $room);
        $this->assertSame(1.0, $manualContext['weight']);
        $this->assertSame(87.5, $scoreMethod->invoke($service, $product, $preference, new Collection, $manualContext));

        $preference->room_width_m = null;
        $preference->room_depth_m = null;
        $room->analysis_status = 'fallback';
        $this->assertSame(0.0, $contextMethod->invoke($service, $preference, $room)['weight']);
    }

    public function test_ready_room_analysis_status_includes_analysis_for_automatic_preview_refresh(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $room = RoomImage::create([
            'user_id' => $user->id,
            'image_path' => 'rooms/'.$user->id.'/analysis.png',
            'analysis_status' => 'completed',
            'analysis' => ['gemini_used' => true, 'candidate_zones' => [['reason' => 'Clear area beside the wall.']]],
        ]);

        $this->actingAs($user)
            ->getJson(route('room.analysis-status', $room))
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('analysis.candidate_zones.0.reason', 'Clear area beside the wall.');
    }

    public function test_visual_zone_width_does_not_assume_an_average_room_width(): void
    {
        $placementService = app(FurniturePlacementService::class);
        $analysis = ['candidate_zones' => [[
            'surface' => 'wall-floor',
            'anchor_x_percent' => 60,
            'anchor_y_percent' => 88,
            'max_width_percent' => 60,
            'suitable_types' => ['Sala Set'],
            'clearance_score' => 90,
            'occlusion_score' => 5,
            'confidence' => 'high',
        ]]];
        $small = new FurnitureProduct(['category' => 'Sala Set', 'width_cm' => 120, 'depth_cm' => 70]);
        $large = new FurnitureProduct(['category' => 'Sala Set', 'width_cm' => 240, 'depth_cm' => 90]);

        $smallFit = $placementService->forProduct($analysis, $small);
        $largeFit = $placementService->forProduct($analysis, $large);
        $measuredFit = $placementService->forProduct($analysis, $large, 5.0);

        $this->assertSame(60.0, $smallFit['max_width_percent']);
        $this->assertSame($smallFit['max_width_percent'], $largeFit['max_width_percent']);
        $this->assertNotSame($largeFit['max_width_percent'], $measuredFit['max_width_percent']);
    }

    public function test_photo_visual_width_uses_clear_zone_instead_of_estimated_room_metres(): void
    {
        $analysis = [
            'gemini_used' => true,
            'estimated_width_m' => 6.5,
            'dimension_confidence' => 'medium',
            'floor_polygon' => [[0, 0], [100, 0], [100, 100], [0, 100]],
            'candidate_zones' => [[
                'surface' => 'floor',
                'anchor_x_percent' => 65,
                'anchor_y_percent' => 80,
                'max_width_percent' => 45,
                'suitable_types' => ['Sala Set'],
                'clearance_score' => 90,
                'occlusion_score' => 5,
                'confidence' => 'high',
            ]],
        ];
        $product = new FurnitureProduct(['category' => 'Sala Set', 'width_cm' => 225, 'depth_cm' => 90]);

        $placement = app(FurniturePlacementService::class)->forProduct($analysis, $product);

        $this->assertSame(45.0, $placement['max_width_percent']);
        $this->assertSame('medium', $placement['confidence']);
        $this->assertSame(72.0, $placement['confidence_score']);
        $this->assertSame('measurements-missing', $placement['measurement_status']);
        $this->assertSame('detected-zone-only', $placement['fit_basis']);
    }

    public function test_placement_checks_the_full_footprint_against_floor_and_room_geometry(): void
    {
        $service = app(FurniturePlacementService::class);
        $product = new FurnitureProduct(['category' => 'Sala Set', 'width_cm' => 220, 'depth_cm' => 90]);
        $zone = [
            'surface' => 'floor', 'anchor_x_percent' => 53, 'anchor_y_percent' => 80, 'max_width_percent' => 10,
            'suitable_types' => ['Sala Set'], 'clearance_score' => 90, 'occlusion_score' => 5, 'confidence' => 'high',
        ];
        $analysis = [
            'floor_polygon' => [[0, 0], [100, 0], [100, 100], [0, 100]],
            'candidate_zones' => [$zone],
            // This triangle's bounding box touches the furniture rectangle, but its polygon does not.
            'obstacles' => [['label' => 'diagonal obstacle', 'polygon' => [[35, 60], [50, 60], [35, 80]]]],
        ];

        $clear = $service->forProduct($analysis, $product);
        $this->assertSame('candidate-zone', $clear['source']);

        $zone['anchor_x_percent'] = 43;
        $analysis['candidate_zones'] = [$zone];
        $overlapping = $service->forProduct($analysis, $product);
        $this->assertNotSame('candidate-zone', $overlapping['source']);

        $zone['anchor_x_percent'] = 45;
        $analysis['candidate_zones'] = [$zone];
        $analysis['obstacles'] = [];
        $analysis['vision_geometry'] = ['blocked_zones' => [[
            'polygon' => [[0.4, 0.7], [0.5, 0.7], [0.5, 0.8], [0.4, 0.8]],
        ]]];
        $blocked = $service->forProduct($analysis, $product);
        $this->assertNotSame('candidate-zone', $blocked['source']);

        $analysis['vision_geometry'] = [];
        $analysis['existing_furniture'] = [['label' => 'existing table', 'bbox' => [0.4, 0.7, 0.5, 0.8]]];
        $existingFurniture = $service->forProduct($analysis, $product);
        $this->assertNotSame('candidate-zone', $existingFurniture['source']);
    }

    public function test_visual_zone_uses_smaller_safe_width_when_full_width_would_overlap_an_obstacle(): void
    {
        $placement = app(FurniturePlacementService::class)->forProduct([
            'floor_polygon' => [[0, 0], [100, 0], [100, 100], [0, 100]],
            'candidate_zones' => [[
                'surface' => 'floor', 'anchor_x_percent' => 50, 'anchor_y_percent' => 80,
                'max_width_percent' => 40, 'suitable_types' => ['Sala Set'],
                'clearance_score' => 90, 'occlusion_score' => 5, 'confidence' => 'high',
            ]],
            'obstacles' => [['label' => 'occupied right edge', 'polygon' => [[69, 50], [73, 50], [73, 85], [69, 85]]]],
        ], new FurnitureProduct(['category' => 'Sala Set', 'width_cm' => 225, 'depth_cm' => 90]));

        $this->assertSame('candidate-zone', $placement['source']);
        $this->assertSame(36.0, $placement['max_width_percent']);
    }

    public function test_selected_anchor_inside_floor_is_rejected_when_furniture_footprint_crosses_the_edge(): void
    {
        $service = app(FurniturePlacementService::class);
        $product = new FurnitureProduct(['category' => 'Sala Set', 'width_cm' => 220, 'depth_cm' => 90]);
        $placement = $service->forProduct([
            'floor_polygon' => [[20, 20], [80, 20], [80, 90], [20, 90]],
            'candidate_zones' => [[
                'surface' => 'floor', 'anchor_x_percent' => 78, 'anchor_y_percent' => 82, 'max_width_percent' => 10,
                'suitable_types' => ['Sala Set'], 'clearance_score' => 90, 'occlusion_score' => 5, 'confidence' => 'high',
            ]],
        ], $product);

        $this->assertNotSame('candidate-zone', $placement['source']);
    }

    public function test_normalized_vision_floor_is_used_when_legacy_floor_polygon_is_missing(): void
    {
        $service = app(FurniturePlacementService::class);
        $placement = $service->forProduct([
            'vision_geometry' => [
                'floor_polygon' => [[0.4, 0.4], [0.6, 0.4], [0.82, 0.95], [0.18, 0.95]],
            ],
            'candidate_zones' => [[
                'surface' => 'floor', 'anchor_x_percent' => 92, 'anchor_y_percent' => 88, 'max_width_percent' => 55,
                'suitable_types' => ['Sala Set'], 'clearance_score' => 90, 'occlusion_score' => 5, 'confidence' => 'high',
            ]],
        ], new FurnitureProduct(['category' => 'Sala Set', 'width_cm' => 220, 'depth_cm' => 90]));

        $this->assertSame('image-floor-fallback', $placement['source']);
        $this->assertGreaterThanOrEqual(18, $placement['x_percent']);
        $this->assertLessThanOrEqual(82, $placement['x_percent']);
    }
}
