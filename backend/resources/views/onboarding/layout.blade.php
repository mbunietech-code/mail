<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title') · MbunieMail</title>
    <link rel="stylesheet" href="{{ asset('assets/admin.css') }}">
</head>
<body>
<main class="narrow">
    <div class="card">
        <h1>@yield('title')</h1>
        @if ($errors->any())<ul class="errors" role="alert">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
        @yield('content')
    </div>
</main>
<script src="{{ asset('assets/fragment-token.js') }}" defer></script>
</body>
</html>
