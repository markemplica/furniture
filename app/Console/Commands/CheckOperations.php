<?php

namespace App\Console\Commands;

use App\Services\GeminiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckOperations extends Command
{
    protected $signature = 'system:operations';

    protected $description = 'Report queue, recovery, and AI usage counts without customer data; fail on actionable backlog';

    public function handle(): int
    {
        $mail = config('queue.default') === 'database' ? DB::connection(config('queue.connections.database.connection'))
            ->table(config('queue.connections.database.table', 'jobs'))->where('queue', 'mail')->where('created_at', '<', now()->subMinutes(2)->timestamp)->count() : 0;
        $notifications = DB::table('notification_logs')->where('type', 'like', 'reservation_%')->where('status', '!=', 'sent')
            ->where(fn ($query) => $query->where('dispatch_attempts', '>=', 8)->orWhere('created_at', '<', now()->subMinutes(15)))->count();
        $files = DB::table('pending_file_deletions')->where(fn ($query) => $query->where('attempts', '>=', 6)->orWhere('created_at', '<', now()->subHour()))->count();
        $this->line(json_encode([
            'mail_older_than_two_minutes' => $mail, 'reservation_notifications_needing_attention' => $notifications,
            'file_deletions_needing_attention' => $files,
            'ai_daily_request_limit' => config('services.gemini.daily_request_limit'),
            'ai_monthly_request_limit' => config('services.gemini.monthly_request_limit'),
            'paid_ai_enabled' => app(GeminiClient::class)->isConfigured(),
            'ai_monthly_reserved_budget_usd' => (int) config('services.gemini.monthly_budget_micro_usd') / 1000000,
            'api_alerts_last_24_hours' => DB::table('ai_usage_alerts')->where('created_at', '>=', now()->subDay())->count(),
            'ai_usage' => DB::table('ai_usage_buckets')->whereIn('bucket', ['day:'.now('UTC')->format('Y-m-d'), 'month:'.now('UTC')->format('Y-m')])->get(),
        ], JSON_THROW_ON_ERROR));

        return $mail || $notifications || $files ? self::FAILURE : self::SUCCESS;
    }
}
