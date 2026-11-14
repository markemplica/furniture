@extends('layouts.app')
@section('title',$product->name)
@section('body_class','product-detail-page')
@section('content')
<div class="catalog-detail">
<nav aria-label="Breadcrumb" class="detail-breadcrumb"><a href="{{ route('catalog.index') }}">Catalog</a> <span aria-hidden="true">/</span> {{ $product->name }}</nav>
<div class="detail-grid">
    <figure class="detail-media"><div class="detail-image-frame"><img class="detail-image" src="{{ $product->image_url }}" alt="{{ $product->name }}" data-image-fallback="{{ asset('images/furniture/default.svg') }}"></div><figcaption class="small text-muted mt-2">Product preview is a 2D representation. Final wood grain and finish can vary.</figcaption></figure>
    <section class="detail-information" aria-labelledby="product-detail-title"><p class="section-kicker">{{ $product->category }}</p><h1 id="product-detail-title">{{ $product->name }}</h1><p class="lead">{{ $product->description }}</p>
        <dl class="spec-list"><div><dt>Wood</dt><dd><span class="swatch {{ strtolower($product->wood_type) }}"></span>{{ $product->wood_type }}</dd></div><div><dt>Finish</dt><dd>{{ $product->varnish_finish }}</dd></div><div><dt>Style</dt><dd>{{ $product->style }}</dd></div><div><dt>Dimensions</dt><dd>{{ number_format((float) $product->width_cm, 1) }} × {{ number_format((float) $product->depth_cm, 1) }} × {{ number_format((float) $product->height_cm, 1) }} cm @if($product->dimensions_estimated)<span class="text-muted">(standard planning estimate; confirm with the shop)</span>@endif</dd></div></dl>
        <p class="product-price fs-3">PHP {{ number_format($product->price, 2) }}</p>
        @auth
        <div class="d-flex flex-wrap gap-2 my-3">@if($product->status === 'active')<form method="POST" action="{{ route('catalog.favorite',$product) }}" class="m-0" data-favorite-form>@csrf<button aria-pressed="{{ $isFavorite ? 'true' : 'false' }}" class="btn {{ $isFavorite ? 'btn-dark' : 'btn-outline-dark' }}" type="submit">{{ $isFavorite ? 'Remove from favorites' : 'Save to favorites' }}</button></form>@endif<button type="button" class="btn btn-primary" data-reservation-open aria-haspopup="dialog" aria-controls="reservation-dialog">Request reservation</button></div>

        @else
        <div class="d-flex flex-wrap gap-2 my-3"><a class="btn btn-outline-dark" href="{{ route('login', ['redirect' => route('catalog.show', $product)]) }}">Log in to save favorites</a><a class="btn btn-primary" href="{{ route('login', ['redirect' => route('catalog.show', $product)]) }}">Log in to request reservation</a></div>
        @endauth
        <p class="detail-reservation-note">A reservation is a request reviewed by the shop. Confirm physical availability before visiting.</p>
        <p class="small" data-favorite-message role="status" aria-live="polite" hidden></p>
    </section>
</div>
</div>
@auth
<dialog id="reservation-dialog" class="reservation-dialog" aria-labelledby="reservation-dialog-title">
<div class="d-flex justify-content-between align-items-start gap-3 mb-2"><h2 class="h4" id="reservation-dialog-title">Request a reservation</h2><button type="button" class="btn-close" data-reservation-close aria-label="Close reservation form"></button></div><p class="fw-semibold">{{ $product->name }}</p><div data-reservation-message role="status" tabindex="-1" hidden></div><p class="small text-muted">This submits a request for review by Lance Furniture Shop; it does not confirm physical stock or hold the item. Shop staff must confirm item readiness with you. No purchase or payment is made here.</p><form method="POST" action="{{ route('reservations.store') }}" class="vstack gap-3" data-reservation-form>@csrf<input type="hidden" name="furniture_product_id" value="{{ $product->id }}"><div><label class="form-label" for="preferred_visit_at">Preferred visit date and time <span class="text-muted">optional</span></label><input class="form-control" type="datetime-local" id="preferred_visit_at" name="preferred_visit_at" min="{{ now()->format('Y-m-d\\TH:i') }}" value="{{ old('preferred_visit_at') }}"></div>@include('reservations._customization-fields', ['formSuffix' => 'catalog-'.$product->id])<div><label class="form-label" for="notes">Notes <span class="text-muted">optional</span></label><textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Questions or preferred viewing schedule">{{ old('notes') }}</textarea></div><button class="btn btn-primary" type="submit">Send reservation request</button></form>
<div class="text-center mt-3" data-reservation-done hidden><button type="button" class="btn btn-primary">Done</button></div>
</dialog>
@endauth
@endsection


