@extends('admin.layout')
@section('title', $subject->name)
@section('content')
<p><a href="{{ route('admin.users.index') }}" class="button text sm">@include('admin.partials.icon', ['name' => 'back', 'class' => 'sm'])Employees</a></p>
<section class="card">
    <div class="person">
        @include('admin.partials.avatar', ['name' => $subject->name, 'email' => $subject->email, 'size' => 'lg'])
        <div><h1>{{ $subject->name }}</h1><span>{{ $subject->email }}</span></div>
    </div>
    <dl class="kv">
        <dt>Status</dt><dd>@if (! $subject->hasAllowedDomain())<span class="badge bad">Blocked — domain removed</span>@else<span class="badge {{ $subject->status === 'active' ? 'ok' : 'bad' }}">{{ ucfirst($subject->status) }}</span>@endif</dd>
        <dt>2-Step Verification</dt><dd>{{ $subject->hasMfaEnabled() ? 'On' : 'Not set up' }}</dd>
        <dt>Roles</dt><dd>{{ $subject->roles->pluck('label')->implode(', ') ?: '—' }}</dd>
        <dt>Last sign-in</dt><dd>{{ $subject->last_login_at?->toDayDateTimeString() ?? 'Never' }}</dd>
    </dl>
</section>

@if ($subject->id === auth('web')->id())
    <div class="notice">@include('admin.partials.icon', ['name' => 'info'])<span>This is your own account. Status, role and verification changes must be made by another administrator.</span></div>
@else
<div class="grid two">
<section class="card">
    <h2>Access</h2>
    <p class="muted">Suspend and deactivate take effect immediately and sign the person out everywhere. Personal data of deactivated employees is deleted after the retention period.</p>
    <div class="actions">
        @foreach (['active' => 'Activate', 'suspended' => 'Suspend', 'deactivated' => 'Deactivate'] as $status => $label)
            @if ($subject->status !== $status)
            <form method="post" action="{{ route('admin.users.status', $subject) }}">@csrf<input type="hidden" name="status" value="{{ $status }}">
                <button type="submit" class="{{ $status === 'active' ? '' : 'ghost-danger' }}">{{ $label }}</button></form>
            @endif
        @endforeach
    </div>
    <div class="actions">
        <form method="post" action="{{ route('admin.users.sessions.revoke', $subject) }}">@csrf<button type="submit" class="secondary">Sign out everywhere</button></form>
        @if ($subject->hasMfaEnabled())
        <form method="post" action="{{ route('admin.users.mfa.reset', $subject) }}">@csrf<button type="submit" class="secondary">Reset 2-Step (lost phone)</button></form>
        @endif
    </div>
</section>

<section class="card">
    <h2>Roles</h2>
    <form method="post" action="{{ route('admin.users.roles', $subject) }}">
        @csrf
        @foreach ($roles as $role)
            <label class="check"><input type="checkbox" name="roles[]" value="{{ $role->name }}" @checked($subject->hasRole($role->name))> <span>{{ $role->label }}@if($role->is_admin) <span class="badge plain warn">Admin</span>@endif</span></label>
        @endforeach
        <p><button type="submit">Save roles</button></p>
    </form>
    <p class="hint">Only a super administrator can grant admin roles. Admins always need 2-Step Verification.</p>
</section>
</div>
@endif

<section class="card flush">
    <div class="card-head"><h2>Mailboxes</h2><span class="muted">Connection status only — administrators can’t read email.</span></div>
    <div class="scroll"><table>
        <thead><tr><th>Mailbox</th><th>Server</th><th>Status</th><th>Last sync</th><th>Last error</th><th></th></tr></thead>
        <tbody>
        @forelse ($accounts as $a)
            <tr>
                <td><b>{{ $a->email_address }}</b></td><td>{{ $a->providerProfile->label }}</td>
                <td>@if (! $a->hasAllowedDomain())<span class="badge bad">Domain removed</span>@else<span class="badge {{ in_array($a->status, ['active', 'syncing']) ? 'ok' : 'bad' }}">{{ ucfirst(str_replace('_', ' ', $a->status)) }}</span>@endif</td>
                <td class="muted">{{ $a->last_synced_at?->diffForHumans() ?? 'Never' }}</td>
                <td>@if ($a->last_error_code)<code>{{ $a->last_error_code }}</code>@else<span class="muted">—</span>@endif</td>
                <td class="num"><form method="post" action="{{ route('admin.users.accounts.status', [$subject, $a]) }}">@csrf
                    <input type="hidden" name="enabled" value="{{ $a->status === 'disabled' ? 1 : 0 }}">
                    <button type="submit" class="secondary sm">{{ $a->status === 'disabled' ? 'Enable' : 'Disable' }}</button></form></td>
            </tr>
        @empty
            <tr><td colspan="6"><div class="empty">@include('admin.partials.icon', ['name' => 'inbox'])No mailboxes linked yet.</div></td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

<section class="card flush">
    <div class="card-head"><h2>Signed-in devices</h2></div>
    <table><thead><tr><th>Device</th><th>Network</th><th>Since</th></tr></thead><tbody>
    @forelse ($sessions as $s)
        <tr><td><div class="person"><span class="avatar c6" aria-hidden="true">@include('admin.partials.icon', ['name' => 'devices', 'class' => 'sm'])</span><div><b>{{ $s->device?->name ?? 'Unknown device' }}</b><span>{{ ucfirst((string) $s->device?->platform) }}</span></div></div></td><td><code>{{ $s->ip_prefix }}</code></td><td class="muted">{{ $s->created_at?->diffForHumans() }}</td></tr>
    @empty
        <tr><td colspan="3"><div class="empty">@include('admin.partials.icon', ['name' => 'devices'])Not signed in on any device.</div></td></tr>
    @endforelse
    </tbody></table>
</section>
@endsection
