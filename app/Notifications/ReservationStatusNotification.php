<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationStatusNotification extends Notification implements ShouldQueue
{
    use Queueable, \App\Notifications\Concerns\RecoversReservationDelivery;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public Reservation $reservation, public string $status, public ?int $notificationLogId = null) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public static function subjectFor(string $status): string
    {
        return match ($status) {
            'pending' => 'Reservation request received',
            'ready' => 'Reservation item marked ready by the shop',
            'completed' => 'Reservation visit recorded as completed',
            'cancelled' => 'Reservation request cancelled',
            'declined' => 'Reservation request declined',
            'no_show' => 'Reservation visit recorded as missed',
        };
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function viaQueues(): array { return ['mail' => 'mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        [$heading, $intro, $note] = match ($this->status) {
            'pending' => ['Request received', 'Lance Furniture Shop received your reservation request.', 'The shop will review your request. No payment has been collected and physical availability is not yet confirmed.'],
            'ready' => ['Shop marked your item ready', 'Lance Furniture Shop marked the item on your approved request as ready.', 'Contact the shop before visiting to confirm physical availability and operating hours. No payment has been collected through FurniCraft.'],
            'completed' => ['Visit completed', 'The shop recorded your reservation visit as completed or claimed.', 'Contact Lance Furniture Shop if this record does not match your visit.'],
            'cancelled' => ['Request cancelled', 'Your reservation request has been cancelled.', 'You can contact Lance Furniture Shop if you have questions about this cancellation.'],
            'declined' => ['Request declined', 'Lance Furniture Shop declined your reservation request.', 'View your reservation for any reason provided by the shop.'],
            'no_show' => ['Visit marked as missed', 'The shop recorded your planned reservation visit as a no-show.', 'Contact Lance Furniture Shop if this record does not match your visit.'],
        };

        return (new MailMessage)
            ->subject(self::subjectFor($this->status))
            ->view([
                'html' => 'emails.customer-notification',
                'text' => 'emails.customer-notification-text',
            ], [
                'eyebrow' => 'RESERVATION UPDATE',
                'heading' => $heading,
                'intro' => $intro,
                'codeLabel' => null,
                'code' => null,
                'expiry' => null,
                'details' => [
                    ['label' => 'REFERENCE', 'value' => $this->reservation->reference],
                    ['label' => 'FURNITURE', 'value' => $this->reservation->product->name],
                    ['label' => 'SHOP', 'value' => $this->reservation->branch],
                ],
                'buttonLabel' => 'View reservation request',
                'buttonUrl' => rtrim((string) config('app.url'), '/').route('reservations.index', [], false),
                'note' => $note,
            ]);
    }
}
