@php($editing = isset($product))
<p class="furniture-required-note">Fields marked <span aria-hidden="true">*</span> are required.</p>
<div class="furniture-editor-grid">
<div class="furniture-editor-main">
<section class="panel furniture-editor-section"><h2>Basic information</h2><div class="row g-4 furniture-field-grid">
<div class="col-md-8"><label class="form-label" for="name">Product name <span class="furniture-required" aria-hidden="true">*</span></label><input class="form-control @error('name') is-invalid @enderror" id="name" placeholder="e.g. Narra six-seat dining table" name="name" aria-describedby="name-error" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" value="{{ old('name',$product->name ?? '') }}" required>
@error('name')<p class="invalid-feedback d-block mb-0" id="name-error" role="alert">{{ $message }}</p>@enderror
</div>
<div class="col-md-4"><label class="form-label" for="category">Category <span class="furniture-required" aria-hidden="true">*</span></label><input class="form-control @error('category') is-invalid @enderror" id="category" placeholder="e.g. Dining Table" name="category" aria-describedby="category-error" aria-invalid="{{ $errors->has('category') ? 'true' : 'false' }}" value="{{ old('category',$product->category ?? '') }}" required>
@error('category')<p class="invalid-feedback d-block mb-0" id="category-error" role="alert">{{ $message }}</p>@enderror
</div>
<div class="col-12"><label class="form-label" for="description">Description <span class="furniture-required" aria-hidden="true">*</span></label><textarea class="form-control @error('description') is-invalid @enderror" id="description" placeholder="Describe the furniture, materials, and useful product details." name="description" aria-describedby="description-error" aria-invalid="{{ $errors->has('description') ? 'true' : 'false' }}" rows="4" required>{{ old('description',$product->description ?? '') }}</textarea>
@error('description')<p class="invalid-feedback d-block mb-0" id="description-error" role="alert">{{ $message }}</p>@enderror
</div>

</div></section>
<section class="panel furniture-editor-section"><h2>Materials & style</h2><div class="row g-4 furniture-field-grid">
<div class="col-md-3"><label class="form-label" for="wood_type">Wood <span class="furniture-required" aria-hidden="true">*</span></label><input class="form-control @error('wood_type') is-invalid @enderror" id="wood_type" placeholder="e.g. Mahogany" name="wood_type" aria-describedby="wood_type-error" aria-invalid="{{ $errors->has('wood_type') ? 'true' : 'false' }}" list="wood-type-options" value="{{ old('wood_type',$product->wood_type ?? '') }}" required><datalist id="wood-type-options">@foreach($woodTypes as $value)<option value="{{ $value }}">@endforeach</datalist>
@error('wood_type')<p class="invalid-feedback d-block mb-0" id="wood_type-error" role="alert">{{ $message }}</p>@enderror
</div>
<div class="col-md-3"><label class="form-label" for="varnish_finish">Finish <span class="furniture-required" aria-hidden="true">*</span></label><input class="form-control @error('varnish_finish') is-invalid @enderror" id="varnish_finish" placeholder="e.g. Natural" name="varnish_finish" aria-describedby="varnish_finish-error" aria-invalid="{{ $errors->has('varnish_finish') ? 'true' : 'false' }}" list="varnish-finish-options" value="{{ old('varnish_finish',$product->varnish_finish ?? '') }}" required><datalist id="varnish-finish-options">@foreach($varnishFinishes as $value)<option value="{{ $value }}">@endforeach</datalist>
@error('varnish_finish')<p class="invalid-feedback d-block mb-0" id="varnish_finish-error" role="alert">{{ $message }}</p>@enderror
</div>
<div class="col-md-3"><label class="form-label" for="style">Style <span class="furniture-required" aria-hidden="true">*</span></label><input class="form-control @error('style') is-invalid @enderror" id="style" placeholder="e.g. Traditional" name="style" aria-describedby="style-error" aria-invalid="{{ $errors->has('style') ? 'true' : 'false' }}" value="{{ old('style',$product->style ?? '') }}" required>
@error('style')<p class="invalid-feedback d-block mb-0" id="style-error" role="alert">{{ $message }}</p>@enderror
</div>

</div></section>
<section class="panel furniture-editor-section"><h2>Size & dimensions</h2><div class="row g-4 furniture-field-grid">
<div class="col-md-3"><label class="form-label" for="width_cm">Width cm</label><div class="input-group"><input class="form-control @error('width_cm') is-invalid @enderror" id="width_cm" type="number" min="1" step=".1" name="width_cm" aria-describedby="standard-size-help width_cm-error" aria-invalid="{{ $errors->has('width_cm') ? 'true' : 'false' }}" value="{{ old('width_cm',$product->width_cm ?? '') }}"><span class="input-group-text" aria-hidden="true">cm</span></div>
@error('width_cm')<p class="invalid-feedback d-block mb-0" id="width_cm-error" role="alert">{{ $message }}</p>@enderror
</div>
<div class="col-md-3"><label class="form-label" for="depth_cm">Depth cm</label><div class="input-group"><input class="form-control @error('depth_cm') is-invalid @enderror" id="depth_cm" type="number" min="1" step=".1" name="depth_cm" aria-describedby="standard-size-help depth_cm-error" aria-invalid="{{ $errors->has('depth_cm') ? 'true' : 'false' }}" value="{{ old('depth_cm',$product->depth_cm ?? '') }}"><span class="input-group-text" aria-hidden="true">cm</span></div>
@error('depth_cm')<p class="invalid-feedback d-block mb-0" id="depth_cm-error" role="alert">{{ $message }}</p>@enderror
</div>
<div class="col-md-3"><label class="form-label" for="height_cm">Height cm</label><div class="input-group"><input class="form-control @error('height_cm') is-invalid @enderror" id="height_cm" type="number" min="1" step=".1" name="height_cm" aria-describedby="standard-size-help height_cm-error" aria-invalid="{{ $errors->has('height_cm') ? 'true' : 'false' }}" value="{{ old('height_cm',$product->height_cm ?? '') }}"><span class="input-group-text" aria-hidden="true">cm</span></div>
@error('height_cm')<p class="invalid-feedback d-block mb-0" id="height_cm-error" role="alert">{{ $message }}</p>@enderror
</div>
<div class="col-12 form-text furniture-size-note" id="standard-size-help">Leave any size field blank to apply a standard size for this furniture type. Standard sizes are planning estimates until confirmed by the shop.</div>
</div></section>
<section class="panel furniture-editor-section"><h2>Search details</h2><div class="row g-4 furniture-field-grid">
<div class="col-md-6"><label class="form-label" for="tags">Search tags</label><input class="form-control @error('tags') is-invalid @enderror" id="tags" name="tags" aria-describedby="tags-error" aria-invalid="{{ $errors->has('tags') ? 'true' : 'false' }}" value="{{ old('tags',isset($product) ? implode(', ',$product->tags ?? []) : '') }}" placeholder="dining, family, six seat"><div class="form-text">Separate tags with commas.</div>
@error('tags')<p class="invalid-feedback d-block mb-0" id="tags-error" role="alert">{{ $message }}</p>@enderror
</div>

</div></section>
</div>
<aside class="furniture-editor-sidebar" aria-label="Price, availability, and image">
<section class="panel furniture-editor-section"><h2>Price & availability</h2><div class="row g-4 furniture-field-grid">
<div class="col-md-3"><label class="form-label" for="price">Price PHP <span class="furniture-required" aria-hidden="true">*</span></label><div class="input-group"><span class="input-group-text" aria-hidden="true">PHP</span><input class="form-control @error('price') is-invalid @enderror" id="price" type="number" min="0" step=".01" name="price" aria-describedby="price-error" aria-invalid="{{ $errors->has('price') ? 'true' : 'false' }}" value="{{ old('price',$product->price ?? '') }}" required></div>
@error('price')<p class="invalid-feedback d-block mb-0" id="price-error" role="alert">{{ $message }}</p>@enderror
</div>
<div class="col-md-3"><label class="form-label" for="status">Status</label><select class="form-select @error('status') is-invalid @enderror" id="status" name="status" aria-describedby="status-error" aria-invalid="{{ $errors->has('status') ? 'true' : 'false' }}">@foreach(['active','inactive'] as $value)<option value="{{ $value }}" @selected(old('status',$product->status ?? 'active')===$value)>{{ ucfirst($value) }}</option>@endforeach</select>
@error('status')<p class="invalid-feedback d-block mb-0" id="status-error" role="alert">{{ $message }}</p>@enderror
</div>

</div></section>
<section class="panel furniture-editor-section"><h2>Product image</h2><div class="row g-4 furniture-field-grid">
<div class="col-md-6"><label class="form-label" for="image">Product image</label><input class="form-control @error('image') is-invalid @enderror" id="image" type="file" name="image" aria-describedby="image-error" aria-invalid="{{ $errors->has('image') ? 'true' : 'false' }}" accept="image/jpeg,image/png,image/webp"><div class="form-text">Optional JPG, PNG, or WebP up to 4 MB.</div>
@error('image')<p class="invalid-feedback d-block mb-0" id="image-error" role="alert">{{ $message }}</p>@enderror
</div>
<p class="furniture-image-hint">Use a clear photo with the whole piece visible. A simple background works best.</p>
</div></section>
</aside>
</div>
<div class="col-12 d-flex justify-content-end gap-2 furniture-editor-actions"><a class="btn btn-outline-dark" href="{{ route('admin.products.index') }}">Cancel</a><button class="btn btn-primary btn-lg">{{ $editing ? 'Save changes' : 'Add furniture' }}</button></div>
