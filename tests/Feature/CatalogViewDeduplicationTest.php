<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\FurnitureViewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogViewDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_refreshes_count_once_without_updating_the_original_event_timestamp(): void
    {
        $user = User::factory()->create();
        $product = $this->product('first-chair');
        $this->actingAs($user)->withCookie(config('session.cookie'), str_repeat('a', 40));
        $this->get(route('catalog.show', $product))->assertOk();
        $event = FurnitureViewEvent::sole();
        $this->travel(5)->minutes();
        for ($i = 0; $i < 5; $i++) {
            $this->get(route('catalog.show', $product))->assertOk();
        }
        $this->assertDatabaseCount('furniture_view_events', 1);
        $this->assertSame($event->created_at->toDateTimeString(), $event->fresh()->created_at->toDateTimeString());
        $this->assertSame($event->updated_at->toDateTimeString(), $event->fresh()->updated_at->toDateTimeString());
        $this->assertSame(1, $product->loadCount('views')->views_count);
        $this->travelBack();
    }

    public function test_other_products_users_and_sessions_each_get_their_own_view(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $chair = $this->product('first-chair');
        $other = $this->product('second-chair');
        $this->actingAs($first)->withCookie(config('session.cookie'), str_repeat('a', 40));
        $this->get(route('catalog.show', $chair))->assertOk();
        $this->get(route('catalog.show', $other))->assertOk();
        $this->actingAs($second)->get(route('catalog.show', $chair))->assertOk();
        $this->actingAs($first)->withCookie(config('session.cookie'), str_repeat('b', 40));
        $this->get(route('catalog.show', $chair))->assertOk();
        $this->get(route('catalog.show', $chair))->assertOk();
        $this->assertDatabaseCount('furniture_view_events', 4);
        $this->assertSame(3, $chair->loadCount('views')->views_count);
        $this->assertSame(1, $other->loadCount('views')->views_count);
    }

    public function test_guests_and_unavailable_products_do_not_create_view_events(): void
    {
        $active = $this->product('active-chair');
        $inactive = $this->product('hidden-chair');
        $inactive->update(['status' => 'inactive']);
        $this->get(route('catalog.show', $active))->assertOk();
        $this->get(route('catalog.show', $inactive))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('catalog.show', $inactive))->assertNotFound();
        $this->assertDatabaseCount('furniture_view_events', 0);
    }

    public function test_existing_duplicate_history_is_preserved_and_not_extended(): void
    {
        $user = User::factory()->create();
        $product = $this->product('historic-chair');
        $attributes = ['user_id' => $user->id, 'furniture_product_id' => $product->id, 'session_key' => str_repeat('a', 40)];
        FurnitureViewEvent::create($attributes);
        FurnitureViewEvent::create($attributes);
        $this->actingAs($user)->withCookie(config('session.cookie'), $attributes['session_key']);
        $this->get(route('catalog.show', $product))->assertOk();
        $this->assertDatabaseCount('furniture_view_events', 2);
    }

    private function product(string $slug): FurnitureProduct
    {
        return FurnitureProduct::create([
            'name' => 'Test Chair', 'slug' => $slug, 'category' => 'Chair',
            'description' => 'A chair', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural',
            'style' => 'Modern', 'price' => 1000, 'status' => 'active',
        ]);
    }
}
