<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\RoomImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SecurityAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_cannot_access_another_customers_private_resources(): void
    {
        $owner = User::factory()->create();
        $visitor = User::factory()->create();
        $preference = $owner->preference()->create(['budget_min' => 1000, 'budget_max' => 30000, 'furniture_type' => 'Table']);
        $room = RoomImage::create(['user_id' => $owner->id, 'image_path' => 'rooms/audit.png', 'analysis_status' => 'fallback']);
        $rec = Recommendation::create(['user_id' => $owner->id, 'customer_preference_id' => $preference->id, 'room_image_id' => $room->id, 'summary' => 'Private plan']);
        $product = FurnitureProduct::create(['name' => 'Audit Table', 'slug' => 'audit-table', 'category' => 'Dining Table', 'description' => 'Audit', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 20000, 'status' => 'active']);
        $reservation = Reservation::create(['user_id' => $owner->id, 'furniture_product_id' => $product->id, 'reference' => 'FC-AUDIT', 'branch' => config('furnicraft.branch'), 'status' => 'pending']);
        $visitor->preference()->create(['budget_min' => 1000, 'budget_max' => 30000, 'furniture_type' => 'Table']);
        $this->actingAs($visitor);
        foreach ([route('room-images.original', $room), route('room.analysis-status', $room), route('room.visualize', $room), route('recommendations.show', $rec)] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->delete(route('room.destroy', $room))->assertForbidden();
        $this->post(route('room.reanalyze', $room))->assertForbidden();
        $this->post(route('recommendations.store'), ['room_image_id' => $room->id])->assertNotFound();
        $this->patch(route('reservations.cancel', $reservation))->assertForbidden();
        $this->get(route('reservations.index'))->assertDontSee('FC-AUDIT');
        $this->assertModelExists($room);
        $this->assertSame('pending', $reservation->fresh()->status);
    }

    public function test_customer_role_cannot_be_escalated_through_profile(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'role' => 'admin', 'is_active' => true])->assertRedirect();
        $this->assertSame('customer', $user->fresh()->role);
        foreach (['admin.dashboard', 'admin.customers.index', 'admin.reports.index', 'admin.privacy-audit'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
    }

    public function test_registration_ignores_injected_admin_role(): void
    {
        Notification::fake();
        $this->post(route('register.store'), ['first_name' => 'Audit', 'last_name' => 'Customer', 'birthdate' => '2000-01-01', 'gender' => 'male', 'email' => 'audit@example.test', 'phone' => '09171234567', 'password' => 'AuditPassword123', 'password_confirmation' => 'AuditPassword123', 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()->toDateTimeString()])->assertRedirect();
        $user = User::where('email', 'audit@example.test')->firstOrFail();
        $this->assertSame('customer', $user->role);
        $this->assertFalse($user->is_active);
        $this->assertNull($user->email_verified_at);
    }
}
