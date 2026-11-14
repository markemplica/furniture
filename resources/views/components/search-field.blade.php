@props(['id', 'value' => '', 'placeholder' => 'Search'])
<div class="catalog-search-field">
    <svg class="catalog-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
    <input {{ $attributes->merge(['class' => 'form-control']) }} id="{{ $id }}" type="search" name="q" value="{{ $value }}" placeholder="{{ $placeholder }}" autocomplete="off" data-report-search>
    <button class="catalog-search-clear" type="button" data-search-clear aria-label="Clear search" hidden><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button>
</div>
