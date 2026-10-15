@extends('admin.layout')
@section('title', 'Recovery codes')
@section('content')
<h1>Save your recovery codes</h1>
<p class="lead">Each code works once if you lose your phone. They are shown <b>only now</b>.</p>
<div class="notice warn">@include('admin.partials.icon', ['name' => 'warning'])<span>Store them offline, for example printed in a locked drawer. Do not email or photograph them.</span></div>
<ul class="codes">@foreach ($codes as $code)<li>{{ $code }}</li>@endforeach</ul>
<p><a class="button block" href="{{ route('admin.dashboard') }}">I have saved them</a></p>
@endsection
