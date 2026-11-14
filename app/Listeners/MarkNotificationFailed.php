<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Str;
use Throwable;

class MarkNotificationFailed
{
    public function handle(NotificationFailed $event): void
    {
        $logId = $event->notification->notificationLogId ?? null;
        if (! is_int($logId) || ! $event->notifiable instanceof User) return;

        $log = NotificationLog::find($logId);
        if (! $log || $log->user_id !== $event->notifiable->id || $log->channel !== $event->channel) return;

        $exception = $event->data['exception'] ?? null;
        $message = $exception instanceof Throwable ? $exception->getMessage() : (string) ($event->data['message'] ?? 'Notification delivery failed.');
        foreach (['app.key', 'mail.mailers.smtp.password', 'mail.mailers.smtp.url', 'services.postmark.token', 'services.resend.key', 'services.ses.key', 'services.ses.secret', 'services.gemini.key'] as $configKey) {
            $secret = config($configKey);
            if (is_string($secret) && strlen($secret) >= 4) $message = str_replace($secret, '[redacted]', $message);
        }
        $message = Str::limit(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message)), 500);
        $log->update([
            'status' => 'failed',
            'sent_at' => null,
            'payload' => array_merge($log->payload ?? [], [
                'error_message' => $message !== '' ? $message : 'Notification delivery failed.',
                'error_type' => $exception instanceof Throwable ? class_basename($exception) : 'DeliveryFailure',
            ]),
        ]);
    }
}
