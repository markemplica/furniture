<?php

namespace App\Notifications\Concerns;

use App\Models\NotificationLog;
use Illuminate\Queue\Middleware\WithoutOverlapping;

trait RecoversReservationDelivery
{
    public function middleware(object $notifiable, string $channel): array
    {
        return $this->notificationLogId
            ? [(new WithoutOverlapping('reservation-mail:'.$this->notificationLogId))->shared()->releaseAfter(10)->expireAfter(120)]
            : [];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! $this->notificationLogId || NotificationLog::whereKey($this->notificationLogId)
            ->where('user_id', $notifiable->id)->where('status', '!=', 'sent')->exists();
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->notificationLogId) {
            NotificationLog::whereKey($this->notificationLogId)->where('status', '!=', 'sent')
                ->update(['status' => 'failed', 'dispatch_after' => now()->addMinute()]);
        }
    }
}
