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

    public function __construct(public FeedbackReport $report) { $this->onQueue('mail'); }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued feedback email failed after retrying', [
            'feedback_report_id' => $this->report->id,
            'exception' => $exception ? $exception::class : null,
        ]);
    }

    public function build(): self
    {
        $this->subject('[FurniCraft] '.$this->report->subject)
            ->view('emails.feedback-received')
            ->text('emails.feedback-received-text');

        if ($this->report->email !== null) {
            $this->replyTo($this->report->email);
        }

        return $this;
    }
}
