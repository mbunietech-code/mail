@extends('admin.layout')
@section('title', 'Audit log')
@section('content')
<div class="head">
    <div><h1>Audit log</h1><p class="lead">Tamper-evident record of sign-ins and administrative actions. It never contains passwords, codes, tokens or message content.</p></div>
    <div class="actions">
        <form method="post" action="{{ route('admin.audit.verify') }}">@csrf<button type="submit" class="secondary">@include('admin.partials.icon', ['name' => 'shield', 'class' => 'sm'])Verify integrity</button></form>
        <a class="button secondary" href="{{ route('admin.audit.export', $filters) }}">@include('admin.partials.icon', ['name' => 'download', 'class' => 'sm'])Export CSV</a>
    </div>
</div>
<section class="card flush">
    <form method="get" class="toolbar">
        <div class="search"><label for="action" class="sr-only">Action</label>@include('admin.partials.icon', ['name' => 'search'])<input id="action" type="search" name="action" value="{{ $filters['action'] ?? '' }}" placeholder="Action, e.g. admin. or auth.login"></div>
        <div><label for="actor" class="sr-only">Actor email</label><input id="actor" type="email" name="actor" value="{{ $filters['actor'] ?? '' }}" placeholder="Actor email"></div>
        <div><label for="result" class="sr-only">Result</label><select id="result" name="result"><option value="">Any result</option>
            @foreach (['success', 'failure', 'denied'] as $r)<option value="{{ $r }}" @selected(($filters['result'] ?? '') === $r)>{{ ucfirst($r) }}</option>@endforeach</select></div>
        <div><label for="from">From</label><input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
        <div><label for="to">To</label><input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
        <button type="submit" class="secondary">Filter</button>
    </form>
    <div class="scroll"><table>
        <thead><tr><th>Time (UTC)</th><th>Actor</th><th>Action</th><th>Result</th><th>Target</th><th>IP</th><th>Details</th></tr></thead>
        <tbody>
        @forelse ($logs as $log)
            <tr>
                <td class="mono" title="#{{ $log->id }}">{{ $log->occurred_at->format('Y-m-d H:i:s') }}</td>
                <td>{{ $log->actor_user_id ? ($actors[$log->actor_user_id] ?? $log->actor_user_id) : ucfirst($log->actor_type) }}</td>
                <td><code>{{ $log->action }}</code></td>
                <td><span class="badge {{ $log->result === 'success' ? 'ok' : ($log->result === 'denied' ? 'warn' : 'bad') }}">{{ $log->result }}</span></td>
                <td class="mono">{{ $log->target_type }} {{ $log->target_id }}</td>
                <td class="mono">{{ $log->ipAddress() }}</td>
                <td class="mono">{{ $log->context ? json_encode($log->context) : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="7"><div class="empty">@include('admin.partials.icon', ['name' => 'history'])No entries match.</div></td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $logs->links('admin.pagination') }}
</section>
<p class="muted">Exports are themselves recorded in the audit log.</p>
@endsection
