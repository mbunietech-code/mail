<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title', 'Admin') · MbunieMail Admin</title>
    <link rel="stylesheet" href="{{ asset('assets/admin.css') }}">
</head>
<body>
@php($signedIn = auth('web')->check() && session('admin_mfa_user') === auth('web')->id())
@if ($signedIn)
@php($me = auth('web')->user())
<div class="shell">
    <aside class="side" aria-label="Admin navigation">
        <a class="brand" href="{{ route('admin.dashboard') }}"><span class="logo">@include('admin.partials.icon', ['name' => 'mail'])</span><span>MbunieMail<small>Admin console</small></span></a>
        <nav class="nav" aria-label="Admin sections">
            @can('health.read')<a href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>@include('admin.partials.icon', ['name' => 'dashboard'])Overview</a>@endcan
            @can('users.manage')<a href="{{ route('admin.users.index') }}" @if(request()->routeIs('admin.users.*')) aria-current="page" @endif>@include('admin.partials.icon', ['name' => 'people'])Employees</a>@endcan
            @can('policies.manage')
                <a href="{{ route('admin.domains.index') }}" @if(request()->routeIs('admin.domains.*')) aria-current="page" @endif>@include('admin.partials.icon', ['name' => 'globe'])Domains</a>
                <a href="{{ route('admin.providers.index') }}" @if(request()->routeIs('admin.providers.*')) aria-current="page" @endif>@include('admin.partials.icon', ['name' => 'server'])Mail servers</a>
                <a href="{{ route('admin.policies.show') }}" @if(request()->routeIs('admin.policies.*')) aria-current="page" @endif>@include('admin.partials.icon', ['name' => 'shield'])Security</a>
            @endcan
            @can('audit.read')<a href="{{ route('admin.audit.index') }}" @if(request()->routeIs('admin.audit.*')) aria-current="page" @endif>@include('admin.partials.icon', ['name' => 'history'])Audit log</a>@endcan
            @can('jobs.manage')<a href="{{ route('admin.jobs.index') }}" @if(request()->routeIs('admin.jobs.*')) aria-current="page" @endif>@include('admin.partials.icon', ['name' => 'warning'])Failed jobs</a>@endcan
        </nav>
        <p class="foot">Administrators see connection status only — never email content.</p>
    </aside>
    <div class="content">
        <header class="topbar">
            <p class="page">@yield('title', 'Admin')</p>
            <div class="me">
                <div class="who"><b>{{ $me->name }}</b><span>{{ $me->email }}</span></div>
                @include('admin.partials.avatar', ['name' => $me->name, 'email' => $me->email])
                <form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit" class="text" title="Sign out">@include('admin.partials.icon', ['name' => 'logout'])<span class="sr-only">Sign out</span></button></form>
            </div>
        </header>
        <main>
            @include('admin.partials.flash')
            @yield('content')
        </main>
    </div>
</div>
@else
<div class="auth">
    <div class="auth-card">
        <a class="brand" href="{{ route('admin.login') }}"><span class="logo">@include('admin.partials.icon', ['name' => 'mail'])</span><span>MbunieMail<small>Admin console</small></span></a>
        @include('admin.partials.flash')
        @yield('content')
    </div>
</div>
@endif
</body>
</html>
