<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoritesTest extends TestCase
{
    use RefreshDatabase;

    public function test_favorites_are_private_and_can_be_removed(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $saved = FurnitureProduct::create(['name' => 'Saved Chair', 'slug' => 'saved-chair', 'category' => 'Chair', 'description' => 'Wooden chair', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 1000, 'status' => 'active']);
        $private = FurnitureProduct::create(['name' => 'Other Customer Chair', 'slug' => 'other-chair', 'category' => 'Chair', 'description' => 'Wooden chair', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 1000, 'status' => 'active']);
        $customer->favorites()->attach($saved);
        $other->favorites()->attach($private);

        $this->get(route('favorites.index'))->assertRedirect(route('login', ['redirect' => route('favorites.index')]));
        $this->actingAs($customer)->get(route('favorites.index'))->assertOk()
            ->assertSee('Saved Chair')->assertDontSee('Other Customer Chair');
        $this->from(route('favorites.index'))->post(route('catalog.favorite', $saved))
            ->assertRedirect(route('favorites.index'));
        $this->get(route('favorites.index'))->assertOk()->assertSee('No favorites yet.');
        $this->assertDatabaseHas('favorites', ['user_id' => $other->id, 'furniture_product_id' => $private->id]);
    }

    public function test_inactive_product_cannot_be_added_to_favorites(): void
    {
        $customer = User::factory()->create();
        $inactive = FurnitureProduct::create(['name' => 'Unavailable Desk', 'slug' => 'unavailable-desk', 'category' => 'Desk', 'description' => 'Wooden desk', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 1000, 'status' => 'inactive']);

        $this->actingAs($customer)->postJson(route('catalog.favorite', $inactive))->assertNotFound();

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $customer->id,
            'furniture_product_id' => $inactive->id,
        ]);
    }

    public function test_previously_saved_inactive_product_has_no_save_button_and_can_be_removed(): void
    {
        $customer = User::factory()->create();
        $inactive = FurnitureProduct::create(['name' => 'Unavailable Desk', 'slug' => 'unavailable-desk', 'category' => 'Desk', 'description' => 'Wooden desk', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 1000, 'status' => 'inactive']);
        $customer->favorites()->attach($inactive);

        $this->actingAs($customer)->get(route('favorites.index'))
            ->assertOk()
            ->assertSee('This item is currently unavailable.')
            ->assertDontSee('Save to favorites')
            ->assertSee('Remove from favorites');

        $this->post(route('catalog.favorite', $inactive))->assertRedirect(route('favorites.index'));

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $customer->id,
            'furniture_product_id' => $inactive->id,
        ]);
    }
}


