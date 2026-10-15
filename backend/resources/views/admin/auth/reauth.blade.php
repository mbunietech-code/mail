@extends('admin.layout')
@section('title', 'Confirm it’s you')
@section('content')
<div class="card">
    <h1>Confirm it’s you</h1>
    <p class="lead">Changes need a fresh confirmation, valid for 10 minutes. After confirming, submit your change again.</p>
    <form method="post" action="{{ route('admin.reauth.submit') }}" class="stack">
        @csrf
        <div><label for="password">Password</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required autofocus></div>
        <div><label for="code">Authenticator code</label>
        <input id="code" class="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" required></div>
        <p><button type="submit">Confirm</button></p>
    </form>
</div>
@endsection
