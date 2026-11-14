<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AiUsageBudget
{
    public static function accessWindowOpen(): bool
    {
        $expires = config('services.gemini.beta_expires_at');
        if ($expires === null || $expires === '') {
            return true;
        }
        try {
            return (int) config('services.gemini.beta_budget_micro_usd') > 0
                && now('UTC')->lt(\Carbon\CarbonImmutable::parse($expires, 'UTC'));
        } catch (\Throwable) {
            return false;
        }
    }

    public function reserve(bool $image = false, ?int $userId = null, string $operation = 'text_generation'): void
    {
        if (! config('services.gemini.enabled') || ! self::accessWindowOpen() || ($image && ! config('services.gemini.image_enabled'))) {
            throw new AiAggregateBudgetExceeded;
        }
        if (Cache::has('ai-provider-circuit:'.hash('sha256', (string) AiProvider::key()))) {
            throw new AiAggregateBudgetExceeded;
        }
        $utc = now('UTC');
        $actor = $userId === null ? 'system' : (string) $userId;
        $kind = $image ? 'image' : ($operation === 'chat_response' ? 'chat' : 'text');
        $reserve = max(1, (int) config('services.gemini.'.$kind.'_reserve_micro_usd'));
        $scopes = [
            'day:'.$utc->format('Y-m-d') => ['global_daily', config('services.gemini.daily_request_limit'), (int) config('services.gemini.daily_budget_micro_usd')],
            'month:'.$utc->format('Y-m') => ['global_monthly', config('services.gemini.monthly_request_limit'), (int) config('services.gemini.monthly_budget_micro_usd')],
            'minute:'.$utc->format('Y-m-d-H-i') => ['project_minute', config('services.gemini.project_per_minute'), null],
            'user:'.$actor.':day:'.$utc->format('Y-m-d') => ['user_daily', config('services.gemini.user_daily_limit'), null],
            'user:'.$actor.':month:'.$utc->format('Y-m') => ['user_monthly', config('services.gemini.user_monthly_limit'), null],
            'user:'.$actor.':minute:'.$utc->format('Y-m-d-H-i') => ['user_minute', config('services.gemini.user_per_minute'), null],
        ];
        if (filled(config('services.gemini.beta_expires_at'))) {
            // A lifetime bucket keeps the beta allowance from resetting at a month boundary.
            $scopes['beta:total'] = ['global_beta', PHP_INT_MAX, (int) config('services.gemini.beta_budget_micro_usd')];
        }
        ksort($scopes);
        $result = DB::transaction(function () use ($scopes, $reserve) {
            $rows = [];
            foreach ($scopes as $bucket => [$scope, $requestLimit, $moneyLimit]) {
                DB::table('ai_usage_buckets')->insertOrIgnore(['bucket' => $bucket, 'requests' => 0, 'reserved_micro_usd' => 0]);
                $rows[$bucket] = DB::table('ai_usage_buckets')->where('bucket', $bucket)->lockForUpdate()->first();
                if ($rows[$bucket]->requests >= max(0, (int) $requestLimit)
                    || ($moneyLimit !== null && $rows[$bucket]->reserved_micro_usd + $reserve > max(0, (int) $moneyLimit))) {
                    return ['blocked' => $scope, 'requests' => $rows[$bucket]->requests];
                }
            }
            $warnings = [];
            foreach ($scopes as $bucket => [$scope, $requestLimit, $moneyLimit]) {
                DB::table('ai_usage_buckets')->where('bucket', $bucket)->incrementEach(['requests' => 1, 'reserved_micro_usd' => $reserve]);
                if (str_starts_with($scope, 'global_') || $scope === 'project_minute') {
                    if (($rows[$bucket]->requests + 1) >= max(1, (int) $requestLimit) * .8
                        || ($moneyLimit !== null && ($rows[$bucket]->reserved_micro_usd + $reserve) >= $moneyLimit * .8)) {
                        $warnings[] = $scope;
                    }
                }
            }

            return ['warnings' => $warnings];
        }, 3);
        if (isset($result['blocked'])) {
            app(AiUsageAlerts::class)->emit('limit_'.$result['blocked'], 'An API usage or reserved budget limit stopped new AI requests. Local guidance remains available.', ['scope' => $result['blocked'], 'requests' => $result['requests']]);
            throw new AiAggregateBudgetExceeded;
        }
        foreach ($result['warnings'] as $scope) {
            app(AiUsageAlerts::class)->emit('near_'.$scope, 'API activity or reserved budget has reached 80% of a configured limit. Review usage before increasing limits.', ['scope' => $scope]);
        }
    }

    public function tripCircuit(int $seconds, string $reason): void
    {
        Cache::put('ai-provider-circuit:'.hash('sha256', (string) AiProvider::key()), true, max(30, min(3600, $seconds)));
        app(AiUsageAlerts::class)->emit('provider_'.$reason, 'AI requests were paused after provider errors. Local guidance remains available; inspect the API project before retrying.', ['reason' => $reason]);
    }
}
