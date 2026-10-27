<?php

namespace App\Services;

use App\Models\FurnitureProduct;
use App\Models\Recommendation;

/**
 * Stable application-facing facade for Gemini features.
 * Feature implementations live in focused services.
 */
class GeminiService
{
    public function __construct(
        private GeminiClient $provider,
        private GeminiChatService $chatService,
        private GeminiRoomVisionService $roomVision,
    ) {}

    public function limitProviderRequests(int $remaining): void { $this->provider->limitProviderRequests($remaining); }
    public function clearProviderRequestLimit(): void { $this->provider->clearProviderRequestLimit(); }
    public function providerCallCount(): int { return $this->provider->providerCallCount(); }
    public function isConfigured(): bool { return $this->provider->isConfigured(); }

    public function recommendationSummary(array $preference, array $products): array { return $this->chatService->recommendationSummary($preference, $products); }
    public function localRecommendationSummary(array $preference): array { return $this->chatService->localRecommendationSummary($preference); }

    public function chat(Recommendation $recommendation, string $question): array { return $this->chatService->chat($recommendation, $question); }
    public function roomFallback(?float $width, ?float $depth, ?string $furnitureType = null): array { return $this->roomVision->roomFallback($width, $depth, $furnitureType); }
    public function analyzeRoom(string $storagePath, ?float $width, ?float $depth, ?string $furnitureType = null, bool $throwOnFailure = false): array { return $this->roomVision->analyzeRoom($storagePath, $width, $depth, $furnitureType, $throwOnFailure); }
    public function verifyRoomPlacement(string $roomStoragePath, string $previewBase64, array $placement): array { return $this->roomVision->verifyRoomPlacement($roomStoragePath, $previewBase64, $placement); }
    public function furnishRoom(string $roomStoragePath, FurnitureProduct $product, bool $throwOnFailure = false, int $modelLimit = 2, int $retryAttempts = 1): array { return $this->roomVision->furnishRoom($roomStoragePath, $product, $throwOnFailure, $modelLimit, $retryAttempts); }
    public function verifyGeneratedRoom(string $roomStoragePath, string $previewBytes, FurnitureProduct $product, int $modelLimit = 2): array { return $this->roomVision->verifyGeneratedRoom($roomStoragePath, $previewBytes, $product, $modelLimit); }

    private function request(array $parts, bool $json = false, ?array $schema = null, string $operation = 'text_generation', int $timeout = 30, int $retryAttempts = 2, int $modelLimit = 2, ?string $systemInstruction = null): string
    {
        return $this->provider->generateText($parts, $json, $schema, $operation, $timeout, $retryAttempts, $modelLimit, $systemInstruction);
    }
}
