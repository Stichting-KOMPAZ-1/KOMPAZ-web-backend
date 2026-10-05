@extends('nova.layout')

@section('title', __('nova.sign_in.title'))

@section('content')
    <h1>{{ __('nova.sign_in.title') }}</h1>
    <p>{{ __('nova.sign_in.intro') }}</p>

    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
    @endif

    @error('email')
        <div class="error">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('nova.sign-in.send') }}">
        @csrf
        <label for="email">{{ __('nova.sign_in.email') }}</label>
        <input id="email" name="email" type="email" required autofocus autocomplete="email"
               value="{{ old('email') }}">
        <button type="submit">{{ __('nova.sign_in.submit') }}</button>
    </form>
@endsection
