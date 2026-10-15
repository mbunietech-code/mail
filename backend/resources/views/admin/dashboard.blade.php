@extends('admin.layout')
@section('title', 'Overview')
@section('content')
@php
    $activeUsers = (int) ($users['active'] ?? 0);
    $allUsers = (int) collect($users)->sum();
    $okAccounts = (int) ($accounts['active'] ?? 0) + (int) ($accounts['syncing'] ?? 0);
    $allAccounts = (int) collect($accounts)->sum();
    $sent = (int) ($outbox['sent'] ?? 0);
    $problems = collect($checks)->filter(fn ($s) => $s === 'failing')->count();
    $noServer = collect($domains)->whereNull('server')->pluck('domain');
@endphp
<div class="head">
    <div><h1>Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ \Illuminate\Support\Str::before(auth('web')->user()->name, ' ') }}</h1>
    <p class="lead">Here’s how MbunieMail is doing right now.</p></div>
    @can('users.manage')<a class="button" href="{{ route('admin.users.index') }}#invite">@include('admin.partials.icon', ['name' => 'person-add'])Invite employee</a>@endcan
</div>

@if ($adminsWithoutMfa > 0)
    <div class="notice bad">@include('admin.partials.icon', ['name' => 'warning'])<span><b>{{ $adminsWithoutMfa }} active administrator(s)</b> have not set up 2-Step Verification yet.</span></div>
@endif
@if ($noServer->isNotEmpty())
    <div class="notice warn">@include('admin.partials.icon', ['name' => 'server'])<span>No mail server is assigned to <b>{{ $noServer->implode(', ') }}</b>, so employees can’t link those mailboxes yet. <a href="{{ route('admin.domains.index') }}">Fix in Domains</a></span></div>
@endif

<div class="grid">
    <div class="tile {{ $problems ? 'bad' : 'ok' }}"><span class="ico">@include('admin.partials.icon', ['name' => $problems ? 'warning' : 'check'])</span>
        <div><div class="label">Service status</div><div class="value">{{ $problems ? $problems.' issue'.($problems > 1 ? 's' : '') : 'Healthy' }}</div>
        <div class="sub">@foreach ($checks as $name => $state){{ ucfirst($name) }}: {{ $state }}@if(! $loop->last) · @endif @endforeach</div></div></div>
    <div class="tile"><span class="ico">@include('admin.partials.icon', ['name' => 'people'])</span>
        <div><div class="label">Active employees</div><div class="value">{{ $activeUsers }}</div><div class="sub">{{ $allUsers }} total</div></div></div>
    <div class="tile {{ $allAccounts > $okAccounts ? 'warn' : '' }}"><span class="ico">@include('admin.partials.icon', ['name' => 'inbox'])</span>
        <div><div class="label">Connected mailboxes</div><div class="value">{{ $okAccounts }}</div><div class="sub">{{ $allAccounts - $okAccounts }} need attention · {{ $syncLagging }} sync overdue</div></div></div>
    <div class="tile"><span class="ico">@include('admin.partials.icon', ['name' => 'send'])</span>
        <div><div class="label">Sent in the last 24 h</div><div class="value">{{ $sent }}</div><div class="sub">{{ (int) ($outbox['failed'] ?? 0) }} failed · {{ (int) ($outbox['unknown'] ?? 0) }} unconfirmed</div></div></div>
</div>

<div class="grid two">
    <section class="card flush">
        <div class="card-head"><h2>Company domains</h2>@can('policies.manage')<a class="button secondary sm" href="{{ route('admin.domains.index') }}">Manage</a>@endcan</div>
        <table><thead><tr><th>Domain</th><th class="num">Employees</th><th class="num">Mailboxes</th></tr></thead><tbody>
        @foreach ($domains as $d)
            <tr><td><b>@</b>{{ $d['domain'] }} @if (! $d['server'])<span class="badge warn">no server</span>@endif</td><td class="num">{{ $d['active_users'] }}</td><td class="num">{{ $d['mailboxes'] }}</td></tr>
        @endforeach
        </tbody></table>
    </section>
    <section class="card flush">
        <div class="card-head"><h2>Background work</h2>@can('jobs.manage')<a class="button secondary sm" href="{{ route('admin.jobs.index') }}">Failed jobs ({{ $failedJobs }})</a>@endcan</div>
        <table><thead><tr><th>Queue</th><th class="num">Waiting</th></tr></thead><tbody>
        @foreach ($queues as $queue => $size)<tr><td>{{ ['send' => 'Outgoing mail', 'sync' => 'Mailbox sync', 'default' => 'Other'][$queue] ?? $queue }}</td><td class="num">{{ $size ?? 'n/a' }}</td></tr>@endforeach
        </tbody></table>
    </section>
</div>

<div class="grid two">
    <section class="card"><h2>Employees by status</h2>
        <dl class="kv">@forelse ($users as $status => $count)<dt>{{ ucfirst($status) }}</dt><dd>{{ $count }}</dd>@empty<dt>None yet</dt><dd></dd>@endforelse</dl></section>
    <section class="card"><h2>Mailboxes by status</h2>
        <dl class="kv">@forelse ($accounts as $status => $count)<dt>{{ ucfirst(str_replace('_', ' ', $status)) }}</dt><dd>{{ $count }}</dd>@empty<dt>None yet</dt><dd></dd>@endforelse</dl>
        <p class="hint">“Unknown” sends mean the connection dropped mid-send; the employee decides whether to resend.</p></section>
</div>
@endsection
