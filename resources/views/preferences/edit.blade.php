@extends('layouts.app')
@section('title','Plan your room')
@section('body_class','room-planning-page')
@section('content')
@php($activeRoomId = (int) old('room_image_id', $selectedRoomId))
<header class="page-heading">
    <div>
        <h1>Plan your room.</h1>
        <p>Choose your furniture and budget. Add a room photo if you want a preview.</p>
    </div>
    <a class="btn btn-outline-dark" href="{{ route('recommendations.index') }}">Past recommendations</a>
</header>

<div class="planning-workspace">
<div class="planning-split">
<section id="preference-form" class="workflow-section" aria-labelledby="preference-heading">
    <div class="workflow-section-heading">
        <div>
            <span class="planning-section-number" aria-hidden="true">01</span><h2 id="preference-heading">Furniture preferences</h2>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="workflow-state is-done" data-preference-save-state @if(!$preferenceComplete) hidden @endif>Saved</span>
            <a class="btn btn-outline-dark" href="{{ route('catalog.index') }}">Browse catalog</a>
        </div>
    </div>

    <form method="POST" action="{{ route('preferences.update') }}" class="panel" data-preferences-form>
        @csrf
        <div class="alert fc-alert mb-4" role="status" aria-live="polite" data-preferences-status hidden></div>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="furniture_type">Furniture type</label><select class="form-select" id="furniture_type" name="furniture_type" required><option value="">Choose furniture</option>@foreach($furnitureTypes as $type)<option value="{{ $type }}" @selected(old('furniture_type',$preference?->furniture_type)===$type)>{{ $type }}</option>@endforeach</select><div class="form-text">Recommendations will only include this type.</div></div>
            <?php
                $budgetRanges = [];
                for ($upper = 10000; $upper <= 100000; $upper += 10000) {
                    $lower = $upper === 10000 ? 1000 : $upper - 10000 + 0.01;
                    $budgetRanges[$lower.'-'.$upper] = 'PHP '.number_format($lower, 2).' – '.number_format($upper, 2);
                }
                $budgetRanges['100000.01-500000'] = 'PHP 100,000.01 – 500,000.00';
                $savedMinimum = old('budget_min', $preference?->budget_min);
                $savedMaximum = old('budget_max', $preference?->budget_max);
                $savedMinimum = is_numeric($savedMinimum) && $savedMinimum >= 0 && $savedMinimum <= 9999999999.99 ? $savedMinimum : null;
                $savedMaximum = is_numeric($savedMaximum) && $savedMaximum >= 0 && $savedMaximum <= 9999999999.99 ? $savedMaximum : null;
                $selectedBudget = old('budget_range', $savedMinimum !== null && $savedMaximum !== null ? (float) $savedMinimum.'-'.(float) $savedMaximum : '');
                $selectedBudget = is_string($selectedBudget) && strlen($selectedBudget) <= 64 ? $selectedBudget : '';
                if ($selectedBudget && !isset($budgetRanges[$selectedBudget]) && $savedMinimum !== null && $savedMaximum !== null) {
                    $budgetRanges[$selectedBudget] = 'PHP '.number_format($savedMinimum, 2).' – '.number_format($savedMaximum, 2).' (saved budget)';
                }
            ?>
            <div class="col-md-8"><label class="form-label" for="budget_range">Budget range</label><select class="form-select" id="budget_range" name="budget_range" required><option value="">Choose your budget</option>@foreach($budgetRanges as $value => $label)<option value="{{ $value }}" @selected($selectedBudget === $value)>{{ $label }}</option>@endforeach</select><div class="form-text">Preset budgets start at PHP 1,000.00.</div></div>
            <div class="col-md-4"><label class="form-label" for="style">Furniture style</label><select class="form-select" id="style" name="style"><option value="">Open to any style</option>@foreach($styles as $style)<option @selected(old('style',$preference?->style)===$style)>{{ $style }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label" for="wood_type">Wood type</label><select class="form-select" id="wood_type" name="wood_type"><option value="">Either wood</option>@foreach($woodTypes as $value)<option @selected(old('wood_type',$preference?->wood_type)===$value)>{{ $value }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label" for="varnish_finish">Varnish finish</label><select class="form-select" id="varnish_finish" name="varnish_finish"><option value="">Any finish</option>@foreach($varnishFinishes as $value)<option @selected(old('varnish_finish',$preference?->varnish_finish)===$value)>{{ $value }}</option>@endforeach</select></div>
            <div class="col-12"><details class="planning-extra-details" @if($errors->hasAny(['room_width_m', 'room_depth_m', 'notes'])) open @endif><summary>Room measurements &amp; notes <span>Optional</span></summary>
            <fieldset class="planning-measurements">
                <legend class="h6 mb-2">Room measurements (optional)</legend>
                <p class="form-text mt-0" id="room-measurement-help">Used only to rank furniture by room fit. Photo previews use the visible space and perspective in your photo, even when you enter measurements here.</p>
                <div class="row g-3">
                    <div class="col-sm-6"><label class="form-label" for="preference_room_width_m">Room width in meters (optional)</label><input class="form-control" id="preference_room_width_m" type="number" name="room_width_m" min="1" max="50" step="0.01" inputmode="decimal" value="{{ old('room_width_m', $preference?->room_width_m) }}" aria-describedby="room-measurement-help">@error('room_width_m')<p class="text-danger small" role="alert">{{ $message }}</p>@enderror</div>
                    <div class="col-sm-6"><label class="form-label" for="preference_room_depth_m">Room depth in meters (optional)</label><input class="form-control" id="preference_room_depth_m" type="number" name="room_depth_m" min="1" max="50" step="0.01" inputmode="decimal" value="{{ old('room_depth_m', $preference?->room_depth_m) }}" aria-describedby="room-measurement-help">@error('room_depth_m')<p class="text-danger small" role="alert">{{ $message }}</p>@enderror</div>
                </div>
            </fieldset>
            <div class="mt-3"><label class="form-label" for="notes">Anything else?</label><textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Example: Easy-clean dining furniture for a family of six.">{{ old('notes',$preference?->notes) }}</textarea></div>
            </details></div>
            <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary btn-lg planning-save-button" type="submit" data-preferences-submit>{{ $preference ? 'Update preferences' : 'Save preferences and continue' }}</button></div>
        </div>
    </form>
</section>

<section id="room-preview" class="workflow-section" aria-labelledby="room-preview-heading">
    <div class="workflow-section-heading">
        <div>
            <span class="planning-section-number" aria-hidden="true">02</span><h2 id="room-preview-heading">Room photo <span class="planning-optional-label">Optional</span></h2>
            <p>A 2D preview is a visual estimate. Confirm measurements and clearance with the shop.</p>
        </div>
        <span class="workflow-state is-done" data-room-step-state @if(!$activeRoomId) hidden @endif>Room selected</span>
    </div>

    @unless($preferenceComplete)
        <div class="alert alert-warning fc-alert" data-step-one-warning>Complete Step 1 before uploading a room image.</div>
    @endunless

    <div class="row g-4">
        <div class="col-lg-5">
            <form method="POST" action="{{ route('room.store') }}" enctype="multipart/form-data" class="panel vstack gap-3">
                @csrf
                <input type="hidden" name="return_to" value="recommendations">
                <fieldset class="vstack gap-3" data-room-upload-fieldset @disabled(!$preferenceComplete)>
                    <div>
                        <label class="form-label" for="room_image">Room photo</label>
                        <input class="form-control" id="room_image" type="file" name="room_image" data-room-photo-input accept="image/jpeg,image/png,image/webp" required>
                        <div class="form-text">JPG, PNG, or WebP · Up to 8 MB.</div>
                        <div class="form-text">Save up to {{ config('privacy.room_photo_max_count') }} room photos, with {{ round(config('privacy.room_photo_max_bytes') / 1048576) }} MB total for originals. Photo times use Philippine time (UTC+8).</div>
                        <div class="form-text">Show the floor and nearby walls with a clear placement area. For doors, include the full doorway.</div>
                        <div class="small mt-2" data-room-photo-quality role="status" aria-live="polite"></div>
                    </div>
                    <label class="form-check panel room-photo-consent mb-0"><input class="form-check-input" type="checkbox" name="gemini_consent" value="1" @checked(old('gemini_consent'))><span class="form-check-label">Allow FurniCraft to send this room photo to {{ \App\Services\AiProvider::label() }} for AI room analysis and placement checking. Previews and measurements are estimates. <a href="{{ route('privacy-policy') }}" target="_blank" rel="noopener">Privacy details</a></span></label>
                    <input type="hidden" name="ai_consent_provider" value="{{ \App\Services\AiProvider::name() }}">
                    @error('ai_consent_provider')<p class="text-danger small" role="alert">Refresh the page and review the current AI provider before uploading.</p>@enderror
                    @error('gemini_consent')<p class="text-danger small" role="alert">{{ $message }}</p>@enderror
                    <button class="btn btn-primary btn-lg" type="submit">Upload room photo</button>
                </fieldset>
            </form>
        </div>

        <div class="col-lg-7">
            <h3 class="h4 mb-3">Your room photos</h3>
            <p class="small text-muted" data-room-selection-status aria-live="polite">Click a room to use it for your recommendation.</p>
            @forelse($rooms as $room)
                @php($isSelectedRoom = $activeRoomId === $room->id)
                @php($roomReady = in_array($room->analysis_status, ['completed', 'fallback'], true))
                @php($analysisBusy = in_array($room->analysis_status, ['queued', 'processing', 'retrying'], true))
                @php($statusLabel = ['completed' => 'AI analyzed', 'queued' => 'Queued', 'processing' => 'Analyzing', 'retrying' => 'Retrying', 'failed' => 'Needs retry'][$room->analysis_status] ?? 'Local guidance')
                <article class="panel room-option mb-3 d-flex flex-column flex-sm-row gap-3 align-items-sm-center {{ $isSelectedRoom ? 'is-selected' : '' }}" data-room-option data-room-id="{{ $room->id }}" data-room-ready="{{ $roomReady ? 'true' : 'false' }}" data-room-analysis-current="{{ $room->analysis_status }}" data-room-analysis-watch="{{ route('room.analysis-status', $room) }}" role="button" tabindex="0" aria-pressed="{{ $isSelectedRoom ? 'true' : 'false' }}" aria-label="Use saved room photo from {{ $room->created_at->copy()->timezone('Asia/Manila')->format('F j, Y g:i A') }} for recommendations">
                    <div class="planning-photo-frame"><span class="planning-photo-placeholder" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><path d="M4 8h24v19H4zM4 22l7-7 6 6 4-4 7 7M21 12h.01" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg><span>Room photo</span></span><img class="planning-room-thumbnail" loading="lazy" decoding="async" src="{{ route('room-images.thumbnail', $room) }}" alt="Uploaded room from {{ $room->created_at->copy()->timezone('Asia/Manila')->format('M j, Y') }}" width="140" height="100" style="object-fit:cover"></div>
                    <div class="flex-grow-1">
                        <div><strong>Saved photo · {{ $room->created_at->copy()->timezone('Asia/Manila')->format('F j, Y · g:i A') }}</strong><span class="badge text-bg-primary ms-1" data-room-selected-badge @if(!$isSelectedRoom) hidden @endif>Selected</span><span class="badge text-bg-light ms-1" data-room-analysis-label>{{ $statusLabel }}</span></div>
                        <p class="small text-muted mb-2">@if($room->image_width_px && $room->image_height_px)Photo {{ $room->image_width_px }} × {{ $room->image_height_px }} px. @else Room photo saved. @endif @if($room->room_width_m && $room->room_depth_m){{ data_get($room->analysis, 'dimension_confidence') === 'customer-provided' ? 'Previously entered room size' : 'Estimated room size' }}: {{ $room->room_width_m }} × {{ $room->room_depth_m }} m. @endif {{ data_get($room->analysis, 'gemini_used') ? 'AI-assisted preview' : 'Approximate photo preview' }} · <span data-room-analysis-message>{{ $room->analysis_error ?: ($analysisBusy ? 'Room analysis is in progress.' : (data_get($room->analysis, 'gemini_used') ? 'Your photo is ready for an AI-assisted preview.' : 'Your photo is ready for a basic preview.')) }}</span></p>
                        <div class="d-flex flex-wrap gap-2">
                            
                            <form method="POST" action="{{ route('room.destroy', $room) }}" data-delete-room>@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Delete photo</button></form>
                            <form method="POST" action="{{ route('room.reanalyze', $room) }}">@csrf<button class="btn btn-sm btn-outline-dark" type="submit" data-room-retry @disabled($analysisBusy)>Re-analyze room</button></form>
                        </div>
                    </div>
                </article>
            @empty
                <div class="empty-state"><h3>A room photo can come later.</h3><p>Start with your preferences. You can add a photo whenever you're ready.</p></div>
            @endforelse
            {{ $rooms->links() }}
        </div>
    </div>

</section>
</div>

    <form method="POST" action="{{ route('recommendations.store') }}" class="panel mt-4" id="recommendation-form">
        @csrf
        <input type="hidden" id="room_image_id" name="room_image_id" value="{{ $activeRoomId ?: '' }}">
        <div class="row g-3 align-items-center">
            <div class="col-lg">
                <h3 class="h4 mb-1">Your plan</h3>
                <p class="planning-summary-values"><span data-saved-furniture-type>{{ $preference?->furniture_type ?: 'Choose furniture' }}</span><span aria-hidden="true"> · </span><span data-saved-budget>{{ $preference?->budget_min !== null && $preference?->budget_max !== null ? 'PHP '.number_format($preference->budget_min, 2).' – '.number_format($preference->budget_max, 2) : 'Set your budget' }}</span></p>
                <p class="text-muted mb-0" data-selected-room-label>{{ $activeRoomId ? 'Saved room photo selected.' : 'No photo selected. Your saved preferences will be used.' }}</p>
            </div>
            <div class="col-lg-auto d-grid planning-final-actions">
                <button class="btn btn-outline-dark mb-2" type="button" data-skip-room @disabled(!$preferenceComplete)>Continue without room photo</button><button class="btn btn-primary btn-lg" type="submit" data-generate-recommendations @disabled(!$preferenceComplete)>Generate recommendations</button>
            </div>
        </div>
    </form>
</div>
@endsection
