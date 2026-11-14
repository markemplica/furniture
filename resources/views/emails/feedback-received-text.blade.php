FURNICRAFT | LANCE FURNITURE SHOP
New feedback received

Category: {{ $report->category }}
From: {{ $report->email ?? $report->name ?? 'Guest' }}
Subject: {{ $report->subject }}

{{ $report->message }}

{{ $report->email ? 'Reply to this email to respond to the person who submitted this feedback.' : 'No reply email was provided for this report.' }}
