@extends('layouts.app')
@section('title','API usage & budget controls')
@section('content')
<header class="page-heading"><div><p class="section-kicker">Administration</p><h1>API usage &amp; budget controls</h1><p>Review AI requests, spending allowances, and recent usage alerts.</p></div><a class="btn btn-outline-dark" href="{{ route('admin.dashboard') }}">Back to overview</a></header>
<section class="panel" aria-labelledby="api-usage-heading">
    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center">
        <h2 id="api-usage-heading" class="h4 mb-0">Usage summary</h2>
        <span>{{ $aiUsage['provider'] }}{{ $aiUsage['key_present'] ? '' : ' · API key missing; using local fallback' }}</span>
        <span class="status">{{ $aiUsage['enabled'] ? 'Paid AI enabled with limits' : 'Paid AI off · Local guidance available' }}</span>
    </div>
    <dl class="row g-3 mt-2 mb-2">
        <div class="col-sm-4"><dt class="small text-muted">Provider attempts today / limit (UTC)</dt><dd>{{ number_format($aiUsage['today']) }} / {{ number_format($aiUsage['daily_limit']) }}</dd></div>
        <div class="col-sm-4"><dt class="small text-muted">Provider attempts this month / limit (UTC)</dt><dd>{{ number_format($aiUsage['month']) }} / {{ number_format($aiUsage['monthly_limit']) }}</dd></div>
        <div class="col-sm-4"><dt class="small text-muted">Reserved monthly allowance / limit</dt><dd>USD {{ number_format($aiUsage['reserved_usd'], 2) }} / {{ number_format($aiUsage['monthly_budget_usd'], 2) }}</dd></div>
    </dl>
    @if($aiUsage['beta_expires_at'])
        <p class="small">Beta paid-AI deadline: {{ $aiUsage['beta_expires_at'] }}.
            Reserved beta allowance: USD {{ number_format($aiUsage['beta_reserved_usd'], 2) }} / {{ number_format($aiUsage['beta_budget_usd'], 2) }}.
            Paid requests stop at the deadline; local guidance stays available.</p>
    @endif
    <p class="small text-muted">Reservations include failed or uncertain requests and are not refunded automatically. This is a conservative app allowance, not Google's invoice. Check provider billing too. {{ $aiUsage['email_enabled'] ? 'Email alerts are enabled; delivery requires a working mail worker.' : 'Alerts appear here and in logs.' }}</p>
    @forelse($aiUsage['alerts'] as $alert)
        <div class="border-top py-2"><time class="small text-muted" datetime="{{ $alert->created_at->toIso8601String() }}">{{ $alert->created_at->format('M j, Y g:i A') }}</time><p class="small mb-0">{{ $alert->message }}</p></div>
    @empty
        <p class="small mb-0">No recorded API usage alerts.</p>
    @endforelse
</section>
@endsection
