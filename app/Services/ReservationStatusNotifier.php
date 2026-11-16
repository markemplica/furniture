<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Notifications\ReservationConfirmedNotification;
use App\Notifications\ReservationStatusNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReservationStatusNotifier
{
    public function record(Reservation $reservation, string $status): ?NotificationLog
    {
        $type = 'reservation_'.$status;
        if (NotificationLog::where('type', $type)->where('payload->reservation', $reservation->reference)->exists()) {
            return null;
        }

        $reservation->loadMissing('user', 'product');
        $payload = [
            'reservation' => $reservation->reference,
            'product' => $reservation->product->name,
            'branch' => $reservation->branch,
        ];
        if ($status === 'confirmed') {
            $payload += [
                'confirmed_at' => $reservation->confirmed_at?->toIso8601String(),
                'next_step' => 'contact_branch_to_confirm_item_readiness',
            ];
        } else {
            $payload['status'] = $status;
        }

        return NotificationLog::create([
            'user_id' => $reservation->user_id,
            'type' => $type,
            'recipient' => $reservation->user->email,
            'subject' => $status === 'confirmed'
                ? ReservationConfirmedNotification::SUBJECT
                : ReservationStatusNotification::subjectFor($status),
            'status' => 'queued',
            'payload' => $payload,
        ]);
    }

    public function dispatch(Reservation $reservation, ?NotificationLog $log): void
    {
        if (! $log) {
            return;
        }

        try {
            DB::transaction(function () use ($reservation, $log) {
                $current = NotificationLog::whereKey($log->id)->lockForUpdate()->first();
                if (! $current || $current->status === 'sent' || $current->dispatch_attempts >= 8
                    || $current->dispatch_after?->isFuture()) {
                    return;
                }
                $reservation->loadMissing('user', 'product');
                if (! $reservation->user || ! $reservation->product) {
                    return;
                }
                $current->update(['status' => 'queued', 'dispatch_attempts' => $current->dispatch_attempts + 1, 'dispatch_after' => now()->addMinutes(15)]);
                $notification = $current->type === 'reservation_confirmed'
                    ? new ReservationConfirmedNotification($reservation, $current->id)
                    : new ReservationStatusNotification($reservation, substr($current->type, strlen('reservation_')), $current->id);
                // Database jobs and the lease commit together on the standard database connection.
                $reservation->user->notify($notification->beforeCommit());
            }, 3);
        } catch (Throwable $exception) {
            Log::error('Reservation notification dispatch needs recovery', ['notification_log_id' => $log->id, 'exception' => $exception::class]);
        }
    }

    public function recover(): int
    {
        $count = 0;
        NotificationLog::whereIn('type', ['reservation_confirmed', 'reservation_ready', 'reservation_completed', 'reservation_cancelled', 'reservation_declined', 'reservation_no_show'])
            ->where('status', '!=', 'sent')->where('dispatch_attempts', '<', 8)
            ->where(fn ($query) => $query->whereNull('dispatch_after')->orWhere('dispatch_after', '<=', now()))
            ->orderBy('id')->chunkById(100, function ($logs) use (&$count) {
                foreach ($logs as $log) {
                    $reservation = Reservation::where('reference', data_get($log->payload, 'reservation'))->where('user_id', $log->user_id)->first();
                    if (! $reservation) {
                        continue;
                    }
                    $this->dispatch($reservation, $log);
                    $count++;
                }
            });

        return $count;
    }
}
