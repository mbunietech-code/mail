@extends('onboarding.layout')

@section('title', 'Download MbunieMail')

@section('content')
    @if (empty($builds))
        <p class="muted">No app builds are available right now. Please check back soon.</p>
    @endif

    @isset($builds['apk'])
        <h2>Android</h2>
        <p class="muted">Version {{ $builds['apk']['version'] }} · {{ $builds['apk']['size'] }}</p>
        <p><a class="button block" href="{{ $builds['apk']['url'] }}" download>Download for Android (.apk)</a></p>
        <p class="muted">After downloading, open the file and allow installing from this source if your phone asks.</p>
    @endisset

    @if (isset($builds['windows']) || isset($builds['windows-zip']))
        <h2>Windows</h2>
        @isset($builds['windows'])
            <p class="muted">Version {{ $builds['windows']['version'] }} · {{ $builds['windows']['size'] }}</p>
            <p><a class="button block" href="{{ $builds['windows']['url'] }}" download>Download for Windows (.exe)</a></p>
        @endisset
        @isset($builds['windows-zip'])
            <p><a class="button secondary block" href="{{ $builds['windows-zip']['url'] }}" download>Windows folder (.zip, {{ $builds['windows-zip']['size'] }})</a></p>
            <p class="muted">For the .zip, extract it and run <code>mbuniemail.exe</code>. Windows may show a SmartScreen warning: choose “More info”, then “Run anyway”.</p>
        @endisset
    @endif
@endsection
