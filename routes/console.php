<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('room-images:prune')->daily();
Schedule::command('feedback:prune')->daily();
Schedule::command('registration-codes:prune')->hourly()->withoutOverlapping();
Schedule::command('notifications:recover')->everyMinute()->withoutOverlapping();
Schedule::command('room-images:retry-deletions')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('reservations:expire')->hourly();
Schedule::command('reservations:remind')->hourly();


