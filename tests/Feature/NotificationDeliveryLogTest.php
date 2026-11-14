<?php

namespace Tests\Feature;

use App\Listeners\MarkNotificationFailed;
use App\Listeners\MarkNotificationSent;
use App\Models\FurnitureProduct;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\ReservationConfirmedNotification;
use App\Services\RegistrationVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class NotificationDeliveryLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_reservation_notification_keeps_its_log_queued_and_visible_to_admin(): void
    {
        config(['queue.default' => 'database']);
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer);

        $this->actingAs($admin)->patch(route('admin.reservations.update', $reservation), ['status' => 'confirmed'])
            ->assertRedirect();

        $log = NotificationLog::where('type', 'reservation_confirmed')->sole();
        $this->assertSame('queued', $log->status);
        $this->assertNull($log->sent_at);
        Notification::assertSentTo($customer, ReservationConfirmedNotification::class,
            fn (ReservationConfirmedNotification $notification) => $notification->notificationLogId === $log->id);
        $this->get(route('admin.reservations.index'))->assertOk()->assertSee('Approval email: Queued');
    }

    public function test_queued_verification_and_reset_codes_carry_their_own_log_ids(): void
    {
        config(['queue.default' => 'database']);
        Notification::fake();
        $customer = User::factory()->create(['email_verified_at' => null, 'is_active' => false]);

        app(RegistrationVerificationService::class)->issue($customer, Request::create('/register', 'POST'));
        $verificationLog = NotificationLog::where('type', 'email_verification_code')->sole();
        $this->assertSame('queued', $verificationLog->status);
        Notification::assertSentTo($customer, EmailVerificationCodeNotification::class,
            fn (EmailVerificationCodeNotification $notification) => $notification->notificationLogId === $verificationLog->id);

        $customer->update(['email_verified_at' => now(), 'is_active' => true]);
        $this->post(route('password.email'), ['email' => $customer->email])->assertRedirect();
        $resetLog = NotificationLog::where('type', 'password_reset_code')->sole();
        $this->assertSame('queued', $resetLog->status);
        Notification::assertSentTo($customer, PasswordResetCodeNotification::class,
            fn (PasswordResetCodeNotification $notification) => $notification->notificationLogId === $resetLog->id);
    }

    public function test_event_listeners_mark_the_exact_log_failed_then_sent_and_clear_the_error(): void
    {
        Event::fake([NotificationSent::class, NotificationFailed::class]);
        Event::assertListening(NotificationSent::class, MarkNotificationSent::class);
        Event::assertListening(NotificationFailed::class, MarkNotificationFailed::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer);
        $otherLog = $this->log($customer, $reservation);
        $log = $this->log($customer, $reservation);
        $notification = new ReservationConfirmedNotification($reservation, $log->id);
        config(['mail.mailers.smtp.password' => 'test-only-secret']);

        $failed = new NotificationFailed($customer, $notification, 'mail', [
            'exception' => new RuntimeException('SMTP connection refused: test-only-secret'),
        ]);
        Event::dispatch($failed);
        Event::assertDispatched(NotificationFailed::class);
        $this->assertSame('queued', $log->fresh()->status);
        app(MarkNotificationFailed::class)->handle($failed);
        $this->assertSame('failed', $log->fresh()->status);
        $this->assertSame('SMTP connection refused: [redacted]', $log->fresh()->payload['error_message']);
        $this->assertNull($log->fresh()->sent_at);
        $this->assertSame('queued', $otherLog->fresh()->status);

        $this->actingAs($admin)->get(route('admin.reservations.index'))
            ->assertOk()->assertSee('Approval email: Failed');

        $sent = new NotificationSent($customer, $notification, 'mail');
        Event::dispatch($sent);
        Event::assertDispatched(NotificationSent::class);
        app(MarkNotificationSent::class)->handle($sent);
        $this->assertSame('sent', $log->fresh()->status);
        $this->assertNotNull($log->fresh()->sent_at);
        $this->assertArrayNotHasKey('error_message', $log->fresh()->payload);
        $this->assertSame('queued', $otherLog->fresh()->status);
        $this->get(route('admin.reservations.index'))->assertOk()->assertSee('Approval email: Sent');
    }

    public function test_real_event_dispatch_invokes_discovered_listener(): void
    {
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer);
        $log = $this->log($customer, $reservation);

        Event::dispatch(new NotificationSent($customer, new ReservationConfirmedNotification($reservation, $log->id), 'mail'));

        $this->assertSame('sent', $log->fresh()->status);
        $this->assertNotNull($log->fresh()->sent_at);
    }

    public function test_dispatch_failure_preserves_log_and_scheduler_recovers_it_once(): void
    {
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer);
        $notifier = app(\App\Services\ReservationStatusNotifier::class);
        $log = $notifier->record($reservation, 'confirmed');
        $dispatcher = \Mockery::mock(\Illuminate\Contracts\Notifications\Dispatcher::class);
        $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('Queue unavailable'));
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, $dispatcher);
        $notifier->dispatch($reservation, $log);
        $this->assertSame(0, $log->fresh()->dispatch_attempts);
        $this->assertNull($log->fresh()->dispatch_after);
        Notification::fake();
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, Notification::getFacadeRoot());
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->artisan('notifications:recover')->assertSuccessful();
        Notification::assertSentTo($customer, ReservationConfirmedNotification::class, 1);
        $this->assertSame(1, $log->fresh()->dispatch_attempts);
        $this->assertFalse((new ReservationConfirmedNotification($reservation, $log->id))->shouldSend(User::factory()->create(), 'mail'));
        $log->update(['status' => 'sent', 'sent_at' => now()]);
        $this->assertFalse((new ReservationConfirmedNotification($reservation, $log->id))->shouldSend($customer, 'mail'));
    }

    public function test_mail_worker_processes_mail_while_ai_work_remains_queued(): void
    {
        config(['queue.default' => 'database']);
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer);
        \App\Jobs\AnalyzeRoomImage::dispatch(999999);
        $log = $this->log($customer, $reservation);
        app(\App\Services\ReservationStatusNotifier::class)->dispatch($reservation, $log);
        $this->assertDatabaseHas('jobs', ['queue' => 'ai']);
        $this->assertDatabaseHas('jobs', ['queue' => 'mail']);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'mail', '--once' => true, '--tries' => 1])->assertSuccessful();
        $this->assertSame('sent', $log->fresh()->status);
        $this->assertDatabaseHas('jobs', ['queue' => 'ai']);
        $this->assertDatabaseMissing('jobs', ['queue' => 'mail']);
    }

    private function reservation(User $customer): Reservation
    {
        $product = FurnitureProduct::create([
            'name' => 'Notification Test Table', 'slug' => 'notification-test-table',
            'category' => 'Dining Table', 'description' => 'A table.',
            'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern',
            'price' => 20000, 'status' => 'active',
        ]);

        return Reservation::create([
            'reference' => 'FC-NOTIFICATION-1', 'user_id' => $customer->id,
            'furniture_product_id' => $product->id, 'branch' => 'Lingayen', 'status' => 'pending',
        ]);
    }

    private function log(User $customer, Reservation $reservation): NotificationLog
    {
        return NotificationLog::create([
            'user_id' => $customer->id, 'type' => 'reservation_confirmed',
            'recipient' => $customer->email, 'subject' => ReservationConfirmedNotification::SUBJECT,
            'status' => 'queued', 'payload' => ['reservation' => $reservation->reference],
        ]);
    }
}
