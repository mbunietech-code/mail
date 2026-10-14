@extends('admin.layout')
@section('title', 'Confirm identity')
@section('main-class', 'narrow')
@section('content')
<div class="card">
    <h1>Confirm it’s you</h1>
    <p class="muted">Changes in the admin panel need a fresh confirmation (valid 10 minutes). After confirming, submit your change again.</p>
    <form method="post" action="{{ route('admin.reauth.submit') }}">
        @csrf
        <label for="password">Password</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required autofocus>
        <label for="code">Authenticator code</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
        <p><button type="submit">Confirm</button></p>
    </form>
</div>
@endsection
