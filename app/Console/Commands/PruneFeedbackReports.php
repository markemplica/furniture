<?php

namespace App\Console\Commands;

use App\Models\FeedbackReport;
use Illuminate\Console\Command;

class PruneFeedbackReports extends Command
{
    protected $signature = 'feedback:prune';

    protected $description = 'Delete feedback reports older than 12 months';

    public function handle(): int
    {
        $deleted = FeedbackReport::where('created_at', '<', now()->subMonthsNoOverflow(12))->delete();

        $this->info("Deleted {$deleted} feedback reports older than 12 months.");

        return self::SUCCESS;
    }
}
