@extends('layouts.app')
@section('title','Reset password')
@section('body_class','auth-page')
@section('content')
<div class="auth-layout">
    <section class="auth-story" aria-labelledby="auth-story-title">
        <a class="guest-brand" href="{{ route('home') }}" aria-label="FurniCraft home"><img class="auth-brand-logo" src="{{ asset('images/optimized/lance-logo-circle-64.webp') }}" srcset="{{ asset('images/optimized/lance-logo-circle-64.webp') }} 1x, {{ asset('images/optimized/lance-logo-circle-128.webp') }} 2x" width="56" height="56" alt=""><span>FurniCraft<small>Lance Furniture Shop</small></span></a>
        <div class="auth-story-copy">
            <p class="section-kicker">Account recovery</p>
            <h1 id="auth-story-title">A fresh start for your next space.</h1>
            <p>Reset your password to return to your saved favorites, room plans, and reservation requests.</p>
        </div>
        <p class="small mb-0 auth-story-note">Reset codes expire after 15 minutes and can only be used once.</p>
    </section>
    <section class="auth-panel" aria-labelledby="reset-heading">
        <div class="auth-panel-inner">
            <p class="section-kicker">Forgot your password?</p>
            <h2 id="reset-heading">Request a reset code</h2>
            <p class="text-muted auth-panel-intro">Enter the email on your FurniCraft account.</p>
            <form method="POST" action="{{ route('password.email') }}" class="vstack gap-3 auth-form">
                @csrf
                <div>
                    <label class="form-label" for="email">Email address</label>
                    <input class="form-control @error('email') is-invalid @enderror" id="email" type="email" name="email" value="{{ is_string(old('email')) ? old('email') : '' }}" maxlength="255" required autofocus autocomplete="email" inputmode="email" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')<div class="invalid-feedback" id="email-error">{{ $message }}</div>@enderror
                </div>
                <button class="btn btn-primary btn-lg w-100" type="submit">Send code</button>
            </form>
            <p class="auth-switch">Remember your password? <a href="{{ route('login') }}">Return to sign in</a></p>
        </div>
    </section>
</div>
@endsection
