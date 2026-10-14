<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin') · MbunieMail</title>
    <link rel="stylesheet" href="{{ asset('assets/admin.css') }}">
</head>
<body>
@auth('web')
    @if (session('admin_mfa_user') === auth('web')->id())
    <header class="top">
        <a class="brand" href="{{ route('admin.dashboard') }}">MbunieMail Admin</a>
        <nav aria-label="Admin sections">
            @can('health.read')<a href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>Health</a>@endcan
            @can('users.manage')<a href="{{ route('admin.users.index') }}" @if(request()->routeIs('admin.users.*')) aria-current="page" @endif>Employees</a>@endcan
            @can('audit.read')<a href="{{ route('admin.audit.index') }}" @if(request()->routeIs('admin.audit.*')) aria-current="page" @endif>Audit log</a>@endcan
            @can('policies.manage')<a href="{{ route('admin.policies.show') }}" @if(request()->routeIs('admin.policies.*')) aria-current="page" @endif>Policies</a>
                <a href="{{ route('admin.providers.index') }}" @if(request()->routeIs('admin.providers.*')) aria-current="page" @endif>Mail servers</a>@endcan
            @can('jobs.manage')<a href="{{ route('admin.jobs.index') }}" @if(request()->routeIs('admin.jobs.*')) aria-current="page" @endif>Failed jobs</a>@endcan
        </nav>
        <span class="muted">{{ auth('web')->user()->email }}</span>
        <form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">Sign out</button></form>
    </header>
    @endif
@endauth
<main class="@yield('main-class')">
    @if (session('status'))<div class="flash ok" role="status">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="flash bad" role="alert">{{ session('error') }}</div>@endif
    @if ($errors->any())
        <ul class="errors" role="alert">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif
    @yield('content')
</main>
</body>
</html>
