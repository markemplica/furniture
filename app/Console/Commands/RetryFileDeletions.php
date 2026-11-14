<?php

namespace App\Console\Commands;

use App\Models\PendingFileDeletion;
use App\Services\FileDeletionRecovery;
use Illuminate\Console\Command;

class RetryFileDeletions extends Command
{
    protected $signature = 'room-images:retry-deletions';

    protected $description = 'Retry committed room-file deletion intents without scanning or deleting unrelated files';

    public function handle(FileDeletionRecovery $recovery): int
    {
        PendingFileDeletion::where(fn ($query) => $query->whereNull('retry_at')->orWhere('retry_at', '<=', now()))
            ->orderBy('id')->chunkById(100, function ($tasks) use ($recovery) {
                foreach ($tasks as $task) {
                    $recovery->attempt($task->id);
                }
            });
        $this->info('Pending deletion retries checked.');

        return self::SUCCESS;
    }
}
