@extends('layouts.app')
@section('title','Home')
@section('body_class','home-page')
@section('content')
<section class="home-hero home-editorial-hero home-quiet-hero" aria-labelledby="home-heading">
    <div class="hero-copy">
        <p class="section-kicker">Intelligent furniture planning</p>
        <h1 id="home-heading">Furniture that feels right at home.</h1>
        <p class="lead">Bring together your room, budget, material preferences, and style. FurniCraft turns them into clear recommendations.</p>
        <div class="home-hero-actions d-flex flex-wrap gap-2">
            <a class="btn btn-primary btn-lg" href="{{ route('preferences.edit') }}">Start planning</a>
            <a class="btn btn-outline-dark btn-lg" href="{{ route('catalog.index') }}">Explore the collection</a>
        </div>
    </div>
    <div class="hero-showcase home-hero-gallery">
        <div id="topProducts" class="carousel slide" data-home-carousel data-bs-interval="3000" role="region" aria-roledescription="carousel" aria-label="Featured furniture">
            <div class="carousel-indicators">
                @foreach($topProducts as $index => $product)
                    <button type="button" data-bs-target="#topProducts" data-bs-slide-to="{{ $index }}" class="{{ $index === 0 ? 'active' : '' }}" @if($index === 0) aria-current="true" @endif aria-label="Show {{ $product->name }}"></button>
                @endforeach
            </div>
            <div class="carousel-inner" aria-live="polite">
                @forelse($topProducts as $index => $product)
                    <article class="carousel-item {{ $index === 0 ? 'active' : '' }}" role="group" aria-roledescription="slide" aria-label="{{ $index + 1 }} of {{ $topProducts->count() }}">
                        <img src="{{ $product->image_url }}" class="d-block w-100" alt="{{ $product->name }}" loading="{{ $index === 0 ? 'eager' : 'lazy' }}" decoding="async" data-image-fallback="{{ asset('images/furniture/default.svg') }}">
                        <div class="carousel-caption">
                            <div><span>{{ $product->category }}</span><h2>{{ $product->name }}</h2><p>PHP {{ number_format($product->price, 2) }}</p></div>
                            <a class="featured-view-more" href="{{ route('catalog.index') }}" aria-label="View more furniture in the catalog">View more <span aria-hidden="true">&rarr;</span></a>
                        </div>
                    </article>
                @empty
                    <div class="empty-state">The catalog is ready for its first furniture item.</div>
                @endforelse
            </div>
            @if($topProducts->count() > 1)
                <button class="carousel-control-prev" type="button" data-bs-target="#topProducts" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span><span class="visually-hidden">Previous</span></button>
                <button class="carousel-control-next" type="button" data-bs-target="#topProducts" data-bs-slide="next"><span class="carousel-control-next-icon"></span><span class="visually-hidden">Next</span></button>
            @endif
        </div>
    </div>
</section>

@endsection
