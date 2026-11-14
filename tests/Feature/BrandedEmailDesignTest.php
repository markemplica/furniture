<?php

namespace Tests\Feature;

use App\Mail\FeedbackReceived;
use App\Models\FeedbackReport;
use App\Models\FurnitureProduct;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\AiUsageAlertNotification;
use App\Notifications\EmailVerificationCodeNotification;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\ReservationConfirmedNotification;
use App\Notifications\ReservationStatusNotification;
use App\Notifications\ReservationVisitReminderNotification;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BrandedEmailDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_notifications_include_the_logo_plain_text_and_their_original_details(): void
    {
        config(['mail.default' => 'array']);
        $customer = new User(['name' => 'Email Preview Customer', 'email' => 'preview@example.test']);
        $reservation = new Reservation(['reference' => 'FC-EMAIL-TEST', 'branch' => 'Lance Furniture Shop', 'preferred_visit_at' => now()->addDay()]);
        $reservation->setRelation('product', new FurnitureProduct(['name' => 'Natural Wood Sala Set']));
        $notifications = [
            new EmailVerificationCodeNotification('012345'),
            new PasswordResetCodeNotification('012345'),
            new ReservationConfirmedNotification($reservation),
            new ReservationVisitReminderNotification($reservation),
            new AiUsageAlertNotification('Daily allowance is nearly used.'),
        ];
        foreach (['pending', 'ready', 'completed', 'cancelled', 'declined', 'no_show'] as $status) {
            $notifications[] = new ReservationStatusNotification($reservation, $status);
        }

        foreach ($notifications as $notification) {
            app(MailChannel::class)->send($customer, $notification);
            $email = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
            $html = $email->getHtmlBody();
            $this->assertStringContainsString('alt="Lance Furniture Shop logo"', $html);
            $this->assertStringContainsString('src="cid:', $html);
            $this->assertStringContainsString('FURNICRAFT', $email->getTextBody());
            $this->assertSame($notification->toMail($customer)->subject, $email->getSubject());
            $this->assertCount(1, $email->getAttachments());
            $this->assertSame('inline', $email->getAttachments()[0]->getDisposition());
            if ($notification instanceof EmailVerificationCodeNotification || $notification instanceof PasswordResetCodeNotification) {
                $this->assertStringContainsString('012345', $html);
                $this->assertStringContainsString('15 minutes', $html);
            }
            if (property_exists($notification, 'reservation')) {
                $this->assertStringContainsString('FC-EMAIL-TEST', $html);
                $this->assertStringContainsString('/reservations', $html);
            }
        }
    }

    public function test_feedback_is_branded_and_keeps_safe_message_content_and_reply_address(): void
    {
        config(['mail.default' => 'array']);
        $report = FeedbackReport::create([
            'email' => 'customer@example.test', 'category' => 'bug',
            'subject' => 'Preview needs checking', 'message' => "First line\n<script>alert('unsafe')</script>",
        ]);
        Mail::to('admin@example.test')->send(new FeedbackReceived($report));
        $email = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertStringContainsString('New feedback received', $email->getHtmlBody());
        $this->assertStringContainsString('src="cid:', $email->getHtmlBody());
        $this->assertStringNotContainsString('<script>', $email->getHtmlBody());
        $this->assertStringContainsString('First line', $email->getTextBody());
        $this->assertSame('customer@example.test', $email->getReplyTo()[0]->getAddress());
    }
}
