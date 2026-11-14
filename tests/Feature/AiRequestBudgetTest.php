<?php

namespace Tests\Feature;

use App\Services\AiRequestBudgetExceeded;
use App\Services\AiTelemetry;
use App\Services\GeminiClient;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiRequestBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function request(GeminiService $service, int $retries = 0): string
    {
        return (new \ReflectionMethod(GeminiService::class, 'request'))->invoke($service, [['text' => 'Test only']], true, ['type' => 'object'], 'budget_test', 5, $retries, 2);
    }

    public function test_schema_and_model_fallbacks_cannot_exceed_budget(): void
    {
        config(['services.gemini.key' => 'fake', 'services.gemini.model' => 'primary', 'services.gemini.fallback_model' => 'secondary']);
        Http::fakeSequence()->push(['error' => ['message' => 'Response schema rejected']], 400)->push([], 503)->push(['candidates' => [['content' => ['parts' => [['text' => '{}']]]]]]);
        $service = app(GeminiService::class);
        $service->limitProviderRequests(2);
        try {
            $this->request($service);
            $this->fail('Budget should stop fallback');
        } catch (AiRequestBudgetExceeded $e) {
            $this->assertSame('ai_request_limit_reached', $e->getMessage());
        }
        Http::assertSentCount(2);
        $this->assertSame(2, $service->providerCallCount());
        $service->clearProviderRequestLimit();
        $this->assertSame('{}', $this->request($service));
        $this->assertSame(3, $service->providerCallCount());
    }

    public function test_http_retry_attempts_are_counted_and_zero_budget_sends_nothing(): void
    {
        config(['services.gemini.key' => 'fake', 'services.gemini.model' => 'primary', 'services.gemini.fallback_model' => null]);
        Http::fakeSequence()->push([], 503)->push(['candidates' => [['content' => ['parts' => [['text' => '{}']]]]]]);
        $service = app(GeminiService::class);
        $service->limitProviderRequests(2);
        $this->assertSame('{}', $this->request($service, 2));
        Http::assertSentCount(2);
        $this->assertSame(2, $service->providerCallCount());
        $service->limitProviderRequests(0);
        try {
            $this->request($service);
            $this->fail('Zero budget must block requests');
        } catch (AiRequestBudgetExceeded $e) {
            $this->assertSame(2, $service->providerCallCount());
        }
        Http::assertSentCount(2);
    }

    public function test_aggregate_limit_applies_across_clients_and_retains_local_fallback(): void
    {
        config(['services.gemini.key' => 'fake', 'services.gemini.model' => 'primary', 'services.gemini.fallback_model' => null,
            'services.gemini.daily_request_limit' => 1, 'services.gemini.monthly_request_limit' => 10]);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'AI response']]]]]])]);
        $first = new GeminiClient(app(AiTelemetry::class));
        $second = new GeminiClient(app(AiTelemetry::class));
        $this->assertTrue($first->generate('question', 'local answer', 'test', retryAttempts: 0)['used']);
        $result = $second->generate('question', 'local answer', 'test', retryAttempts: 0);
        $this->assertFalse($result['used']);
        $this->assertSame('local answer', $result['text']);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('ai_usage_buckets', ['bucket' => 'day:'.now('UTC')->format('Y-m-d'), 'requests' => 1]);
        $this->travel(1)->days();
        $this->assertTrue($second->generate('question', 'local answer', 'test', retryAttempts: 0)['used']);
        Http::assertSentCount(2);
        config(['services.gemini.monthly_request_limit' => 2]);
        $this->travel(1)->days();
        $this->assertFalse($second->generate('question', 'local answer', 'test', retryAttempts: 0)['used']);
        Http::assertSentCount(2);
    }

    public function test_zero_aggregate_budget_sends_nothing(): void
    {
        config(['services.gemini.key' => 'fake', 'services.gemini.model' => 'primary', 'services.gemini.daily_request_limit' => 0]);
        Http::fake();
        $result = app(GeminiClient::class)->generate('question', 'local', 'test');
        $this->assertFalse($result['used']);
        Http::assertNothingSent();
    }
}
