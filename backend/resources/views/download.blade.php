@extends('onboarding.layout')

@section('title', 'Download MbunieMail')

@section('content')
    @if (empty($builds))
        <p class="muted">No app builds are available right now. Please check back soon.</p>
    @endif

    @isset($builds['android'])
        <h2>Android</h2>
        <p class="muted">Version {{ $builds['android']['version'] }} · {{ $builds['android']['size'] }}</p>
        <p><a class="button block" href="{{ $builds['android']['url'] }}">Download for Android (.apk)</a></p>
        <p class="muted">Open the downloaded file and allow installing from this source if your phone asks.</p>
    @endisset

    @isset($builds['windows-installer'])
        <h2>Windows</h2>
        <p class="muted">Version {{ $builds['windows-installer']['version'] }} · {{ $builds['windows-installer']['size'] }}</p>
        <p><a class="button block" href="{{ $builds['windows-installer']['url'] }}">Download for Windows (.exe)</a></p>
        <p class="muted">If Windows shows a SmartScreen warning, choose “More info”, then “Run anyway”.</p>
    @endisset

    <p class="muted">The apps update themselves once installed.</p>
@endsection
