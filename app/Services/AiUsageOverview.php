<?php

namespace App\Services;

use App\Models\AiUsageAlert;
use Illuminate\Support\Facades\DB;

class AiUsageOverview
{
    public function snapshot(): array
    {
        $today = DB::table('ai_usage_buckets')->where('bucket', 'day:'.now('UTC')->format('Y-m-d'))->first();
        $month = DB::table('ai_usage_buckets')->where('bucket', 'month:'.now('UTC')->format('Y-m'))->first();
        $beta = DB::table('ai_usage_buckets')->where('bucket', 'beta:total')->first();

        return [
            'enabled' => app(GeminiClient::class)->isConfigured(),
            'provider' => AiProvider::label(),
            'key_present' => filled(AiProvider::key()),
            'beta_expires_at' => config('services.gemini.beta_expires_at'),
            'beta_reserved_usd' => ($beta?->reserved_micro_usd ?? 0) / 1000000,
            'beta_budget_usd' => (int) config('services.gemini.beta_budget_micro_usd') / 1000000,
            'today' => $today?->requests ?? 0, 'month' => $month?->requests ?? 0,
            'reserved_usd' => ($month?->reserved_micro_usd ?? 0) / 1000000,
            'monthly_budget_usd' => (int) config('services.gemini.monthly_budget_micro_usd') / 1000000,
            'daily_limit' => (int) config('services.gemini.daily_request_limit'),
            'monthly_limit' => (int) config('services.gemini.monthly_request_limit'),
            'email_enabled' => (bool) config('services.gemini.alerts_email_enabled'),
            'alerts' => AiUsageAlert::latest()->limit(5)->get(),
        ];
    }
}
