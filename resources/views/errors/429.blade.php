@extends('errors.layout')
@section('title', 'Please wait a moment')
@section('heading', 'Please wait before trying again.')
@section('message', $message ?? 'You have made too many requests. Please wait a moment and try again.')
