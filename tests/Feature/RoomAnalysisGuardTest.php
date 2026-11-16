<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeRoomImage;
use App\Models\RoomImage;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoomAnalysisGuardTest extends TestCase
{
    public function test_recent_analysis_cannot_be_purchased_again_immediately(): void
    {
        $room=$this->room();
        $room->update(['analysis_status'=>'fallback','analysis_attempts'=>1,'analyzed_at'=>now()]);
        Bus::fake();
        $this->postJson(route('room.reanalyze',$room))->assertStatus(429);
        Bus::assertNothingDispatched();
    }

    public function test_identical_photo_reuses_older_success_without_purchasing_another_analysis(): void
    {
        $room=$this->room();
        $room->update(['image_sha256'=>str_repeat('a',64)]);
        \App\Models\RoomPreviewAnalysis::create(['image_sha256'=>$room->image_sha256,
            'pipeline_version'=>config('roompreview.pipeline_version'),'status'=>'completed','analyzed_at'=>now()->subDay(),
            'analysis'=>['gemini_used'=>true,'vision_geometry'=>['image_quality'=>['usable'=>true]],
                'analyzed_furniture_type'=>'all catalog furniture','placement_profile_version'=>2]]);
        $this->mock(GeminiService::class,function($mock){
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldNotReceive('analyzeRoom');
        });
        Http::fake();
        app()->call([new AnalyzeRoomImage($room->id,'all catalog furniture'),'handle']);
        $this->assertSame('completed',$room->fresh()->analysis_status);
        Http::assertNothingSent();
    }

    public function test_room_analysis_never_retries_bad_requests_or_leaks_provider_error_text(): void
    {
        $room = $this->room();
        config(['services.gemini.max_calls_per_operation' => 3, 'services.gemini.fallback_model' => 'secondary']);
        Http::fake(['*' => Http::response(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'secret unsafe schema text',
            'details' => [['reason' => 'API_KEY_INVALID']]]], 400)]);
        $result = app(GeminiService::class)->analyzeRoom($room->image_path, null, null);
        $this->assertFalse($result['gemini_used']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => data_get($request->data(), 'generationConfig.responseJsonSchema') === null
            && data_get($request->data(), 'generationConfig.responseMimeType') === 'application/json');
        $this->assertDatabaseHas('ai_service_events', ['failure_code' => 'INVALID_ARGUMENT:API_KEY_INVALID']);
        $this->assertDatabaseMissing('ai_service_events', ['failure_code' => 'secret unsafe schema text']);
    }

    use RefreshDatabase;

    private function room(): RoomImage
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'primary', 'services.gemini.fallback_model' => null]);
        Storage::fake('local');
        Storage::disk('local')->put('rooms/test.png', UploadedFile::fake()->image('room.png', 800, 600)->get());
        $user = User::factory()->create();
        $this->actingAs($user);
        return RoomImage::create(['user_id' => $user->id, 'image_path' => 'rooms/test.png',
            'analysis_status' => 'queued', 'analysis_requested_at' => now(), 'gemini_consent_at' => now()]);
    }

    private function response(bool $usable): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode(['vision_geometry' => [
            'image_quality' => ['usable' => $usable, 'issues' => $usable ? [] : ['blurred']],
            'floor_polygon' => [], 'walls' => [], 'openings' => [], 'horizon_y' => null,
            'blocked_zones' => [], 'existing_furniture' => [], 'scale_clues' => [], 'lighting' => [], 'confidence' => .5,
        ]])]]]]]];
    }

    public function test_invalid_json_validation_retry_cannot_reset_the_one_call_budget(): void
    {
        $room = $this->room();
        config(['services.gemini.max_calls_per_operation' => 3]);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{}']]]]]])]);
        $result = app(GeminiService::class)->analyzeRoom($room->image_path, null, null);
        $this->assertFalse($result['gemini_used']);
        Http::assertSentCount(1);
    }

    public function test_budget_approved_model_completes_photo_analysis_with_production_allowlist(): void
    {
        $room = $this->room();
        $services = require config_path('services.php');
        config([
            'services.gemini.model' => 'gemini-3.1-flash-lite',
            'services.gemini.allowed_text_models' => $services['gemini']['allowed_text_models'],
            'services.gemini.image_enabled' => false,
        ]);
        Http::fake(['*' => Http::response($this->response(true))]);

        app()->call([new AnalyzeRoomImage($room->id), 'handle']);

        $this->assertSame('completed', $room->fresh()->analysis_status);
        $this->assertTrue($room->fresh()->analysis['gemini_used']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/models/gemini-3.1-flash-lite:generateContent')
            && $request->hasHeader('x-goog-api-key', 'test-key')
            && isset($request['contents'][0]['parts'][1]['inline_data']['data']));
        $this->assertDatabaseHas('ai_service_events', ['operation' => 'room_analysis', 'model' => 'gemini-3.1-flash-lite', 'status' => 'success']);
    }

    public function test_unusable_photo_is_fallback_and_actual_transfer_is_audited(): void
    {
        $room = $this->room();
        Http::fake(['*' => Http::response($this->response(false))]);
        app()->call([new AnalyzeRoomImage($room->id), 'handle']);
        $this->assertSame('fallback', $room->fresh()->analysis_status);
        $this->assertFalse($room->fresh()->analysis['gemini_used']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('privacy_audit_events', 1);
        $this->assertDatabaseHas('privacy_audit_events', ['action' => 'room_photo_sent_to_gemini', 'subject_id' => $room->id]);
        $this->assertNull(Context::getHidden('ai_photo_transfer'));
    }

    public function test_budget_blocked_photo_is_not_recorded_as_sent(): void
    {
        $room = $this->room();
        config(['roompreview.max_provider_calls' => 0]);
        Http::fake();
        app()->call([new AnalyzeRoomImage($room->id), 'handle']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('privacy_audit_events', 0);
        $this->assertSame('fallback', $room->fresh()->analysis_status);
    }

    public function test_stale_queue_recovers_but_recent_work_is_not_restarted(): void
    {
        $room = $this->room();
        Bus::fake();
        $this->postJson(route('room.reanalyze', $room))->assertUnprocessable();
        Bus::assertNothingDispatched();
        $room->update(['analysis_requested_at' => now()->subHour()]);
        $this->getJson(route('room.analysis-status', $room))->assertOk()->assertJsonPath('status', 'failed');
        $this->postJson(route('room.reanalyze', $room))->assertOk();
        Bus::assertDispatched(AnalyzeRoomImage::class, 1);
        $this->assertTrue($room->fresh()->analysis_requested_at->gt(now()->subMinute()));
    }

    public function test_aggregate_spending_block_does_not_log_a_transfer(): void
    {
        $room = $this->room();
        $this->mock(\App\Services\AiUsageBudget::class, function ($mock) {
            $mock->shouldReceive('reserve')->once()->andThrow(new \App\Services\AiAggregateBudgetExceeded);
        });
        Http::fake();
        app()->call([new AnalyzeRoomImage($room->id), 'handle']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('privacy_audit_events', 0);
    }

    public function test_saved_unusable_success_is_corrected_on_status_read(): void
    {
        $room = $this->room();
        $room->update(['analysis_status' => 'completed', 'analysis' => ['gemini_used' => true,
            'vision_geometry' => ['image_quality' => ['usable' => false, 'issues' => ['blurred']]]]]);
        $this->getJson(route('room.analysis-status', $room))->assertOk()
            ->assertJsonPath('status', 'fallback')->assertJsonPath('analysis.gemini_used', false);
    }
}
