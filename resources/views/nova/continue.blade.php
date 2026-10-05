@extends('nova.layout')

@section('title', __('nova.sign_in.continue_title'))

@section('content')
    <h1>{{ __('nova.sign_in.continue_title') }}</h1>
    <p>{{ __('nova.sign_in.continue_intro') }}</p>

    <form method="POST" action="{{ route('nova.sign-in.redeem') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <button type="submit" autofocus>{{ __('nova.sign_in.continue_submit') }}</button>
    </form>
@endsection
