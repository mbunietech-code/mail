@extends('admin.layout')
@section('title', 'Employees')
@section('content')
<div class="head"><div><h1>Employees</h1><p class="lead">Invite people, manage access and see mailbox connection health.</p></div></div>

<section class="card" id="invite">
    <h2>Invite an employee</h2>
    <p class="muted">They get a one-time link (valid 72 hours) to choose a password. Only {{ implode(', ', array_map(fn ($d) => '@'.$d, $domains)) }} addresses can be invited.</p>
    <form method="post" action="{{ route('admin.invitations.store') }}" class="inline">
        @csrf
        <div><label for="invite-email">Work email</label><input id="invite-email" type="email" name="email" value="{{ old('email') }}" placeholder="name@{{ $domains[0] ?? 'company.com' }}" required></div>
        <div><label for="invite-role">Role</label>
            <select id="invite-role" name="role">@foreach ($roles as $role)<option value="{{ $role->name }}" @selected($role->name === 'employee')>{{ $role->label }}</option>@endforeach</select></div>
        <button type="submit">@include('admin.partials.icon', ['name' => 'send'])Send invitation</button>
    </form>
</section>

<section class="card flush">
    <form method="get" class="toolbar">
        <div class="search"><label for="q" class="sr-only">Search</label>@include('admin.partials.icon', ['name' => 'search'])<input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Search by name or email"></div>
        <div><label for="domain" class="sr-only">Domain</label><select id="domain" name="domain"><option value="">All domains</option>
            @foreach ($domains as $d)<option value="{{ $d }}" @selected(($filters['domain'] ?? '') === $d)>@ {{ $d }}</option>@endforeach</select></div>
        <div><label for="status" class="sr-only">Status</label><select id="status" name="status"><option value="">Any status</option>
            @foreach (['active', 'suspended', 'deactivated'] as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst($s) }}</option>@endforeach</select></div>
        <button type="submit" class="secondary">Filter</button>
    </form>
    <div class="scroll"><table>
        <thead><tr><th>Employee</th><th>Roles</th><th>Status</th><th>2-Step</th><th>Last sign-in</th></tr></thead>
        <tbody>
        @forelse ($users as $u)
            <tr>
                <td><a class="person" href="{{ route('admin.users.show', $u) }}">@include('admin.partials.avatar', ['name' => $u->name, 'email' => $u->email])<div><b>{{ $u->name }}</b><span>{{ $u->email }}</span></div></a></td>
                <td>@forelse ($u->roles as $r)<span class="badge plain {{ $r->is_admin ? 'warn' : 'neutral' }}">{{ $r->label }}</span> @empty<span class="muted">—</span>@endforelse</td>
                <td>@if (! $u->hasAllowedDomain())<span class="badge bad">Domain removed</span>@else<span class="badge {{ $u->status === 'active' ? 'ok' : 'bad' }}">{{ ucfirst($u->status) }}</span>@endif</td>
                <td>@if ($u->hasMfaEnabled())<span class="badge ok">On</span>@else<span class="badge neutral">Off</span>@endif</td>
                <td class="muted">{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
            </tr>
        @empty
            <tr><td colspan="5"><div class="empty">@include('admin.partials.icon', ['name' => 'people'])No employees match these filters.</div></td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $users->links('admin.pagination') }}
</section>
@endsection
