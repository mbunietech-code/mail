@extends('admin.layout')
@section('title', 'Set up verification')
@section('main-class', 'narrow')
@section('content')
<div class="card">
    <h1>Set up two-step verification</h1>
    <p>Administrators must use an authenticator app (for example Microsoft Authenticator, Google Authenticator or Aegis).</p>
    <p>In the app, choose <strong>enter a setup key</strong> and type:</p>
    <p class="mono">{{ trim(chunk_split($secret, 4, ' ')) }}</p>
    <p class="muted">Account: {{ auth('web')->user()->email }} · Type: time-based. Or paste this link into an app that accepts it:</p>
    <p class="mono">{{ $uri }}</p>
    <form method="post" action="{{ route('admin.mfa.enroll.confirm') }}">
        @csrf
        <label for="code">Enter the 6-digit code the app shows</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
        <p><button type="submit">Confirm</button></p>
    </form>
</div>
@endsection
