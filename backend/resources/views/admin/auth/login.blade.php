@extends('admin.layout')
@section('title', 'Sign in')
@section('main-class', 'narrow')
@section('content')
<div class="card">
    <h1>MbunieMail Admin</h1>
    <p class="muted">For MbuniTech administrators. Every sign-in is recorded.</p>
    <form method="post" action="{{ route('admin.login.submit') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        <label for="password">Password</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
        <p><button type="submit">Continue</button></p>
    </form>
</div>
@endsection
