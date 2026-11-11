<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Download MbunieMail</title>
    <link rel="icon" href="{{ asset('assets/mbunie-mail-icon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('assets/download.css') }}">
</head>
<body>
    <div class="brand">
        <img class="mark" src="{{ asset('assets/mbunie-mail-icon.svg') }}" alt="MbunieMail">
        <div class="name">MbunieMail<span>Mbunietech Workspace</span></div>
    </div>
    <h1>Your company email on every device. Pick your platform.</h1>

    <div class="cards">
        <div class="card">
            <div class="icon icon-android">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.6 9.48l1.84-3.18c.16-.31.04-.69-.26-.85-.29-.15-.65-.06-.83.22l-1.88 3.24a11.43 11.43 0 0 0-8.94 0L5.65 5.67a.637.637 0 0 0-.87-.2c-.28.16-.38.53-.22.83L6.4 9.48A10.78 10.78 0 0 0 1 18h22a10.78 10.78 0 0 0-5.4-8.52zM7 15.25a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5zm10 0a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5z"/></svg>
            </div>
            <h2>Android</h2>
            <p class="desc">Download the APK and install it directly on your phone or tablet.</p>
            @isset($builds['android'])
                <a class="btn btn-primary" href="{{ $builds['android']['url'] }}">Download APK ↓</a>
                <p class="meta">{{ $builds['android']['size'] }} · v{{ $builds['android']['version'] }}</p>
                <p class="note">If your phone asks, allow installing apps from your browser.</p>
            @else
                <a class="btn btn-disabled" aria-disabled="true">Coming soon</a>
            @endisset
        </div>

        <div class="card">
            <div class="icon icon-windows">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 5.5L10.5 4.4V11.4H3V5.5M11.5 4.3L21 3V11.3H11.5V4.3M3 12.4H10.5V19.5L3 18.4V12.4M11.5 12.4H21V20.9L11.5 19.6V12.4Z"/></svg>
            </div>
            <h2>Windows</h2>
            <p class="desc">Run the installer to set up MbunieMail on your Windows 10 or 11 PC.</p>
            @isset($builds['windows-installer'])
                <a class="btn btn-primary" href="{{ $builds['windows-installer']['url'] }}">Download Installer ↓</a>
                <p class="meta">{{ $builds['windows-installer']['size'] }} · v{{ $builds['windows-installer']['version'] }}</p>
                <p class="note">If Windows SmartScreen appears, click <strong>More info</strong>, then <strong>Run anyway</strong>.</p>
            @else
                <a class="btn btn-disabled" aria-disabled="true">Coming soon</a>
            @endisset
        </div>

        <div class="card">
            <div class="icon icon-apple">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.37 12.6c-.02-2.3 1.88-3.4 1.96-3.46-1.07-1.56-2.73-1.78-3.32-1.8-1.41-.14-2.76.83-3.47.83-.72 0-1.82-.81-3-.79-1.54.02-2.96.9-3.76 2.28-1.6 2.78-.41 6.9 1.15 9.16.76 1.1 1.67 2.34 2.86 2.3 1.15-.05 1.58-.74 2.97-.74 1.38 0 1.77.74 2.98.72 1.23-.02 2.01-1.12 2.76-2.23.87-1.28 1.23-2.51 1.25-2.58-.03-.01-2.4-.92-2.42-3.66zM14.1 5.84c.63-.77 1.06-1.83.94-2.89-.91.04-2.01.6-2.66 1.37-.58.67-1.1 1.76-.96 2.8 1.01.08 2.05-.52 2.68-1.28z"/></svg>
            </div>
            <h2>macOS</h2>
            <p class="desc">MbunieMail for Mac, on Apple Silicon and Intel.</p>
            <a class="btn btn-disabled" aria-disabled="true">Coming soon</a>
        </div>

        <div class="card">
            <div class="icon icon-apple">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.37 12.6c-.02-2.3 1.88-3.4 1.96-3.46-1.07-1.56-2.73-1.78-3.32-1.8-1.41-.14-2.76.83-3.47.83-.72 0-1.82-.81-3-.79-1.54.02-2.96.9-3.76 2.28-1.6 2.78-.41 6.9 1.15 9.16.76 1.1 1.67 2.34 2.86 2.3 1.15-.05 1.58-.74 2.97-.74 1.38 0 1.77.74 2.98.72 1.23-.02 2.01-1.12 2.76-2.23.87-1.28 1.23-2.51 1.25-2.58-.03-.01-2.4-.92-2.42-3.66zM14.1 5.84c.63-.77 1.06-1.83.94-2.89-.91.04-2.01.6-2.66 1.37-.58.67-1.1 1.76-.96 2.8 1.01.08 2.05-.52 2.68-1.28z"/></svg>
            </div>
            <h2>iPhone &amp; iPad</h2>
            <p class="desc">Install MbunieMail from Apple on iOS 15 or later.</p>
            <a class="btn btn-disabled" aria-disabled="true">Coming soon</a>
        </div>
    </div>

    <footer>
        © {{ date('Y') }} Mbunietech Technologies Limited. All rights reserved.
        @if (Route::has('privacy')) · <a href="{{ route('privacy') }}">Privacy Policy</a>@endif
    </footer>
</body>
</html>
