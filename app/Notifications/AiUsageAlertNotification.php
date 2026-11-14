<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AiUsageAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public string $message)
    {
        $this->onQueue('mail');
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('FurniCraft API usage alert')
            ->view([
                'html' => 'emails.customer-notification',
                'text' => 'emails.customer-notification-text',
            ], [
                'eyebrow' => 'API USAGE ALERT',
                'heading' => 'Review your API usage',
                'intro' => $this->message,
                'code' => null,
                'details' => [],
                'buttonLabel' => 'Review API usage',
                'buttonUrl' => rtrim((string) config('app.url'), '/').route('admin.api-usage', [], false),
                'note' => 'Do not increase limits before checking the cause and your provider billing. Review API controls in the admin dashboard.',
            ]);
    }
}
