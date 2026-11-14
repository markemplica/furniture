@extends('layouts.app')
@section('title','How it works')
@section('body_class', 'how-it-works-page')
@section('content')
<header class="page-heading">
    <div>
        <p class="section-kicker">How FurniCraft works</p>
        <h1>Find furniture for your home.</h1>
        <p>Browse the collection, compare your options, and plan your next visit to Lance Furniture Shop.</p>
    </div>
    <a class="btn btn-primary" href="{{ route('preferences.edit') }}">Start planning</a>
</header>

<section class="home-services" aria-labelledby="explore-heading">
    <div class="home-services-heading">
        <p class="section-kicker">Explore FurniCraft</p>
        <h2 id="explore-heading">Browse, compare, and plan.</h2>
    </div>
    <div class="home-service-grid">
        <a class="home-service-card" href="{{ route('catalog.index') }}"><span>01</span><div><h3>Catalog</h3><p>Browse the complete Lance Furniture Shop collection by category, material, style, and budget.</p></div><b aria-hidden="true">&rarr;</b></a>
        <a class="home-service-card" href="{{ route('preferences.edit') }}"><span>02</span><div><h3>Furniture recommendations</h3><p>FurniCraft ranks active catalog furniture using your preferences and available room-fit information.</p></div><b aria-hidden="true">&rarr;</b></a>
        <a class="home-service-card" href="{{ route('room.index') }}"><span>03</span><div><h3>Room preview</h3><p>Upload a room photo to see an approximate 2D furniture preview. Visual placement does not confirm physical fit.</p></div><b aria-hidden="true">&rarr;</b></a>
    </div>
    @guest<p class="access-note">You can browse the public catalog without an account. <strong>Sign in to save preferences, plan a room, save favorites, or request a reservation.</strong></p>@endguest
</section>

<section class="process-grid" aria-labelledby="how-heading">
    <div>
        <p class="section-kicker">The planning process</p>
        <h2 id="how-heading">Three simple steps.</h2>
        <p>Start with your preferences, then check the details with the shop.</p>
    </div>
    <ol>
        <li><strong>Set your preferences</strong><span>Choose a budget, style, and materials. Add a photo for an optional room preview; measurements are not required to start. With your consent, AI can help analyze the photo. Local guidance is used when AI analysis is unavailable.</span></li>
        <li><strong>Review intelligent matches</strong><span>Compare ranked furniture with concise explanations and room-fit context.</span></li>
        <li><strong>Preview and reserve</strong><span>Use the 2D preview to compare placement, then confirm furniture dimensions, room space, and access clearances with Lance Furniture Shop before requesting a reservation.</span></li>
    </ol>
</section>
@endsection
