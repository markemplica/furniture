<?php

namespace Tests\Feature;

use App\Mail\FeedbackReceived;
use App\Models\FeedbackReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BetaSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_feedback_is_saved_and_queued_for_the_admin(): void
    {
        Mail::fake();
        config(['services.feedback.admin_email' => 'beta-admin@example.test']);

        $this->post(route('feedback.store'), [
            'email' => 'tester@example.test',
            'category' => 'bug',
            'subject' => 'Preview issue',
            'message' => 'The preview did not load.',
        ])->assertRedirect()->assertSessionHas('success');

        $report = FeedbackReport::firstOrFail();
        $this->assertDatabaseHas('feedback_reports', ['id' => $report->id, 'category' => 'bug']);
        Mail::assertQueued(FeedbackReceived::class, fn (FeedbackReceived $mail) => $mail->report->is($report));
    }

    public function test_health_endpoint_reports_database_cache_and_queue_status(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.cache', true)
            ->assertJsonPath('checks.queue', true);
    }
}
