@extends('onboarding.layout')
@section('title', 'Join MbunieMail')
@section('content')
@if (session('done'))
    <p>Your account is ready. Open the MbunieMail app and sign in with <strong>{{ session('done') }}</strong>.</p>
@else
    <p class="muted">Set your name and a password for MbunieMail (not your mailbox password).</p>
    <form method="post" action="{{ route('onboarding.invite.accept') }}">
        @csrf
        <input type="hidden" id="token" name="token" value="{{ old('token') }}">
        <label for="name">Your name</label>
        <input id="name" type="text" name="name" value="{{ old('name') }}" maxlength="120" required autocomplete="name">
        <label for="password">Password (at least 12 characters)</label>
        <input id="password" type="password" name="password" required autocomplete="new-password">
        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
        <p><button type="submit">Create account</button></p>
    </form>
@endif
@endsection
