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
use App\Notifications\ReservationStatusNotification;
use App\Services\RegistrationVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
        config([
            'queue.default' => 'database',
            'mail.default' => 'array',
            'furnicraft.shop_phone' => '09107664249',
        ]);
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer);
        \App\Jobs\AnalyzeRoomImage::dispatch(999999);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patch(route('admin.reservations.update', $reservation), ['status' => 'confirmed'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $log = NotificationLog::where('type', 'reservation_confirmed')->sole();
        $this->assertDatabaseHas('jobs', ['queue' => 'ai']);
        $this->assertDatabaseHas('jobs', ['queue' => 'mail']);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'mail', '--once' => true, '--tries' => 1])->assertSuccessful();
        $this->assertSame('sent', $log->fresh()->status);
        $this->assertDatabaseHas('jobs', ['queue' => 'ai']);
        $this->assertDatabaseMissing('jobs', ['queue' => 'mail']);

        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->sole()->getOriginalMessage();
        $this->assertSame($customer->email, $email->getTo()[0]->getAddress());
        $this->assertSame(ReservationConfirmedNotification::SUBJECT, $email->getSubject());
        foreach ([$email->getHtmlBody(), $email->getTextBody()] as $body) {
            $this->assertStringContainsString('Your reservation is approved', $body);
            $this->assertStringContainsString('You can visit Lance Furniture Shop', $body);
            $this->assertStringContainsString('or contact us at 09107664249', $body);
            $this->assertStringContainsString($reservation->reference, $body);
            $this->assertStringContainsString($reservation->product->name, $body);
        }

        $this->patch(route('admin.reservations.update', $reservation), ['status' => 'confirmed'])->assertRedirect();
        $this->assertDatabaseMissing('jobs', ['queue' => 'mail']);
        $this->assertSame(1, NotificationLog::where('type', 'reservation_confirmed')->count());
    }

    public function test_migration_permanently_deletes_only_receipt_email_jobs_and_logs(): void
    {
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer);
        $log = NotificationLog::create([
            'user_id' => $customer->id, 'type' => 'reservation_pending',
            'recipient' => $customer->email, 'subject' => 'Reservation request received',
            'status' => 'queued', 'payload' => ['reservation' => $reservation->reference],
        ]);

        $sentReceiptLog = $log->replicate();
        $sentReceiptLog->fill(['status' => 'sent', 'sent_at' => now()])->save();
        $approvalLog = $this->log($customer, $reservation);
        $receiptJobId = Queue::pushOn('mail', new SendQueuedNotifications(
            $customer, new ReservationStatusNotification($reservation, 'pending', $log->id), ['mail'],
        ));
        $approvalJobId = Queue::pushOn('mail', new SendQueuedNotifications(
            $customer, new ReservationConfirmedNotification($reservation, $approvalLog->id), ['mail'],
        ));
        $readyJobId = Queue::pushOn('mail', new SendQueuedNotifications(
            $customer, new ReservationStatusNotification($reservation, 'ready'), ['mail'],
        ));
        $aiJobId = Queue::pushOn('ai', new \App\Jobs\AnalyzeRoomImage(999999));

        $failedJobIds = [];
        foreach ([$receiptJobId, $approvalJobId] as $jobId) {
            $failedJobIds[] = DB::table('failed_jobs')->insertGetId([
                'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'mail',
                'payload' => DB::table('jobs')->where('id', $jobId)->value('payload'),
                'exception' => 'Test failure', 'failed_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_11_14_000001_remove_reservation_receipt_emails.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseMissing('jobs', ['id' => $receiptJobId]);
        foreach ([$approvalJobId, $readyJobId, $aiJobId] as $jobId) {
            $this->assertDatabaseHas('jobs', ['id' => $jobId]);
        }
        $this->assertDatabaseMissing('failed_jobs', ['id' => $failedJobIds[0]]);
        $this->assertDatabaseHas('failed_jobs', ['id' => $failedJobIds[1]]);
        $this->assertDatabaseMissing('notification_logs', ['id' => $log->id]);
        $this->assertDatabaseMissing('notification_logs', ['id' => $sentReceiptLog->id]);
        $this->assertDatabaseHas('notification_logs', ['id' => $approvalLog->id]);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'pending']);
    }

    public function test_approval_email_omits_contact_number_when_none_is_configured(): void
    {
        config(['furnicraft.shop_phone' => '']);
        $customer = User::factory()->create();
        $reservation = $this->reservation($customer);
        $mail = (new ReservationConfirmedNotification($reservation))->toMail($customer);

        $this->assertStringContainsString('You can visit Lance Furniture Shop', $mail->viewData['bodyText']);
        $this->assertStringNotContainsString('contact us at', $mail->viewData['bodyText']);
        $this->assertNotContains('SHOP CONTACT NUMBER', array_column($mail->viewData['details'], 'label'));
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
