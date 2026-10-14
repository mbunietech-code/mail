@extends('admin.layout')
@section('title', 'Recovery codes')
@section('main-class', 'narrow')
@section('content')
<div class="card">
    <h1>Save your recovery codes</h1>
    <p class="notice">Each code works once if you lose your phone. They are shown <strong>only now</strong>. Store them offline (for example printed in a locked drawer). Do not email or photograph them.</p>
    <ul class="codes">@foreach ($codes as $code)<li>{{ $code }}</li>@endforeach</ul>
    <p><a class="button" href="{{ route('admin.dashboard') }}">I have saved them</a></p>
</div>
@endsection
