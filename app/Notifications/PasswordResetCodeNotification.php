<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;

class PasswordResetCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 60;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

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
                'intro' => 'Hello '.$notifiable->name.'. Use the secure code below on FurniCraft to choose a new password.',
                'codeLabel' => 'YOUR PASSWORD RESET CODE',
                'code' => $this->code,
                'expiry' => 'This code expires in 15 minutes and can only be used once.',
                'details' => [],
                'buttonLabel' => null,
                'buttonUrl' => null,
                'note' => 'If you did not request a password reset, ignore this email. Your password will not change unless you submit the code and a new password.',
            ]);
    }
}
