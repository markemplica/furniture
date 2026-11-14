<?php

namespace Tests\Feature;

use App\Models\CustomerPreference;
use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\RecommendationItem;
use App\Models\RoomImage;
use App\Models\User;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomDisplayLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_room_screens_use_friendly_labels_instead_of_database_ids(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $preference = CustomerPreference::create([
            'user_id' => $user->id,
            'furniture_type' => 'Sala Set',
            'budget_max' => 100000,
        ]);
        $room = RoomImage::create([
            'user_id' => $user->id,
            'image_path' => 'rooms/'.$user->id.'/display-label.png',
            'analysis' => ['gemini_used' => false],
            'analysis_status' => 'fallback',
        ]);
        $product = FurnitureProduct::create([
            'name' => 'Display Label Sala Set',
            'slug' => 'display-label-sala-set',
            'category' => 'Sala Set',
            'description' => 'A display-label test item.',
            'wood_type' => 'Wood',
            'varnish_finish' => 'Natural',
            'style' => 'Modern',
            'price' => 10000,
            'status' => 'active',
        ]);
        $recommendation = Recommendation::create([
            'user_id' => $user->id,
            'customer_preference_id' => $preference->id,
            'room_image_id' => $room->id,
            'furniture_type' => 'Sala Set',
            'summary' => 'Local recommendation summary.',
            'algorithm_version' => RecommendationService::ALGORITHM_VERSION,
        ]);
        RecommendationItem::create([
            'recommendation_id' => $recommendation->id,
            'furniture_product_id' => $product->id,
            'position' => 1,
            'explanation' => 'A test recommendation.',
        ]);

        $this->actingAs($user)
            ->get(route('preferences.edit', ['room' => $room->id]))
            ->assertOk()
            ->assertSee('Saved photo ·')
            ->assertDontSee('Room '.$room->id);

        $this->actingAs($user)
            ->get(route('room.visualize', [$room, 'product' => $product->id]))
            ->assertOk()
            ->assertSee('Selected room photo')
            ->assertDontSee('Room '.$room->id);

        $this->actingAs($user)
            ->get(route('recommendations.show', $recommendation))
            ->assertOk()
            ->assertSee('Selected room photo')
            ->assertDontSee('Room '.$room->id);
    }
}
