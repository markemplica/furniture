<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;

class MarkNotificationSent
{
    public function handle(NotificationSent $event): void
    {
        $logId = $event->notification->notificationLogId ?? null;
        if (! is_int($logId) || ! $event->notifiable instanceof User) return;

        $log = NotificationLog::find($logId);
        if (! $log || $log->user_id !== $event->notifiable->id || $log->channel !== $event->channel) return;

        $payload = $log->payload ?? [];
        unset($payload['error_message'], $payload['error_type']);
        $log->update(['status' => 'sent', 'sent_at' => now(), 'payload' => $payload ?: null]);
    }
}
