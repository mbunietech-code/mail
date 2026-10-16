@extends('admin.layout')
@section('title', 'Sign in')
@section('content')
<h1>Sign in</h1>
<p class="lead">For MbunieTech administrators. Every sign-in is recorded.</p>
<form method="post" action="{{ route('admin.login.submit') }}">
    @csrf
    <label for="email">Work email</label>
    <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" placeholder="name@mt.co.tz" required autofocus>
    <label for="password">Password</label>
    <input id="password" type="password" name="password" autocomplete="current-password" required>
    <p><button type="submit" class="block">Continue</button></p>
</form>
<p class="auth-foot">Protected by two-step verification</p>
@endsection
