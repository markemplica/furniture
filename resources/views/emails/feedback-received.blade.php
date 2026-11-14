@include('emails.customer-notification', [
    'eyebrow' => 'CUSTOMER FEEDBACK',
    'heading' => 'New feedback received',
    'intro' => 'A feedback report is ready for your review.',
    'details' => [
        ['label' => 'CATEGORY', 'value' => $report->category],
        ['label' => 'FROM', 'value' => $report->email ?? $report->name ?? 'Guest'],
        ['label' => 'SUBJECT', 'value' => $report->subject],
    ],
    'bodyText' => $report->message,
    'footerNote' => $report->email ? 'Reply to this email to respond to the person who submitted this feedback.' : 'No reply email was provided for this report.',
])
