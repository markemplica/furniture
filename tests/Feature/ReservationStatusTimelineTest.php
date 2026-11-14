<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationConfirmedNotification;
use App\Notifications\ReservationStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReservationStatusTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_request_sends_one_logged_pending_email(): void
    {
        Notification::fake();
        $customer = User::factory()->create();
        $product = $this->product();

        $payload = ['furniture_product_id' => $product->id, 'branch' => config('furnicraft.branch')];
        $this->actingAs($customer)->post(route('reservations.store'), $payload)
            ->assertRedirect(route('reservations.index'));
        $reservation = Reservation::sole();
        $log = NotificationLog::where('type', 'reservation_pending')->sole();
        $this->assertSame('queued', $log->status);
        $this->assertSame($reservation->reference, $log->payload['reservation']);
        Notification::assertSentTo($customer, ReservationStatusNotification::class,
            fn (ReservationStatusNotification $notification) => $notification->status === 'pending' && $notification->notificationLogId === $log->id);

        $this->post(route('reservations.store'), $payload)->assertSessionHasErrors('furniture_product_id');
        $this->assertSame(1, NotificationLog::where('type', 'reservation_pending')->count());
    }

    public function test_approval_readiness_and_completion_have_one_email_each_and_a_real_timeline(): void
    {
        Notification::fake();
        $customer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $reservation = $this->reservation($customer);

        $this->actingAs($admin)->patch(route('admin.reservations.update', $reservation), ['status' => 'confirmed'])->assertRedirect();
        $this->patch(route('admin.reservations.update', $reservation), ['status' => 'confirmed', 'item_ready' => 1])->assertRedirect();
        $this->patch(route('admin.reservations.update', $reservation), ['status' => 'confirmed', 'item_ready' => 1])->assertRedirect();
        $this->patch(route('admin.reservations.update', $reservation), ['status' => 'confirmed', 'outcome' => 'completed'])->assertRedirect();

        $this->assertSame(1, NotificationLog::where('type', 'reservation_confirmed')->count());
        $this->assertSame(1, NotificationLog::where('type', 'reservation_ready')->count());
        $this->assertSame(1, NotificationLog::where('type', 'reservation_completed')->count());
        Notification::assertSentTo($customer, ReservationConfirmedNotification::class, 1);
        Notification::assertSentTo($customer, ReservationStatusNotification::class,
            fn (ReservationStatusNotification $notification) => $notification->status === 'ready');
        Notification::assertSentTo($customer, ReservationStatusNotification::class,
            fn (ReservationStatusNotification $notification) => $notification->status === 'completed');
        $this->assertSame(['pending', 'confirmed', 'ready', 'completed'], $reservation->fresh()->statusTimeline()->pluck('status')->all());
        $this->actingAs($customer)->get(route('reservations.index'))
            ->assertOk()
            ->assertSee('Status timeline')
            ->assertSee('Status timeline for reservation '.$reservation->reference)
            ->assertSeeInOrder(['<strong>Pending</strong>', '<strong>Confirmed</strong>', '<strong>Ready</strong>', '<strong>Completed</strong>'], false);
    }

    public function test_cancellation_and_decline_are_logged_and_reapproval_does_not_duplicate_the_confirmed_email(): void
    {
        Notification::fake();
        $customer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $reservation = $this->reservation($customer);

        $this->actingAs($admin)->patch(route('admin.reservations.update', $reservation), ['status' => 'confirmed'])->assertRedirect();
        $this->patch(route('admin.reservations.update', $reservation), ['status' => 'declined', 'decline_reason' => 'Unavailable'])->assertRedirect();
        $this->patch(route('admin.reservations.update', $reservation), ['status' => 'confirmed'])->assertRedirect();
        $this->assertSame(1, NotificationLog::where('type', 'reservation_confirmed')->count());
        $this->assertSame(1, NotificationLog::where('type', 'reservation_declined')->count());
        $this->assertSame(['pending', 'confirmed', 'declined', 'confirmed'], $reservation->fresh()->statusTimeline()->pluck('status')->all());

        $this->actingAs($customer)->patch(route('reservations.cancel', $reservation), [])->assertRedirect();
        $this->patch(route('reservations.cancel', $reservation), [])->assertStatus(422);
        $this->assertSame(1, NotificationLog::where('type', 'reservation_cancelled')->count());
        Notification::assertSentTo($customer, ReservationStatusNotification::class,
            fn (ReservationStatusNotification $notification) => $notification->status === 'cancelled');
    }

    public function test_automatic_expiry_and_no_show_send_one_status_email_and_delivery_listener_updates_log(): void
    {
        Notification::fake();
        $customer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $expired = $this->reservation($customer, ['expires_at' => now()->subDay()]);
        $this->artisan('reservations:expire')->assertSuccessful();
        $this->artisan('reservations:expire')->assertSuccessful();
        $cancelledLog = NotificationLog::where('type', 'reservation_cancelled')->sole();
        $this->assertSame($expired->reference, $cancelledLog->payload['reservation']);
        $this->assertSame(['pending', 'cancelled'], $expired->fresh()->statusTimeline()->pluck('status')->all());

        $missed = $this->reservation($customer);
        $this->actingAs($admin)->patch(route('admin.reservations.update', $missed), ['status' => 'confirmed'])->assertRedirect();
        $this->patch(route('admin.reservations.update', $missed), ['status' => 'confirmed', 'outcome' => 'no_show'])->assertRedirect();
        $this->assertSame(1, NotificationLog::where('type', 'reservation_no_show')->count());
        $this->assertSame(['pending', 'confirmed', 'no_show'], $missed->fresh()->statusTimeline()->pluck('status')->all());

        $notification = new ReservationStatusNotification($expired, 'cancelled', $cancelledLog->id);
        Event::dispatch(new NotificationSent($customer, $notification, 'mail'));
        $this->assertSame('sent', $cancelledLog->fresh()->status);
        $this->assertNotNull($cancelledLog->fresh()->sent_at);
    }

    public function test_readiness_json_response_preserves_original_confirmation_and_sends_one_notice(): void
    {
        Notification::fake();
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer, ['status' => 'confirmed']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson(route('admin.reservations.update', $reservation), ['status' => 'confirmed', 'item_ready' => 1])
            ->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonStructure(['item_ready_confirmed_at', 'label']);
        $ready = $reservation->fresh()->item_ready_confirmed_at->toIso8601String();
        $this->travel(2)->minutes();
        $this->patchJson(route('admin.reservations.update', $reservation), ['status' => 'confirmed', 'item_ready' => 1])
            ->assertOk()->assertJsonPath('item_ready_confirmed_at', $ready);
        $this->assertSame(1, NotificationLog::where('type', 'reservation_ready')->count());
        $this->travelBack();
    }

    public function test_readiness_json_rejects_unapproved_reservations_and_customer_access(): void
    {
        Notification::fake();
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer);
        $this->actingAs($customer)->patchJson(route('admin.reservations.update', $reservation), ['status' => 'confirmed', 'item_ready' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson(route('admin.reservations.update', $reservation), ['status' => 'confirmed', 'item_ready' => 1])->assertUnprocessable();
        $this->assertNull($reservation->fresh()->item_ready_confirmed_at);
        $this->assertSame('pending', $reservation->fresh()->status);
        Notification::assertNothingSent();
    }

    private function reservation(User $customer, array $overrides = []): Reservation
    {
        $product = $this->product();

        return Reservation::create(array_merge([
            'reference' => 'FC-STATUS-'.$product->id,
            'user_id' => $customer->id,
            'furniture_product_id' => $product->id,
            'branch' => 'Lingayen',
            'status' => 'pending',
        ], $overrides));
    }

    private function product(): FurnitureProduct
    {
        return FurnitureProduct::create([
            'name' => 'Status Test Table', 'slug' => 'status-test-table-'.uniqid(),
            'category' => 'Dining Table', 'description' => 'A table.',
            'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern',
            'price' => 20000, 'status' => 'active',
        ]);
    }
}
