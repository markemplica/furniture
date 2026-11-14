<?php

namespace App\Console\Commands;

use App\Services\ReservationStatusNotifier;
use Illuminate\Console\Command;

class RecoverReservationNotifications extends Command
{
    protected $signature = 'notifications:recover';

    protected $description = 'Redispatch stranded reservation emails using durable notification logs';

    public function handle(ReservationStatusNotifier $notifier): int
    {
        $this->info('Checked '.$notifier->recover().' recoverable reservation notifications.');

        return self::SUCCESS;
    }
}
