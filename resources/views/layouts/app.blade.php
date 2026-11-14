<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#4b3326">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/optimized/lance-favicon-32.png') }}?v={{ filemtime(public_path('images/optimized/lance-favicon-32.png')) }}">
    <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('images/optimized/lance-favicon-48.png') }}?v={{ filemtime(public_path('images/optimized/lance-favicon-48.png')) }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/optimized/lance-apple-touch-180.png') }}">
    <title>@yield('title', 'FurniCraft') | Lance Furniture Shop</title>
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.documentElement.dataset.theme = 'light';
        try {
            @auth
            if (localStorage.getItem('furnicraft-theme') === 'dark') document.documentElement.dataset.theme = 'dark';
            @else
            localStorage.removeItem('furnicraft-theme');
            @endauth
        } catch (_) {}
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="{{ asset('css/furnicraft.css') }}?v={{ filemtime(public_path('css/furnicraft.css')) }}" rel="stylesheet">
</head>
<body class="@guest guest-body @endguest @if(request()->routeIs('admin.*')) admin-page @endif @yield('body_class')">
<a class="skip-link" href="#main-content">Skip to main content</a>
@auth
<nav class="navbar {{ auth()->user()->isAdmin() ? 'navbar-expand-xl' : 'navbar-expand-lg' }} navbar-light fc-navbar sticky-top" aria-label="Main navigation">
    <div class="container-xl">
        <a class="navbar-brand" href="{{ auth()->user()->isAdmin() ? route('admin.dashboard') : route('home') }}"><img class="brand-logo" src="{{ asset('images/optimized/lance-logo-64.webp') }}" srcset="{{ asset('images/optimized/lance-logo-64.webp') }} 1x, {{ asset('images/optimized/lance-logo-128.webp') }} 2x" width="48" height="48" alt=""><span>FurniCraft<small>Lance Furniture Shop</small></span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Open navigation"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto {{ auth()->user()->isAdmin() ? 'align-items-xl-center gap-xl-1' : 'align-items-lg-center gap-lg-1' }}">
                @if(auth()->user()->isAdmin())
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}">Overview</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.products.*') ? 'active' : '' }}" href="{{ route('admin.products.index') }}">Catalog</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.reservations.*') ? 'active' : '' }}" href="{{ route('admin.reservations.index') }}">Reservations</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.reports.*') ? 'active' : '' }}" href="{{ route('admin.reports.index') }}">Reports</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.customers.*') ? 'active' : '' }}" href="{{ route('admin.customers.index') }}">Customers</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.privacy-audit') ? 'active' : '' }}" href="{{ route('admin.privacy-audit') }}">Privacy audit</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.api-usage') ? 'active' : '' }}" href="{{ route('admin.api-usage') }}">API usage</a></li>
                @else
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}" href="{{ route('home') }}">Home</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('how-it-works') ? 'active' : '' }}" href="{{ route('how-it-works') }}">How it works</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('catalog.*') ? 'active' : '' }}" href="{{ route('catalog.index') }}">Catalog</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('preferences.*','recommendations.*','room.*') ? 'active' : '' }}" href="{{ route('preferences.edit') }}">Plan your room</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('reservations.*') ? 'active' : '' }}" href="{{ route('reservations.index') }}">Reservations</a></li>
                @endif
                <li class="nav-item dropdown ms-lg-2">
                    <button class="btn account-button dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">{{ auth()->user()->name }}</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small text-muted">{{ auth()->user()->isAdmin() ? 'Administrator/Owner' : 'Customer' }}</span></li>
                        @if(! auth()->user()->isAdmin())
                            <li><a class="dropdown-item {{ request()->routeIs('profile.*') ? 'active' : '' }}" href="{{ route('profile.edit') }}">Profile settings</a></li>
                            <li><a class="dropdown-item {{ request()->routeIs('favorites.*') ? 'active' : '' }}" href="{{ route('favorites.index') }}">Favorites</a></li>
                        @endif
                        <li><button class="dropdown-item" type="button" data-theme-toggle aria-pressed="false">Dark mode</button></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><form method="POST" action="{{ route('logout') }}">@csrf<button class="dropdown-item" type="submit">Sign out</button></form></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
@endauth

@guest
    @if(request()->routeIs('home', 'how-it-works', 'catalog.*'))
<nav class="navbar navbar-expand-lg navbar-light fc-navbar sticky-top" aria-label="Public navigation">
    <div class="container-xl">
        <a class="navbar-brand" href="{{ route('home') }}"><img class="brand-logo" src="{{ asset('images/optimized/lance-logo-64.webp') }}" srcset="{{ asset('images/optimized/lance-logo-64.webp') }} 1x, {{ asset('images/optimized/lance-logo-128.webp') }} 2x" width="48" height="48" alt=""><span>FurniCraft<small>Lance Furniture Shop</small></span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav" aria-controls="publicNav" aria-expanded="false" aria-label="Open navigation"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="publicNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}" href="{{ route('home') }}">Home</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('how-it-works') ? 'active' : '' }}" href="{{ route('how-it-works') }}">How it works</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('catalog.*') ? 'active' : '' }}" @if(request()->routeIs('catalog.index')) aria-current="page" @endif href="{{ route('catalog.index') }}">Catalog</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('preferences.edit') }}">Plan your room</a></li>
            </ul>
            <div class="public-auth-actions d-flex flex-wrap gap-2 ms-lg-3">
                <a class="btn btn-outline-dark" href="{{ route('login') }}">Log in</a>
                <a class="btn btn-primary" href="{{ route('register') }}">Create account</a>
            </div>
        </div>
    </div>
</nav>
    @endif
@endguest

<main id="main-content" class="{{ auth()->check() || request()->routeIs('home', 'how-it-works', 'catalog.*') ? 'page-shell' : 'guest-shell' }}">
    <div class="container-xl page-content {{ request()->routeIs('catalog.index') ? 'catalog-page-content' : '' }}">
        @unless(request()->routeIs('password.reset'))
            @if(session('success') && !request()->routeIs('admin.reservations.index') && !(request()->routeIs('login') && session('password_reset_completed')))
                <div class="alert alert-success fc-alert" role="status">{{ session('success') }}</div>
            @endif
            @if(session('warning'))
                <div class="alert alert-warning fc-alert" role="status">{{ session('warning') }}</div>
            @endif
            @if($errors->any() && !request()->routeIs('login', 'register', 'register.store'))
                <div class="alert alert-danger fc-alert" role="alert">
                    <strong>Please check the form.</strong>
                    <ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
        @endunless
        @yield('content')
    </div>
</main>

@if(auth()->check() || request()->routeIs('home', 'how-it-works', 'catalog.*'))
<footer class="site-footer">
    <div class="container-xl site-footer-inner">
        <div class="site-footer-identity"><span class="footer-brand">FurniCraft</span><small>Intelligent furniture planning for Lance Furniture Shop.</small></div>
        <span class="site-footer-note">Designed around real rooms, local craftsmanship, and informed choices.</span>
        <a class="site-footer-link" href="{{ route('feedback.create') }}">Send feedback / report a bug</a>
    </div>
</footer>
@endif

@if(session('welcome'))
<div class="welcome-toast" data-welcome-toast role="status" aria-live="polite" aria-atomic="true">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>
    <p>{{ session('welcome') }}</p>
    <button type="button" data-dismiss-welcome aria-label="Dismiss welcome message"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button>
</div>
@endif
<script src="{{ asset('js/furnicraft.js') }}?v={{ filemtime(public_path('js/furnicraft.js')) }}"></script>
<script src="{{ asset('js/room-preview-engine.js') }}?v={{ filemtime(public_path('js/room-preview-engine.js')) }}"></script>
@stack('scripts')
</body>
</html>

