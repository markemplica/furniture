@extends('layouts.app')

@section('title', 'Send feedback')

@section('content')
<header class="page-heading">
    <div><p class="section-kicker">Closed beta</p><h1>Send feedback or report a bug</h1><p>Tell us what happened and what you expected. Please do not include passwords or private account details.</p></div>
</header>
<form class="panel form-panel" method="POST" action="{{ route('feedback.store') }}">
    @csrf
    <div class="row g-3">
        <div class="col-12 col-md-6"><label class="form-label" for="email">Email for follow-up</label><input class="form-control" id="email" name="email" type="email" maxlength="255" value="{{ old('email', auth()->user()?->email) }}" required autocomplete="email"></div>
        <div class="col-12 col-md-6"><label class="form-label" for="category">What is this about?</label><select class="form-select" id="category" name="category" required><option value="feedback" @selected(old('category') === 'feedback')>Product feedback</option><option value="bug" @selected(old('category') === 'bug')>Report a bug</option><option value="accessibility" @selected(old('category') === 'accessibility')>Accessibility issue</option><option value="other" @selected(old('category') === 'other')>Other</option></select></div>
        <div class="col-12"><label class="form-label" for="subject">Short summary</label><input class="form-control" id="subject" name="subject" maxlength="120" value="{{ old('subject') }}" required></div>
        <div class="col-12"><label class="form-label" for="message">Details</label><textarea class="form-control" id="message" name="message" rows="6" maxlength="5000" required>{{ old('message') }}</textarea></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Send report</button></div>
    </div>
</form>
@endsection
