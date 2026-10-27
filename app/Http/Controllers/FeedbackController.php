<?php

namespace App\Http\Controllers;

use App\Http\Requests\FeedbackReportRequest;
use App\Mail\FeedbackReceived;
use App\Models\FeedbackReport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class FeedbackController extends Controller
{
    public function create()
    {
        return view('feedback.create');
    }

    public function store(FeedbackReportRequest $request)
    {
        $data = $request->validated();
        $report = FeedbackReport::create([
            ...$data,
            'user_id' => $request->user()?->id,
        ]);

        $recipient = config('services.feedback.admin_email');
        if (! is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return back()->with('warning', 'Your report was saved, but email delivery is not configured. Please contact Lance Furniture Shop directly.');
        }

        try {
            Mail::to($recipient)->queue(new FeedbackReceived($report));
        } catch (Throwable $exception) {
            Log::error('Feedback report was saved but email delivery failed', ['feedback_report_id' => $report->id, 'exception' => $exception::class]);

            return back()->with('warning', 'Your report was saved, but we could not email the admin just now.');
        }

        return back()->with('success', 'Thank you. Your feedback was saved and queued for the FurniCraft team.');
    }
}
