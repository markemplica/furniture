<?php

namespace App\Mail;

use App\Models\FeedbackReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class FeedbackReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public FeedbackReport $report) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued beta feedback email failed after retrying', [
            'feedback_report_id' => $this->report->id,
            'exception' => $exception ? $exception::class : null,
        ]);
    }

    public function build(): self
    {
        return $this->subject('[FurniCraft beta] '.$this->report->subject)
            ->replyTo($this->report->email)
            ->view('emails.feedback-received');
    }
}
