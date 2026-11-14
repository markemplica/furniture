<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeRoomImage;
use App\Models\RoomImage;
use App\Models\User;
use App\Services\AiAggregateBudgetExceeded;
use App\Services\AiProvider;
use App\Services\AiTelemetry;
use App\Services\GeminiClient;
use App\Services\GeminiService;
use App\Services\PrivacyAudit;
use App\Services\RoomPhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroqIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai.provider' => 'groq', 'services.groq.key' => 'test-groq-key',
            'services.gemini.key' => 'test-google-key-must-never-be-used']);
        Cache::flush();
    }

    private function response(string $text = 'A helpful answer', string $finish = 'stop'): array
    {
        return ['choices' => [['message' => ['content' => $text], 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30, 'completion_tokens_details' => ['reasoning_tokens' => 10]]];
    }

    public function test_chat_uses_only_groq_bearer_auth_and_records_usage(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->response())]);
        $result = app(GeminiClient::class)->generate('Furniture question', 'Local', 'chat_response', retryAttempts: 0);
        $this->assertTrue($result['used']);
        $this->assertSame('groq', $result['source']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer test-groq-key') && ! $r->hasHeader('x-goog-api-key')
            && $r['model'] === 'openai/gpt-oss-20b' && $r['max_completion_tokens'] === 1024
            && ! isset($r['tools']) && $r['stream'] === false);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('ai_service_events', ['model' => 'openai/gpt-oss-20b', 'input_tokens' => 120, 'output_tokens' => 30, 'thinking_tokens' => 10]);
        $this->assertDatabaseHas('ai_usage_buckets', ['bucket' => 'day:'.now('UTC')->format('Y-m-d'), 'requests' => 1]);
    }

    public function test_room_analysis_round_trip_uses_inline_photo_and_groq_geometry(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5mQAAAAASUVORK5CYII=');
        $photos = $this->mock(RoomPhotoService::class);
        $photos->shouldReceive('exists')->andReturnTrue();
        $photos->shouldReceive('get')->andReturn($png);
        $photos->shouldReceive('mimeType')->andReturn('image/png');
        $geometry = ['image_quality' => ['usable' => true, 'issues' => []],
            'floor_polygon' => [[0, .4], [1, .4], [1, 1], [0, 1]], 'walls' => [], 'openings' => [],
            'blocked_zones' => [], 'existing_furniture' => [], 'scale_clues' => [], 'horizon_y' => .3,
            'lighting' => ['brightness' => .5, 'warmth' => .5, 'light_from' => 'unknown'], 'confidence' => .6];
        Http::fake(['api.groq.com/*' => Http::response($this->response(json_encode(['vision_geometry' => $geometry])))]);
        $result = app(GeminiService::class)->analyzeRoom('private-room.png', null, null, 'Chair', true);
        $this->assertTrue($result['gemini_used']); // Legacy persisted field means AI used.
        $this->assertSame('groq', $result['ai_provider']);
        Http::assertSent(fn ($r) => $r['model'] === 'qwen/qwen3.8-27b'
            && str_starts_with($r['messages'][0]['content'][1]['image_url']['url'], 'data:image/')
            && $r['response_format']['type'] === 'json_schema' && $r['reasoning_effort'] === 'none');
        Http::assertSentCount(1);
    }

    public function test_missing_groq_key_never_falls_back_to_google(): void
    {
        config(['services.groq.key' => null]);
        Http::fake();
        $this->assertFalse(app(GeminiClient::class)->generate('Question', 'Local', 'chat_response')['used']);
        Http::assertNothingSent();
    }

    public function test_gemini_rollback_does_not_reuse_groq_photo_consent(): void
    {
        $room = new RoomImage(['gemini_consent_at' => now(), 'gemini_consent_version' => 'room-ai-groq-v1']);
        config(['services.ai.provider' => 'gemini']);
        $this->assertFalse(AiProvider::hasPhotoConsent($room));
        $room->gemini_consent_version = 'room-ai-v1';
        $this->assertTrue(AiProvider::hasPhotoConsent($room));
        $room->gemini_consent_version = null;
        $this->assertTrue(AiProvider::hasPhotoConsent($room));
    }

    public function test_groq_quota_error_is_not_retried_and_opens_circuit(): void
    {
        Http::fakeSequence()->push([], 429, ['Retry-After' => '60']);
        $client = app(GeminiClient::class);
        $this->assertFalse($client->generate('Question', 'Local', 'chat_response', retryAttempts: 99)['used']);
        $this->assertFalse($client->generate('Again', 'Local', 'chat_response')['used']);
        Http::assertSentCount(1);
    }

    public function test_groq_retries_are_bounded(): void
    {
        Http::fakeSequence()->push([], 503)->push([], 503);
        $this->assertFalse(app(GeminiClient::class)->generate('Question', 'Local', 'chat_response', retryAttempts: 99)['used']);
        Http::assertSentCount(2);
    }

    public function test_truncated_groq_responses_use_local_fallback_without_retry(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->response('Partial answer', 'length'))]);
        $this->assertFalse(app(GeminiClient::class)->generate('Other', 'Local', 'chat_response', retryAttempts: 0)['used']);
        Http::assertSentCount(1);
    }

    public function test_google_photo_consent_is_not_permission_to_send_to_groq(): void
    {
        $room = RoomImage::create(['user_id' => User::factory()->create()->id, 'image_path' => 'private.png',
            'gemini_consent_at' => now(), 'gemini_consent_version' => 'room-ai-v1', 'analysis_status' => 'queued']);
        $this->assertFalse(AiProvider::hasPhotoConsent($room));
        Http::fake();
        $photos = $this->mock(RoomPhotoService::class);
        $photos->shouldReceive('exists')->andReturnTrue();
        (new AnalyzeRoomImage($room->id))->handle(app(GeminiService::class), $photos, app(PrivacyAudit::class), app(AiTelemetry::class));
        Http::assertNothingSent();
        $this->assertSame('fallback', $room->fresh()->analysis_status);
        $room->update(['gemini_consent_version' => AiProvider::consentVersion()]);
        $this->assertTrue(AiProvider::hasPhotoConsent($room->fresh()));
        app(PrivacyAudit::class)->record('room_photo_sent_to_gemini', 'room_image', $room->id, ['purpose' => 'room_analysis']);
        $this->assertDatabaseHas('privacy_audit_events', ['action' => 'room_photo_sent_to_groq']);
    }

    public function test_groq_still_obeys_beta_expiry_and_never_generates_images(): void
    {
        Http::fake();
        config(['services.gemini.beta_expires_at' => now()->subSecond()->toIso8601String(), 'services.gemini.beta_budget_micro_usd' => 15000000]);
        $this->assertFalse(app(GeminiClient::class)->generate('Question', 'Local', 'chat_response')['used']);
        Http::assertNothingSent();
        $this->expectException(AiAggregateBudgetExceeded::class);
        app(GeminiClient::class)->generateImage([['text' => 'Generate an image']]);
    }

    public function test_groq_disclosure_is_rendered_and_stale_google_consent_form_is_rejected(): void
    {
        $this->get(route('privacy-policy'))->assertOk()->assertSee('Groq')->assertSee('https://console.groq.com/docs/your-data', false);
        $user = User::factory()->create();
        $user->preference()->create(['furniture_type' => 'Chair', 'budget_min' => 1000, 'budget_max' => 10000]);
        $this->actingAs($user)->get(route('preferences.edit'))->assertOk()
            ->assertSee('send this room photo to Groq')->assertSee('name="ai_consent_provider" value="groq"', false);
        Http::fake();
        $this->post(route('room.store'), ['room_image' => UploadedFile::fake()->image('room.jpg', 800, 600),
            'gemini_consent' => '1', 'ai_consent_provider' => 'gemini'])
            ->assertSessionHasErrors('ai_consent_provider');
        $this->assertDatabaseCount('room_images', 0);
        Http::assertNothingSent();
    }

    public function test_groq_refuses_unapproved_models_before_sending(): void
    {
        config(['services.groq.chat_model' => 'expensive-unapproved-model']);
        Http::fake();
        $this->assertFalse(app(GeminiClient::class)->generate('Question', 'Local', 'chat_response')['used']);
        Http::assertNothingSent();
    }
}
