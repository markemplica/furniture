<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeRoomImage;
use App\Models\AiUsageAlert;
use App\Models\FurnitureProduct;
use App\Models\RoomImage;
use App\Models\User;
use App\Notifications\AiUsageAlertNotification;
use App\Services\AiAggregateBudgetExceeded;
use App\Services\AiTelemetry;
use App\Services\AiUsageBudget;
use App\Services\GeminiChatService;
use App\Services\GeminiClient;
use App\Services\GeminiService;
use App\Services\PrivacyAudit;
use App\Services\ProductionReadiness;
use App\Services\RecommendationService;
use App\Services\RoomPhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ApiSpendingProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_optional_chat_cannot_enable_transport_retries_or_model_fallback(): void
    {
        $client=$this->provider();
        config(['services.gemini.chat_model'=>'primary','services.gemini.fallback_model'=>'secondary']);
        Http::fakeSequence()->push([],503)->push($this->success(),200);
        $answer=$client->generate('Fake question','Local answer','chat_response',retryAttempts:99,modelLimit:99);
        $this->assertFalse($answer['used']);
        Http::assertSentCount(1);
    }

    private function provider(): GeminiClient
    {
        config(['services.gemini.key' => 'test-only-key', 'services.gemini.model' => 'primary', 'services.gemini.fallback_model' => null]);

        return new GeminiClient(app(AiTelemetry::class));
    }

    private function success(): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => 'A short answer']]]]],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 5]];
    }

    public function test_kill_switch_and_missing_limits_never_send_requests(): void
    {
        Http::fake();
        foreach (['enabled' => false, 'daily_request_limit' => null, 'monthly_request_limit' => 0, 'daily_budget_micro_usd' => null, 'monthly_budget_micro_usd' => 0] as $key => $value) {
            $client = $this->provider();
            $original = config('services.gemini.'.$key);
            config(['services.gemini.'.$key => $value]);
            $this->assertFalse($client->generate('Question', 'Local', 'test')['used']);
            config(['services.gemini.'.$key => $original]);
        }
        Http::assertNothingSent();
    }

    public function test_money_is_reserved_before_requests_and_failed_attempts_are_not_refunded(): void
    {
        $client = $this->provider();
        config(['services.gemini.daily_budget_micro_usd' => 150000]);
        Http::fakeSequence()->push([], 400);
        $this->assertFalse($client->generate('Question', 'Local', 'test')['used']);
        $this->assertFalse($this->provider()->generate('Another question', 'Local', 'test')['used']);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('ai_usage_buckets', ['bucket' => 'day:'.now('UTC')->format('Y-m-d'), 'requests' => 1, 'reserved_micro_usd' => 100000]);
    }

    public function test_beta_deadline_stops_existing_clients_and_direct_reservations(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-05T00:00:00Z'));
        config(['services.gemini.beta_expires_at' => '2026-11-20T00:00:00Z', 'services.gemini.beta_budget_micro_usd' => 15000000]);
        $client = $this->provider();
        Http::fakeSequence()->push($this->success());
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-19T23:59:59Z'));
        $this->assertTrue($client->generate('Before expiry', 'Local', 'test')['used']);
        $this->travel(1)->seconds();
        $this->assertFalse($client->isConfigured());
        $this->assertFalse($client->generate('At expiry', 'Local', 'test')['used']);
        Http::assertSentCount(1);
        $this->expectException(AiAggregateBudgetExceeded::class);
        app(AiUsageBudget::class)->reserve();
    }

    public function test_beta_total_does_not_reset_across_months_or_cache_clear(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-30T12:00:00Z'));
        config(['services.gemini.beta_expires_at' => '2026-12-10T00:00:00Z', 'services.gemini.beta_budget_micro_usd' => 100000]);
        Http::fakeSequence()->push($this->success());
        $this->assertTrue($this->provider()->generate('First', 'Local', 'test')['used']);
        $this->travel(1)->days();
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertFalse($this->provider()->generate('Next month', 'Local', 'test')['used']);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('ai_usage_buckets', ['bucket' => 'beta:total', 'requests' => 1, 'reserved_micro_usd' => 100000]);
    }

    public function test_invalid_beta_configuration_fails_closed_without_transport(): void
    {
        Http::fake();
        foreach ([['invalid-date', 15000000], ['2026-11-20T00:00:00Z', 0]] as [$expires, $budget]) {
            config(['services.gemini.beta_expires_at' => $expires, 'services.gemini.beta_budget_micro_usd' => $budget]);
            $this->assertFalse($this->provider()->generate('Question', 'Local', 'test')['used']);
        }
        Http::assertNothingSent();
    }

    public function test_quota_and_authentication_errors_are_not_retried_and_open_a_shared_circuit(): void
    {
        $client = $this->provider();
        Http::fakeSequence()->push([], 429, ['Retry-After' => '120'])->push($this->success());
        $this->assertFalse($client->generate('Question', 'Local', 'test', retryAttempts: 99, modelLimit: 99)['used']);
        $this->assertFalse($this->provider()->generate('Second question', 'Local', 'test')['used']);
        Http::assertSentCount(1);
        $this->travel(121)->seconds();
        $this->assertTrue($this->provider()->generate('Third question', 'Local', 'test')['used']);
        Http::assertSentCount(2);
    }

    public function test_http_retries_are_capped_even_when_a_caller_passes_an_extreme_value(): void
    {
        $client = $this->provider();
        Http::fakeSequence()->push([], 503)->push([], 503)->push($this->success());
        $this->assertFalse($client->generate('Question', 'Local', 'test', retryAttempts: PHP_INT_MAX)['used']);
        Http::assertSentCount(2);
        $this->assertSame(2, $client->providerCallCount());
    }

    public function test_per_user_budget_is_shared_by_clients_but_does_not_block_another_user(): void
    {
        config(['services.gemini.user_daily_limit' => 1]);
        Http::fake(['*' => Http::response($this->success())]);
        $first = $this->provider();
        $first->setActor(100);
        $second = $this->provider();
        $second->setActor(100);
        $other = $this->provider();
        $other->setActor(200);
        $this->assertTrue($first->generate('Question', 'Local', 'test')['used']);
        $this->assertFalse($second->generate('Question', 'Local', 'test')['used']);
        $this->assertTrue($other->generate('Question', 'Local', 'test')['used']);
        Http::assertSentCount(2);
    }

    public function test_unapproved_models_and_oversized_prompts_are_blocked_before_sending(): void
    {
        $client = $this->provider();
        config(['services.gemini.model' => 'unapproved-expensive-pro']);
        Http::fake();
        $this->assertFalse($client->generate('Question', 'Local', 'test')['used']);
        config(['services.gemini.model' => 'primary']);
        $this->assertFalse($client->generate(str_repeat('x', 25000), 'Local', 'test')['used']);
        Http::assertNothingSent();
    }

    public function test_outputs_are_capped_and_provider_token_metadata_is_saved(): void
    {
        $client = $this->provider();
        Http::fake(['*' => Http::response($this->success())]);
        $this->assertTrue($client->generate('Question', 'Local', 'chat_response')['used']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-3.1-flash-lite:') && $request['generationConfig']['maxOutputTokens'] === 384 && $request['generationConfig']['candidateCount'] === 1 && ! isset($request['tools']));
        $this->assertDatabaseHas('ai_service_events', ['input_tokens' => 100, 'output_tokens' => 20, 'thinking_tokens' => 5]);
    }

    public function test_alerts_are_deduplicated_and_email_only_the_approved_admin_recipient(): void
    {
        Notification::fake();
        config(['services.gemini.alerts_email_enabled' => true, 'services.feedback.admin_email' => 'lancefurniture15@gmail.com', 'services.gemini.daily_request_limit' => 2]);
        $budget = app(AiUsageBudget::class);
        $budget->reserve();
        $budget->reserve();
        for ($i = 0; $i < 3; $i++) {
            try {
                $budget->reserve();
                $this->fail('Limit must block');
            } catch (AiAggregateBudgetExceeded) {
            }
        }
        $this->assertSame(2, AiUsageAlert::count());
        Notification::assertSentOnDemandTimes(AiUsageAlertNotification::class, 1);
        Notification::assertSentOnDemand(AiUsageAlertNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'lancefurniture15@gmail.com');
    }

    public function test_repeated_failures_trigger_a_circuit_and_a_deduplicated_alert(): void
    {
        $this->provider();
        for ($i = 0; $i < 7; $i++) {
            app(AiTelemetry::class)->record('text', 'chat_response', 'failure', 'primary', 503);
        }
        $this->assertSame(1, AiUsageAlert::where('code', 'provider_failure_burst')->count());
        Http::fake();
        $this->assertFalse($this->provider()->generate('Question', 'Local', 'test')['used']);
        Http::assertNothingSent();
    }

    public function test_job_deadline_does_not_move_forward_after_clock_changes(): void
    {
        $job = new AnalyzeRoomImage(1);
        $deadline = $job->retryUntil()->timestamp;
        $this->travel(5)->minutes();
        $this->assertSame($deadline, $job->retryUntil()->timestamp);
    }

    public function test_room_get_and_status_polling_never_dispatch_analysis(): void
    {
        Bus::fake();
        Http::fake();
        $user = User::factory()->create();
        $room = RoomImage::create(['user_id' => $user->id, 'image_path' => 'rooms/test.png', 'gemini_consent_at' => now(), 'analysis_status' => 'completed', 'analysis' => []]);
        $this->provider();
        $this->actingAs($user)->get(route('room.visualize', $room))->assertOk();
        $this->getJson(route('room.analysis-status', $room))->assertOk();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_email_trigger_endpoints_have_a_global_bot_spam_limit_across_ips(): void
    {
        Notification::fake();
        for ($i = 1; $i <= 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])
                ->post('/forgot-password', ['email' => 'missing@example.test'])->assertRedirect();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->post('/register', [])->assertStatus(429)->assertHeader('Retry-After');
        Notification::assertNothingSent();
    }

    public function test_image_generation_never_retries_or_silently_changes_models(): void
    {
        $client = $this->provider();
        Http::fakeSequence()->push([], 503)->push($this->success());
        try {
            $client->generateImage([['text' => 'Test image']], retryAttempts: 999, modelLimit: 999);
            $this->fail('The image request must fail');
        } catch (RequestException) {
        }
        Http::assertSentCount(1);
        $this->assertSame(1, $client->providerCallCount());
    }

    public function test_ledger_failure_blocks_provider_access_instead_of_spending_without_tracking(): void
    {
        $client = $this->provider();
        Http::fake();
        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if ($armed && str_contains($query->sql, 'insert or ignore into "ai_usage_buckets"')) {
                $armed = false;
                throw new \RuntimeException('Simulated budget ledger unavailable');
            }
        });
        $this->assertFalse($client->generate('Question', 'Local', 'test')['used']);
        Http::assertNothingSent();
    }

    public function test_repeated_chat_questions_reuse_cached_answers_and_simple_questions_stay_local(): void
    {
        $this->provider();
        $user = User::factory()->create();
        $preference = $user->preference()->create(['furniture_type' => 'Table', 'budget_min' => 1000, 'budget_max' => 30000]);
        FurnitureProduct::create(['name' => 'Test Table', 'slug' => 'test-table', 'category' => 'Table', 'description' => 'Test', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 10000, 'status' => 'active']);
        $recommendation = app(RecommendationService::class)->generate($user, $preference, null, 'Table');
        Http::fake(['*' => Http::response($this->success())]);
        $chat = app(GeminiChatService::class);
        $this->assertTrue($chat->chat($recommendation, 'Explain why these recommendations suit my preferences')['used']);
        $this->assertTrue($chat->chat($recommendation, 'Explain why these recommendations suit my preferences')['used']);
        $this->assertFalse($chat->chat($recommendation, 'What is the price?')['used']);
        Http::assertSentCount(1);
    }

    public function test_duplicate_completed_analysis_jobs_do_not_call_the_provider_again(): void
    {
        $this->provider();
        Http::fake();
        $user = User::factory()->create();
        $room = RoomImage::create(['user_id' => $user->id, 'image_path' => 'rooms/complete.png', 'analysis_status' => 'completed', 'gemini_consent_at' => now()]);
        (new AnalyzeRoomImage($room->id))->handle(app(GeminiService::class), app(RoomPhotoService::class), app(PrivacyAudit::class), app(AiTelemetry::class));
        Http::assertNothingSent();
        $this->assertSame('completed', $room->fresh()->analysis_status);
    }

    public function test_readiness_does_not_approve_enabled_ai_without_budgets_or_approved_models(): void
    {
        config(['services.gemini.enabled' => true, 'services.gemini.daily_budget_micro_usd' => 0]);
        $check = fn () => collect(app(ProductionReadiness::class)->inspect()['checks'])->keyBy('name')->get('AI spending policy')['ok'];
        $this->assertFalse($check());
        config(['services.gemini.enabled' => false]);
        $this->assertTrue($check());
        config(['services.gemini.enabled' => true, 'services.gemini.daily_budget_micro_usd' => 10000000, 'services.gemini.model' => 'unapproved-pro']);
        $this->assertFalse($check());
    }

    public function test_identical_visual_checks_reuse_a_cached_result(): void
    {
        $user = User::factory()->create();
        $room = RoomImage::create(['user_id' => $user->id, 'image_path' => 'rooms/cached.png', 'analysis_status' => 'completed', 'gemini_consent_at' => now()]);
        $product = FurnitureProduct::create(['name' => 'Cached Table', 'slug' => 'cached-table', 'category' => 'Table', 'description' => 'Test', 'wood_type' => 'Gmelina', 'varnish_finish' => 'Natural', 'style' => 'Modern', 'price' => 10000, 'status' => 'active', 'width_cm' => 120, 'depth_cm' => 70, 'height_cm' => 75]);
        $provider = $this->mock(GeminiService::class);
        $provider->shouldReceive('isConfigured')->andReturnTrue();
        $provider->shouldReceive('verifyRoomPlacement')->once()->andReturn(['valid' => true, 'verdict' => 'good', 'realism_score' => .8]);
        $payload = ['preview' => base64_encode(file_get_contents(base_path('tests/Fixtures/room-600.png'))), 'placement' => ['mode' => 'floor', 'x' => .5, 'y' => .8, 'width' => .3, 'height' => .2, 'scale' => 1]];
        $this->actingAs($user)->postJson(route('room.preview-verify', [$room, $product->id]), $payload)->assertOk()->assertJsonPath('valid', true);
        $this->postJson(route('room.preview-verify', [$room, $product->id]), $payload)->assertOk()->assertJsonPath('valid', true);
        $this->get(route('room.visualize', ['roomImage' => $room, 'product' => $product->id]))->assertOk()
            ->assertSee(route('room.preview-verify', [$room, $product->id]), false);
        $this->actingAs(User::factory()->create())->postJson(route('room.preview-verify', [$room, $product->id]), $payload)->assertForbidden();
    }

    public function test_a_log_write_failure_does_not_suppress_the_database_or_email_alert(): void
    {
        Notification::fake();
        config(['services.gemini.alerts_email_enabled' => true, 'services.feedback.admin_email' => 'lancefurniture15@gmail.com']);
        \Illuminate\Support\Facades\Log::shouldReceive('log')->once()->andThrow(new \RuntimeException('Simulated log volume unavailable'));
        app(\App\Services\AiUsageAlerts::class)->emit('log_failure_test', 'A budget guard stopped an API call.');
        $this->assertDatabaseHas('ai_usage_alerts', ['code' => 'log_failure_test']);
        Notification::assertSentOnDemandTimes(AiUsageAlertNotification::class, 1);
    }
}
