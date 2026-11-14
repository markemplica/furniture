@extends('layouts.app')
@section('title','Add furniture')
@section('body_class', 'furniture-editor-page')
@section('content')<nav class="furniture-editor-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('admin.products.index') }}">Catalog</a><span aria-hidden="true">/</span><span aria-current="page">Add furniture</span></nav>
<header class="page-heading"><div><p class="section-kicker">Catalog records</p><h1>Add furniture</h1><p>Use exact materials, finish, price, and measurements so recommendations remain trustworthy.</p></div><a class="furniture-editor-back" href="{{ route('admin.products.index') }}">Back to catalog</a></header><form class="panel furniture-editor" method="POST" action="{{ route('admin.products.store') }}" enctype="multipart/form-data">@csrf @include('admin.products._form')</form>@endsection
