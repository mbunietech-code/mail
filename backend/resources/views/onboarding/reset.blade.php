@extends('onboarding.layout')
@section('title', 'Reset your password')
@section('content')
@if (session('done'))
    <p>Your password was changed and you were signed out everywhere. Open the MbunieMail app and sign in again.</p>
@else
    <form method="post" action="{{ route('onboarding.reset.submit') }}">
        @csrf
        <input type="hidden" id="token" name="token" value="{{ old('token') }}">
        <label for="email">Work email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
        <label for="password">New password (at least 12 characters)</label>
        <input id="password" type="password" name="password" required autocomplete="new-password">
        <label for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
        <p><button type="submit">Change password</button></p>
    </form>
@endif
@endsection
