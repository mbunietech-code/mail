@extends('admin.layout')
@section('title', 'Verification')
@section('content')
<h1>2-Step Verification</h1>
<p class="lead">Open your authenticator app and enter the 6-digit code for <b>{{ auth('web')->user()->email }}</b>.</p>
<form method="post" action="{{ route('admin.mfa.verify') }}">
    @csrf
    <label for="code" class="sr-only">Code</label>
    <input id="code" class="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="32" placeholder="000000" required autofocus>
    <p class="hint">Lost your phone? Enter one of your recovery codes instead.</p>
    <p><button type="submit" class="block">Verify</button></p>
</form>
<form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit" class="text block">Use another account</button></form>
@endsection
