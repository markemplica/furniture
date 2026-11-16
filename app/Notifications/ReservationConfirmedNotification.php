<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable, \App\Notifications\Concerns\RecoversReservationDelivery;

    public int $tries = 3;
    public int $timeout = 60;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public const SUBJECT = 'Your reservation is approved - Lance Furniture Shop';

    public function __construct(public Reservation $reservation, public ?int $notificationLogId = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function viaQueues(): array { return ['mail' => 'mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $shopPhone = trim((string) config('furnicraft.shop_phone'));
        $details = [
            ['label' => 'REFERENCE', 'value' => $this->reservation->reference],
            ['label' => 'FURNITURE', 'value' => $this->reservation->product->name],
            ['label' => 'SHOP', 'value' => $this->reservation->branch],
        ];
        if ($shopPhone !== '') {
            $details[] = ['label' => 'SHOP CONTACT NUMBER', 'value' => $shopPhone];
        }

        return (new MailMessage)
            ->subject(self::SUBJECT)
            ->view([
                'html' => 'emails.customer-notification',
                'text' => 'emails.customer-notification-text',
            ], [
                'eyebrow' => 'RESERVATION UPDATE',
                'heading' => 'Your reservation is approved',
                'intro' => 'Good news, '.$notifiable->name.'. Lance Furniture Shop reviewed and approved your reservation request.',
                'bodyText' => $shopPhone !== ''
                    ? 'You can visit Lance Furniture Shop to discuss your reservation, or contact us at '.$shopPhone.' for assistance.'
                    : 'You can visit Lance Furniture Shop to discuss your reservation. Please contact the shop to arrange your visit.',
                'codeLabel' => null,
                'code' => null,
                'expiry' => null,
                'details' => $details,
                'buttonLabel' => 'View reservation request',
                'buttonUrl' => rtrim((string) config('app.url'), '/').route('reservations.index', [], false),
                'note' => 'Please contact the shop before visiting to confirm physical availability, item readiness, and operating hours. No purchase or payment has been made through FurniCraft.',
            ]);
    }
}
