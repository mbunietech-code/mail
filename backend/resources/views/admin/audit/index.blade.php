@extends('admin.layout')
@section('title', 'Audit log')
@section('content')
<h1>Audit log</h1>
<form method="get" class="inline card">
    <div><label for="action">Action starts with</label><input id="action" type="text" name="action" value="{{ $filters['action'] ?? '' }}" placeholder="admin. or auth.login"></div>
    <div><label for="actor">Actor email</label><input id="actor" type="email" name="actor" value="{{ $filters['actor'] ?? '' }}"></div>
    <div><label for="result">Result</label><select id="result" name="result"><option value="">Any</option>
        @foreach (['success', 'failure', 'denied'] as $r)<option value="{{ $r }}" @selected(($filters['result'] ?? '') === $r)>{{ $r }}</option>@endforeach</select></div>
    <div><label for="from">From</label><input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
    <div><label for="to">To</label><input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
    <button type="submit" class="secondary">Filter</button>
</form>
<div class="actions">
    <a class="button secondary" href="{{ route('admin.audit.export', $filters) }}">Export CSV</a>
    <form method="post" action="{{ route('admin.audit.verify') }}">@csrf<button type="submit" class="secondary">Verify tamper-evidence chain</button></form>
</div>
<p class="muted">Exports are themselves audited. Entries never contain passwords, codes, tokens or message content.</p>
<div class="scroll"><table>
    <thead><tr><th>#</th><th>Time (UTC)</th><th>Actor</th><th>Action</th><th>Target</th><th>Result</th><th>IP</th><th>Details</th></tr></thead>
    <tbody>
    @forelse ($logs as $log)
        <tr>
            <td>{{ $log->id }}</td>
            <td class="mono">{{ $log->occurred_at->format('Y-m-d H:i:s') }}</td>
            <td>{{ $log->actor_user_id ? ($actors[$log->actor_user_id] ?? $log->actor_user_id) : $log->actor_type }}</td>
            <td><code>{{ $log->action }}</code></td>
            <td class="mono">{{ $log->target_type }} {{ $log->target_id }}</td>
            <td><span class="badge {{ $log->result === 'success' ? 'ok' : 'bad' }}">{{ $log->result }}</span></td>
            <td class="mono">{{ $log->ipAddress() }}</td>
            <td class="mono">{{ $log->context ? json_encode($log->context) : '' }}</td>
        </tr>
    @empty
        <tr><td colspan="8" class="muted">No entries.</td></tr>
    @endforelse
    </tbody>
</table></div>
{{ $logs->links('admin.pagination') }}
@endsection
