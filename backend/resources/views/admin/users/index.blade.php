@extends('admin.layout')
@section('title', 'Employees')
@section('content')
<h1>Employees</h1>
<div class="card">
    <h2>Invite an employee</h2>
    <form method="post" action="{{ route('admin.invitations.store') }}" class="inline">
        @csrf
        <div><label for="invite-email">Company email</label><input id="invite-email" type="email" name="email" value="{{ old('email') }}" required></div>
        <div><label for="invite-role">Role</label>
            <select id="invite-role" name="role">@foreach ($roles as $role)<option value="{{ $role->name }}" @selected($role->name === 'employee')>{{ $role->label }}</option>@endforeach</select></div>
        <button type="submit">Send invitation</button>
    </form>
    <p class="muted">The invitation link expires in 72 hours and works once. Invitations are limited to the company domains.</p>
</div>
<form method="get" class="inline card">
    <div><label for="q">Search</label><input id="q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100"></div>
    <div><label for="status">Status</label><select id="status" name="status"><option value="">Any</option>
        @foreach (['active', 'suspended', 'deactivated'] as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ $s }}</option>@endforeach</select></div>
    <button type="submit" class="secondary">Filter</button>
</form>
<div class="scroll"><table>
    <thead><tr><th>Name</th><th>Email</th><th>Roles</th><th>Status</th><th>MFA</th><th>Last sign-in</th></tr></thead>
    <tbody>
    @forelse ($users as $u)
        <tr>
            <td><a href="{{ route('admin.users.show', $u) }}">{{ $u->name }}</a></td>
            <td>{{ $u->email }}</td>
            <td>{{ $u->roles->pluck('name')->implode(', ') ?: '—' }}</td>
            <td><span class="badge {{ $u->status === 'active' ? 'ok' : 'bad' }}">{{ $u->status }}</span></td>
            <td>{{ $u->hasMfaEnabled() ? 'on' : 'off' }}</td>
            <td class="muted">{{ $u->last_login_at?->diffForHumans() ?? 'never' }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">No employees match.</td></tr>
    @endforelse
    </tbody>
</table></div>
{{ $users->links('admin.pagination') }}
@endsection
