<?php

namespace App\Services;

use App\Models\AiUsageAlert;
use App\Notifications\AiUsageAlertNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class AiUsageAlerts
{
    public function emit(string $code, string $message, array $context = []): void
    {
        try {
            // One alert per type per UTC hour, across processes. No customer content or keys.
            $created = AiUsageAlert::query()->insertOrIgnore([
                'dedupe_key' => $code.':'.now('UTC')->format('Y-m-d-H'),
                'code' => $code, 'message' => $message, 'context' => json_encode($context, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($created) {
                $this->safeLog('warning', 'API usage control alert', ['code' => $code] + $context);
            }
            $email = config('services.feedback.admin_email');
            if (config('services.gemini.alerts_email_enabled') && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Bound the alert channel too: at most one queued alert email per UTC hour.
                $emailSlot = DB::table('ai_usage_buckets')->insertOrIgnore([
                    'bucket' => 'alert-email:'.now('UTC')->format('Y-m-d-H'), 'requests' => 1, 'reserved_micro_usd' => 0,
                ]);
                if ($emailSlot) {
                    Notification::route('mail', $email)->notify(new AiUsageAlertNotification($message));
                }
            }
        } catch (Throwable $e) {
            // Alert delivery must never turn a blocked API call into an allowed one.
            $this->safeLog('error', 'API usage alert could not be recorded or queued', ['exception' => $e::class]);
        }
    }

    private function safeLog(string $level, string $message, array $context): void
    {
        try { Log::log($level, $message, $context); }
        catch (Throwable) {
            // A full/unwritable log volume must not suppress the database/email alert or recurse.
        }
    }
}
