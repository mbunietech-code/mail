@extends('admin.layout')
@section('title', $subject->name)
@section('content')
<p><a href="{{ route('admin.users.index') }}">← Employees</a></p>
<h1>{{ $subject->name }} <span class="badge {{ $subject->status === 'active' ? 'ok' : 'bad' }}">{{ $subject->status }}</span></h1>
<p class="muted">{{ $subject->email }} · MFA {{ $subject->hasMfaEnabled() ? 'enabled' : 'not enrolled' }} · last sign-in {{ $subject->last_login_at?->toDayDateTimeString() ?? 'never' }}</p>

@if ($subject->id === auth('web')->id())
    <p class="notice">This is your own account. Status, role and MFA changes must be made by another administrator.</p>
@else
<div class="card">
    <h2>Account status</h2>
    <div class="actions">
        @foreach (['active' => 'Activate', 'suspended' => 'Suspend', 'deactivated' => 'Deactivate'] as $status => $label)
            @if ($subject->status !== $status)
            <form method="post" action="{{ route('admin.users.status', $subject) }}">@csrf<input type="hidden" name="status" value="{{ $status }}">
                <button type="submit" class="{{ $status === 'active' ? '' : 'danger' }}">{{ $label }}</button></form>
            @endif
        @endforeach
        <form method="post" action="{{ route('admin.users.sessions.revoke', $subject) }}">@csrf<button type="submit" class="secondary">Sign out everywhere</button></form>
        @if ($subject->hasMfaEnabled())
        <form method="post" action="{{ route('admin.users.mfa.reset', $subject) }}">@csrf<button type="submit" class="secondary">Reset MFA (lost phone)</button></form>
        @endif
    </div>
    <p class="muted">Suspend and deactivate take effect immediately: all sessions are revoked. Deactivation also stops mailbox access; personal data is deleted after the retention period.</p>
</div>

<div class="card">
    <h2>Roles</h2>
    <form method="post" action="{{ route('admin.users.roles', $subject) }}">
        @csrf
        @foreach ($roles as $role)
            <label><input type="checkbox" name="roles[]" value="{{ $role->name }}" @checked($subject->hasRole($role->name))> {{ $role->label }}@if($role->is_admin) <span class="badge warn">admin</span>@endif</label>
        @endforeach
        <p><button type="submit">Save roles</button></p>
    </form>
    <p class="muted">Administrator roles can only be granted by a super administrator and always require MFA.</p>
</div>
@endif

<div class="card">
    <h2>Mailbox connections</h2>
    <p class="muted">Connection status only — administrators cannot read employee email.</p>
    <div class="scroll"><table>
        <thead><tr><th>Mailbox</th><th>Provider</th><th>Status</th><th>Last sync</th><th>Last error</th><th></th></tr></thead>
        <tbody>
        @forelse ($accounts as $a)
            <tr>
                <td>{{ $a->email_address }}</td><td>{{ $a->providerProfile->label }}</td>
                <td><span class="badge {{ in_array($a->status, ['active', 'syncing']) ? 'ok' : 'bad' }}">{{ $a->status }}</span></td>
                <td class="muted">{{ $a->last_synced_at?->diffForHumans() ?? 'never' }}</td>
                <td><code>{{ $a->last_error_code ?? '—' }}</code></td>
                <td><form method="post" action="{{ route('admin.users.accounts.status', [$subject, $a]) }}">@csrf
                    <input type="hidden" name="enabled" value="{{ $a->status === 'disabled' ? 1 : 0 }}">
                    <button type="submit" class="secondary">{{ $a->status === 'disabled' ? 'Enable' : 'Disable' }}</button></form></td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No mailboxes linked.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

<div class="card">
    <h2>Active sessions</h2>
    <table><thead><tr><th>Device</th><th>Network</th><th>Since</th></tr></thead><tbody>
    @forelse ($sessions as $s)
        <tr><td>{{ $s->device?->name ?? 'Unknown device' }} <span class="muted">{{ $s->device?->platform }}</span></td><td><code>{{ $s->ip_prefix }}</code></td><td class="muted">{{ $s->created_at?->diffForHumans() }}</td></tr>
    @empty
        <tr><td colspan="3" class="muted">No active sessions.</td></tr>
    @endforelse
    </tbody></table>
</div>
@endsection
