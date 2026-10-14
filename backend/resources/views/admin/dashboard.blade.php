@extends('admin.layout')
@section('title', 'Health')
@section('content')
<h1>Service health</h1>
@if ($adminsWithoutMfa > 0)
    <div class="flash bad" role="alert">{{ $adminsWithoutMfa }} active administrator(s) have not enrolled MFA yet.</div>
@endif
<div class="grid">
    @foreach ($checks as $name => $state)
        <div class="card"><div class="muted">{{ ucfirst($name) }}</div>
            <div class="stat"><span class="badge {{ $state === 'ok' ? 'ok' : ($state === 'failing' ? 'bad' : '') }}">{{ $state }}</span></div></div>
    @endforeach
    <div class="card"><div class="muted">Failed jobs</div><div class="stat">{{ $failedJobs }}</div></div>
    <div class="card"><div class="muted">Accounts with sync overdue (&gt;10 min)</div><div class="stat">{{ $syncLagging }}</div></div>
</div>

<h2>Queues (pending jobs)</h2>
<div class="grid">@foreach ($queues as $queue => $size)<div class="card"><div class="muted">{{ $queue }}</div><div class="stat">{{ $size ?? 'n/a' }}</div></div>@endforeach</div>

<div class="grid">
    <div class="card"><h2>Employees</h2><table>@forelse ($users as $status => $count)<tr><td>{{ $status }}</td><td>{{ $count }}</td></tr>@empty<tr><td class="muted">None</td></tr>@endforelse</table></div>
    <div class="card"><h2>Mailbox connections</h2><table>@forelse ($accounts as $status => $count)<tr><td>{{ $status }}</td><td>{{ $count }}</td></tr>@empty<tr><td class="muted">None</td></tr>@endforelse</table></div>
    <div class="card"><h2>Outgoing mail (24 h)</h2><table>@forelse ($outbox as $status => $count)<tr><td>{{ $status }}</td><td>{{ $count }}</td></tr>@empty<tr><td class="muted">None</td></tr>@endforelse</table>
        <p class="muted">“unknown” means the connection dropped mid-send; the employee decides whether to resend.</p></div>
</div>
<p class="muted">This panel shows counts and statuses only. Message content is never available to administrators.</p>
@endsection
