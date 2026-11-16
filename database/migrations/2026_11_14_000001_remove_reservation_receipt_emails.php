<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            foreach (['jobs', 'failed_jobs'] as $table) {
                DB::table($table)->select('id', 'payload')->orderBy('id')
                    ->chunkById(100, function ($jobs) use ($table) {
                        $ids = $jobs->filter(fn ($job) => $this->isReceiptEmail($job->payload))->pluck('id');
                        if ($ids->isNotEmpty()) {
                            DB::table($table)->whereIn('id', $ids)->delete();
                        }
                    });
            }

            DB::table('notification_logs')->where('type', 'reservation_pending')->delete();
        });
    }

    private function isReceiptEmail(string $payload): bool
    {
        $data = json_decode($payload, true);
        $command = $data['data']['command'] ?? null;
        if (($data['data']['commandName'] ?? null) !== \Illuminate\Notifications\SendQueuedNotifications::class
            || ! is_string($command)) {
            return false;
        }

        try {
            // Inspect serialized data without instantiating jobs, notifications, or models.
            $job = (array) @unserialize($command, ['allowed_classes' => false]);
            $notification = (array) ($job['notification'] ?? null);

            return ($notification['__PHP_Incomplete_Class_Name'] ?? null) === \App\Notifications\ReservationStatusNotification::class
                && ($notification['status'] ?? null) === 'pending';
        } catch (\Throwable) {
            return false;
        }
    }

    public function down(): void
    {
        // Permanently deleted receipt emails and their logs cannot be recreated.
    }
};
