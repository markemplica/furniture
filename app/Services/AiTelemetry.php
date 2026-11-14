<?php

namespace App\Services;

use App\Models\AiServiceEvent;
use Throwable;

class AiTelemetry
{
    public function record(
        string $feature,
        string $operation,
        string $status,
        ?string $model = null,
        ?int $httpStatus = null,
        ?int $latencyMs = null,
        bool $fallbackUsed = false,
        ?string $failureCode = null,
        array $usage = [],
    ): void {
        AiServiceEvent::create([
            'feature' => $feature,
            'operation' => $operation,
            'status' => $status,
            'model' => $model,
            'http_status' => $httpStatus,
            'latency_ms' => $latencyMs === null ? null : min(4_294_967_295, max(0, $latencyMs)),
            'fallback_used' => $fallbackUsed,
            'failure_code' => $failureCode ? substr($failureCode, 0, 100) : null,
            'input_tokens' => isset($usage['promptTokenCount']) ? min(4_294_967_295, max(0, (int) $usage['promptTokenCount'])) : null,
            'output_tokens' => isset($usage['candidatesTokenCount']) ? min(4_294_967_295, max(0, (int) $usage['candidatesTokenCount'])) : null,
            'thinking_tokens' => isset($usage['thoughtsTokenCount']) ? min(4_294_967_295, max(0, (int) $usage['thoughtsTokenCount'])) : null,
        ]);
        if ($status === 'failure' && $model !== null && AiServiceEvent::whereIn('feature', ['text', 'image'])
            ->whereNotNull('model')->where('status', 'failure')->where('created_at', '>=', now()->subMinutes(5))->count() >= 5) {
            app(AiUsageBudget::class)->tripCircuit(120, 'failure_burst');
        }
    }

    public function statusCode(Throwable $exception): ?int
    {
        if (method_exists($exception, 'response') && $exception->response()) {
            return $exception->response()->status();
        }
        if (property_exists($exception, 'response') && $exception->response) {
            return $exception->response->status();
        }

        return null;
    }

    public function failureCode(Throwable $exception): string
    {
        return class_basename($exception);
    }
}
