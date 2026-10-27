<?php
namespace App\Console\Commands;

use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Notifications\ReservationVisitReminderNotification;
use Illuminate\Console\Command;
use Throwable;

class RemindReservationVisits extends Command
{

    protected $signature = 'reservations:remind';

    protected $description = 'Send one reminder 24 hours before a scheduled reservation visit';

    public function handle(): int
    {
        $count = 0;

        Reservation::with('user')
            ->where('status', 'confirmed')
            ->whereNotNull('preferred_visit_at')
            ->whereNull('reminder_sent_at')
            ->whereBetween('preferred_visit_at', [now()->addHours(23), now()->addHours(25)])
            ->orderBy('id')
            ->chunkById(100, function ($reservations) use (&$count): void {
                foreach ($reservations as $reservation) {
                    $sentAt = now();
                    $log = NotificationLog::create([
                        'user_id' => $reservation->user_id,
                        'type' => 'reservation_visit_reminder',
                        'recipient' => $reservation->user->email,
                        'subject' => 'Reminder: planned visit to Lance Furniture Shop',
                        'status' => 'queued',
                        'payload' => [
                            'reservation' => $reservation->reference,
                            'branch' => $reservation->branch,
                            'preferred_visit_at' => $reservation->preferred_visit_at->toIso8601String(),
                        ],
                    ]);

                    try {
                        $reservation->user->notify(new ReservationVisitReminderNotification($reservation));
                    } catch (Throwable $exception) {
                        $log->update([
                            'status' => 'failed',
                            'payload' => array_merge($log->payload ?? [], ['error_type' => class_basename($exception)]),
                        ]);

                        throw $exception;
                    }

                    $log->update(['status' => 'sent', 'sent_at' => $sentAt]);
                    $reservation->update(['reminder_sent_at' => $sentAt]);
                    $reservation->transactions()->create([
                        'action' => 'visit_reminder_sent',
                        'notes' => 'Automatic 24 hour visit reminder sent.',
                    ]);
                    $count++;
                }
            });

        $this->info("Sent {$count} visit reminders.");

        return self::SUCCESS;
    }
}
