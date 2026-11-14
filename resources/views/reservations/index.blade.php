@extends('layouts.app')
@section('title','Reservations')
@section('body_class', 'customer-reservations-page')
@section('content')
<header class="page-heading"><div><p class="section-kicker">Your account</p><h1>Your reservations</h1><p>Follow your requests and shop updates here. We’ll email you when a status changes.</p></div><a class="btn btn-primary" href="{{ route('catalog.index') }}">Browse furniture</a></header>
<p class="reservation-shop-note"><strong>Before visiting</strong> Confirm stock and item readiness with Lance Furniture Shop, even after approval. FurniCraft records requests only; it does not track stock or collect payment.</p>
@forelse($reservations as $reservation)
<article class="panel reservation-card mb-3">
    <div class="reservation-card-layout">
        <div class="reservation-card-image"><img src="{{ $reservation->product->image_url }}" alt="{{ $reservation->product->name }}"></div>
        <div class="reservation-card-copy">
            <span class="status status-{{ $reservation->status }}">{{ match($reservation->status) { 'confirmed' => 'Request approved', 'completed' => 'Completed / claimed', 'no_show' => 'No-show', default => ucfirst($reservation->status) } }}</span>
            <h2 class="h4 mt-2 mb-1">{{ $reservation->product->name }}</h2>
            <p class="mb-1"><strong>{{ $reservation->reference }}</strong> · {{ $reservation->branch }}</p>
            @if($reservation->notes)<details class="small mb-2"><summary>Request notes / customization</summary><div class="text-muted mt-1" style="white-space:pre-wrap">{{ $reservation->notes }}</div></details>@endif
            @if($reservation->preferred_visit_at)<p class="small mb-1">Preferred visit: {{ $reservation->preferred_visit_at->format('F j, Y · g:i A') }}</p>@endif
            @if($reservation->status === 'confirmed')<p class="small mb-1">Shop readiness: {{ $reservation->item_ready_confirmed_at ? 'Marked ready by shop' : 'Not yet confirmed' }}</p>@endif
            @if($reservation->status === 'declined' && $reservation->decline_reason)<p class="small text-danger mb-1">Reason: {{ $reservation->decline_reason }}</p>@endif
            @if($reservation->completed_at)<p class="small text-success mb-1">Visit completed / claimed</p>@elseif($reservation->no_show_at)<p class="small text-muted mb-1">Visit recorded as no-show</p>@endif
            @if($reservation->cancellation_reason)<p class="small text-muted mb-1">{{ $reservation->cancellation_reason }}</p>@endif
            <p class="small text-muted mb-0">Requested {{ $reservation->created_at->format('F j, Y · g:i A') }}@if(in_array($reservation->status, ['confirmed', 'completed', 'no_show']) && $reservation->confirmed_at) · Request approved {{ $reservation->confirmed_at->format('F j, Y') }}@endif</p>
        </div>
        <div class="reservation-card-actions">
            @if(in_array($reservation->status, ['pending', 'confirmed']))<form method="POST" action="{{ route('reservations.cancel',$reservation) }}">@csrf @method('PATCH')<input class="form-control form-control-sm mb-1" name="cancellation_reason" placeholder="Reason (optional)"><button class="btn btn-sm btn-outline-danger" data-confirm="Cancel this reservation request?">Cancel request</button></form>@else<a class="btn btn-sm btn-outline-dark" href="{{ route('catalog.show',$reservation->product) }}">View product</a>@endif
        </div>
    </div>
    <div class="reservation-timeline-wrap">
        <h3 class="h6 mb-2">Status timeline</h3>
        <ol class="reservation-timeline" aria-label="Status timeline for reservation {{ $reservation->reference }}">
            @foreach($reservation->statusTimeline() as $event)
                <li class="reservation-timeline-step {{ $loop->last ? 'is-current' : '' }}">
                    <strong>{{ $event['label'] }}</strong>
                    <time datetime="{{ $event['at']->toIso8601String() }}">{{ $event['at']->format('M j, Y · g:i A') }}</time>
                </li>
            @endforeach
        </ol>
    </div>
</article>
@empty<div class="empty-state"><h2>No reservation requests yet.</h2><p>Reserve from a catalog page or recommendation result.</p></div>@endforelse
{{ $reservations->links() }}
@endsection
