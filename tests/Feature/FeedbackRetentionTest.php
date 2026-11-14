<?php

namespace Tests\Feature;

use App\Mail\FeedbackReceived;
use App\Models\FeedbackReport;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FeedbackRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_deletion_anonymizes_linked_feedback_and_redacts_unreviewed_text(): void
    {
        $user = User::factory()->create(['name' => 'Maya Private', 'email' => 'maya@example.test']);
        $report = $this->report($user, [
            'email' => 'alternate@example.test',
            'subject' => 'Maya Private needs help',
            'message' => 'I am Maya Private. Call 09171234567 or email maya@example.test.',
        ]);
        $unreviewed = $this->report($user);
        $other = $this->report(User::factory()->create());
        $guest = $this->report();
        $otherAttributes = $other->fresh()->getAttributes();
        $guestAttributes = $guest->fresh()->getAttributes();

        $this->actingAs($user)->delete(route('profile.destroy'), [
            'current_password' => 'password',
            'confirm_account_deletion' => 'DELETE',
        ])->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertModelMissing($user);
        foreach ([$report, $unreviewed] as $anonymized) {
            $anonymized->refresh();
            $this->assertNull($anonymized->user_id);
            $this->assertNull($anonymized->email);
            $this->assertSame('Deleted user', $anonymized->name);
            $this->assertSame('Feedback from deleted user', $anonymized->subject);
            $this->assertSame(FeedbackReport::REDACTED_MESSAGE, $anonymized->message);
        }
        $this->assertSame($otherAttributes, $other->fresh()->getAttributes());
        $this->assertSame($guestAttributes, $guest->fresh()->getAttributes());
    }

    public function test_model_deletion_keeps_only_messages_explicitly_reviewed_as_personal_data_free(): void
    {
        $user = User::factory()->create();
        $report = $this->report($user);
        $report->forceFill(['message_is_personal_data_free' => true])->save();
        $message = $report->message;

        $user->delete();

        $report->refresh();
        $this->assertNull($report->user_id);
        $this->assertNull($report->email);
        $this->assertSame('Deleted user', $report->name);
        $this->assertSame('Feedback from deleted user', $report->subject);
        $this->assertSame($message, $report->message);
    }

    public function test_editing_a_reviewed_message_invalidates_its_personal_data_review(): void
    {
        $user = User::factory()->create();
        $report = $this->report($user);
        $report->forceFill(['message_is_personal_data_free' => true])->save();

        $report->update(['message' => 'Please contact '.$user->email]);
        $this->assertFalse($report->fresh()->message_is_personal_data_free);

        $user->delete();
        $this->assertSame(FeedbackReport::REDACTED_MESSAGE, $report->fresh()->message);
    }

    public function test_feedback_submitter_cannot_bypass_the_personal_data_review(): void
    {
        Mail::fake();
        config(['services.feedback.admin_email' => 'owner@example.test']);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('feedback.store'), [
            'email' => $user->email,
            'category' => 'bug',
            'subject' => 'Preview issue',
            'message' => 'Please contact '.$user->email,
            'message_is_personal_data_free' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $report = FeedbackReport::firstOrFail();
        $this->assertFalse($report->message_is_personal_data_free);
        $user->delete();
        $this->assertSame(FeedbackReport::REDACTED_MESSAGE, $report->fresh()->message);
    }

    public function test_pruning_deletes_reports_older_than_twelve_months_and_keeps_the_boundary(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(12, 0, 0));
        $cutoff = now()->subMonthsNoOverflow(12);
        $expired = $this->report(User::factory()->create());
        $expired->forceFill(['created_at' => $cutoff->copy()->subSecond(), 'updated_at' => now()])->save();
        $expiredGuest = $this->report();
        $expiredGuest->forceFill(['created_at' => $cutoff->copy()->subMonth()])->save();
        $boundary = $this->report();
        $boundary->forceFill(['created_at' => $cutoff])->save();
        $recent = $this->report();

        $this->artisan('feedback:prune')
            ->expectsOutput('Deleted 2 feedback reports older than 12 months.')
            ->assertExitCode(0);

        $this->assertModelMissing($expired);
        $this->assertModelMissing($expiredGuest);
        $this->assertModelExists($boundary);
        $this->assertModelExists($recent);
    }

    public function test_feedback_pruning_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'feedback:prune'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }

    public function test_queued_feedback_email_reloads_the_anonymized_report_without_reply_to(): void
    {
        $user = User::factory()->create();
        $report = $this->report($user, ['message' => 'Please contact '.$user->email]);
        $queuedMail = serialize(new FeedbackReceived($report));

        $user->delete();

        $mail = unserialize($queuedMail);
        $mail->build();
        $this->assertSame([], $mail->replyTo);
        $html = $mail->render();
        $this->assertStringContainsString('Deleted user', $html);
        $this->assertStringNotContainsString($user->email, $html);
        $this->assertStringNotContainsString('Please contact', $html);
    }

    private function report(?User $user = null, array $overrides = []): FeedbackReport
    {
        return FeedbackReport::create(array_merge([
            'user_id' => $user?->id,
            'email' => $user?->email ?? 'guest@example.test',
            'category' => 'bug',
            'subject' => 'Preview issue',
            'message' => 'The room preview did not load.',
        ], $overrides));
    }
}
