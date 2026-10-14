@extends('admin.layout')
@section('title', 'Verification')
@section('main-class', 'narrow')
@section('content')
<div class="card">
    <h1>Two-step verification</h1>
    <form method="post" action="{{ route('admin.mfa.verify') }}">
        @csrf
        <label for="code">6-digit code from your authenticator app, or a recovery code</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="32" required autofocus>
        <p><button type="submit">Verify</button></p>
    </form>
    <form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit" class="secondary">Cancel</button></form>
</div>
@endsection
