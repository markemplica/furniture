<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;

class PasswordResetCodeNotification extends Notification implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 60;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(public string $code, public ?int $notificationLogId = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function viaQueues(): array { return ['mail' => 'mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your FurniCraft password reset code')
            ->view([
                'html' => 'emails.customer-notification',
                'text' => 'emails.customer-notification-text',
            ], [
                'eyebrow' => 'ACCOUNT SECURITY',
                'heading' => 'Reset your password',
                'intro' => 'Hello '.$notifiable->name.'. Enter this code on FurniCraft to set a new password.',
                'codeLabel' => 'PASSWORD RESET CODE',
                'code' => $this->code,
                'expiry' => 'Valid for 15 minutes. Use once only.',
                'details' => [],
                'buttonLabel' => null,
                'buttonUrl' => null,
                'note' => 'Did not request this? Ignore this email. Your password will stay the same.',
            ]);
    }
}
