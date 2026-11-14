@extends('layouts.app')
@section('title', 'My favorites')
@section('body_class', 'customer-favorites-page')
@section('content')
<header class="page-heading">
    <div><p class="section-kicker">Your collection</p><h1>My favorites</h1><p>Find the furniture you saved, all in one place.</p></div>
    <a class="btn btn-outline-dark" href="{{ route('catalog.index') }}">Browse catalog</a>
</header>
@if($products->isNotEmpty())
    <p class="text-muted">{{ $products->total() }} saved {{ Str::plural('item', $products->total()) }}</p>
    <div class="favorites-list">
        @foreach($products as $product)
            <article class="favorite-row panel">
                <div class="favorite-image">
                    @if($product->status === 'active')<a href="{{ route('catalog.show', $product) }}">@endif
                    <img src="{{ $product->image_url }}" alt="{{ $product->name }}" loading="lazy" decoding="async" data-image-fallback="{{ asset('images/furniture/default.svg') }}">
                    @if($product->status === 'active')</a>@endif
                </div>
                <div class="favorite-details">
                    <p class="favorite-category">{{ $product->category }}</p>
                    <h2>{{ $product->name }}</h2>
                    @if($product->status === 'active')
                        <p class="favorite-materials">{{ $product->wood_type }} · {{ $product->varnish_finish }} · {{ $product->style }}</p>
                        <p class="favorite-price">PHP {{ number_format($product->price, 2) }}</p>
                        <a class="favorite-view" href="{{ route('catalog.show', $product) }}">View details</a>
                    @else
                        <p class="text-muted">This item is currently unavailable.</p>
                    @endif
                </div>
                <form method="POST" action="{{ route('catalog.favorite', $product) }}" class="favorite-remove">
                    @csrf
                    <button class="btn btn-outline-dark" type="submit" aria-label="Remove {{ $product->name }} from favorites">Remove from favorites</button>
                </form>
            </article>
        @endforeach
    </div>
    <div class="mt-4">{{ $products->links() }}</div>
@else
    <div class="empty-state"><h2>No favorites yet.</h2><p>Open a product in the catalog and select Save to favorites.</p><a class="btn btn-primary" href="{{ route('catalog.index') }}">Explore furniture</a></div>
@endif
@endsection
