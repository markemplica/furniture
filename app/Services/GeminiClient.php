<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiClient
{
    private int $providerRequests = 0;
    private ?int $requestCeiling = null;

    public function __construct(private AiTelemetry $telemetry) {}

    public function isConfigured(): bool
    {
        return filled(config('services.gemini.key'));
    }
    public function limitProviderRequests(int $remaining): void
    {
        $this->requestCeiling = $this->providerRequests + max(0, $remaining);
    }

    public function clearProviderRequestLimit(): void
    {
        $this->requestCeiling = null;
    }

    private function countProviderRequest(): void
    {
        if ($this->requestCeiling !== null && $this->providerRequests >= $this->requestCeiling) {
            throw new AiRequestBudgetExceeded;
        }
        $this->providerRequests++;
    }

    public function providerCallCount(): int
    {
        return $this->providerRequests;
    }

    public function generateImage(array $parts, int $timeout = 120, int $retryAttempts = 1, int $modelLimit = 2): array
    {
        $models = collect([
            config('services.gemini.image_model', 'gemini-3.1-flash-image'),
            config('services.gemini.image_fallback_model', 'gemini-2.5-flash-image'),
        ])->filter()->unique()->take(max(1, min(2, $modelLimit)));
        $lastException = null;

        foreach ($models as $index => $model) {
            $started = hrtime(true);
            try {
                $response = Http::withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                    ->timeout($timeout)
                    ->retry(max(0, min(1, $retryAttempts)), 500)
                    ->beforeSending(fn () => $this->countProviderRequest())
                    ->post("https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent", [
                        'contents' => [['role' => 'user', 'parts' => $parts]],
                        'generationConfig' => ['responseModalities' => ['IMAGE']],
                    ]);
                $httpStatus = $response->status();
                $response->throw();
                $response = $response->json();

                $imagePart = collect(data_get($response, 'candidates.0.content.parts', []))
                    ->first(fn ($part) => filled(data_get($part, 'inlineData.data')) || filled(data_get($part, 'inline_data.data')));
                $encoded = data_get($imagePart, 'inlineData.data') ?: data_get($imagePart, 'inline_data.data');
                $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;

                if (! $bytes) {
                    throw new \RuntimeException('Gemini returned no generated room image.');
                }

                $this->telemetry->record('image', 'furnished_preview', 'success', $model, $httpStatus, (int) ((hrtime(true) - $started) / 1_000_000), $index > 0);

                return [
                    'bytes' => $bytes,
                    'mime_type' => data_get($imagePart, 'inlineData.mimeType') ?: data_get($imagePart, 'inline_data.mime_type') ?: 'image/png',
                    'used' => true,
                ];
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->telemetry->record('image', 'furnished_preview', 'failure', $model, $this->telemetry->statusCode($e), (int) ((hrtime(true) - $started) / 1_000_000), failureCode: $this->telemetry->failureCode($e));
                Log::warning('Gemini image model attempt failed', array_merge(['model' => $model], $this->errorContext($e)));
            }
        }

        throw $lastException ?? new \RuntimeException('No Gemini image model is configured.');
    }

    public function generate(string $prompt, string $fallback, string $operation, int $timeout = 30, int $retryAttempts = 2, int $modelLimit = 2, ?string $systemInstruction = null): array
    {
        if (! $this->isConfigured()) {
            $this->telemetry->record('text', $operation, 'fallback', fallbackUsed: true, failureCode: 'not_configured');
            return ['text' => $fallback, 'used' => false, 'source' => 'local-fallback'];
        }

        try {
            return ['text' => $this->request([['text' => $prompt]], operation: $operation, timeout: $timeout, retryAttempts: $retryAttempts, modelLimit: $modelLimit, systemInstruction: $systemInstruction), 'used' => true, 'source' => 'gemini'];
        } catch (\Throwable $e) {
            $this->telemetry->record('text', $operation, 'fallback', fallbackUsed: true, failureCode: $this->telemetry->failureCode($e));
            Log::warning('Gemini request failed', $this->errorContext($e));

            return ['text' => $fallback, 'used' => false, 'source' => 'local-fallback'];
        }
    }

    public function generateText(array $parts, bool $json = false, ?array $schema = null, string $operation = 'text_generation', int $timeout = 30, int $retryAttempts = 2, int $modelLimit = 2, ?string $systemInstruction = null): string
    {
        return $this->request($parts, $json, $schema, $operation, $timeout, $retryAttempts, $modelLimit, $systemInstruction);
    }

    private function request(array $parts, bool $json = false, ?array $schema = null, string $operation = 'text_generation', int $timeout = 30, int $retryAttempts = 2, int $modelLimit = 2, ?string $systemInstruction = null): string
    {
        $models = collect([
            config('services.gemini.model'),
            config('services.gemini.fallback_model'),
        ])->filter()->unique()->take(max(1, min(2, $modelLimit)));
        $lastException = null;

        foreach ($models as $index => $model) {
            $started = hrtime(true);
            try {
                $payload = [
                    'contents' => [['parts' => $parts]],
                    'generationConfig' => $json
                        ? array_filter([
                            'responseMimeType' => 'application/json',
                            'responseJsonSchema' => $schema,
                            'temperature' => 0,
                        ])
                        : ['temperature' => 0.25],
                ];
                if ($systemInstruction !== null) {
                    $payload['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
                }
                $response = Http::timeout($timeout)->retry($retryAttempts, 250)
                    ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                    ->beforeSending(fn () => $this->countProviderRequest())->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", $payload);
                // Some models reject complex structured-output schemas. Retry JSON mode
                // with the same shape in the prompt; callers still validate the result.
                if ($response->status() === 400 && $json && $schema) {
                    $jsonParts = $parts;
                    $jsonParts[] = ['text' => 'Return JSON only matching this schema: '.json_encode($schema)];
                    $fallbackPayload = [
                        'contents' => [['parts' => $jsonParts]],
                        'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0],
                    ];
                    if ($systemInstruction !== null) {
                        $fallbackPayload['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
                    }
                    $response = Http::timeout($timeout)
                        ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                    ->beforeSending(fn () => $this->countProviderRequest())
                        ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", $fallbackPayload);
                }
                $httpStatus = $response->status();
                $response->throw();
                $response = $response->json();
                $text = trim(data_get($response, 'candidates.0.content.parts.0.text', ''));
                if ($text === '') throw new \RuntimeException('Gemini returned an empty response.');
                $this->telemetry->record('text', $operation, 'success', $model, $httpStatus, (int) ((hrtime(true) - $started) / 1_000_000), $index > 0);

                return $text;
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->telemetry->record('text', $operation, 'failure', $model, $this->telemetry->statusCode($e), (int) ((hrtime(true) - $started) / 1_000_000), failureCode: $this->telemetry->failureCode($e));
                Log::warning('Gemini model attempt failed', array_merge(['model' => $model], $this->errorContext($e)));
            }
        }

        throw $lastException ?? new \RuntimeException('No Gemini model is configured.');
    }

    public function errorContext(\Throwable $exception): array
    {
        $context = ['exception' => $exception::class];

        if (property_exists($exception, 'response') && $exception->response) {
            $context['status'] = $exception->response->status();
        }

        return $context;
    }
}
