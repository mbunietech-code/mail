@extends('admin.layout')
@section('title', 'Set up verification')
@section('content')
<h1>Set up 2-Step Verification</h1>
<p class="lead">Administrators must use an authenticator app such as Microsoft Authenticator, Google Authenticator or Aegis.</p>
<ol class="stack">
    <li>In the app, choose <b>Enter a setup key</b> (time-based) and type:
        <p class="secret">{{ trim(chunk_split($secret, 4, ' ')) }}</p>
        <p class="hint">Account name: {{ auth('web')->user()->email }}</p></li>
    <li>Enter the 6-digit code the app shows.</li>
</ol>
<form method="post" action="{{ route('admin.mfa.enroll.confirm') }}">
    @csrf
    <label for="code" class="sr-only">Code</label>
    <input id="code" class="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" required autofocus>
    <p><button type="submit" class="block">Turn on</button></p>
</form>
<details class="more"><summary>Setup link for apps that accept it</summary><p class="mono">{{ $uri }}</p></details>
@endsection
