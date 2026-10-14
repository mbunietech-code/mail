@extends('admin.layout')
@section('title', 'Mail servers')
@section('content')
<h1>Mail servers</h1>
<p class="notice">Confirm these values in Hostinger hPanel. Connections always use verified TLS; plain-text connections are not possible.</p>
@foreach ($profiles as $p)
<div class="card">
    <h2>{{ $p->label }} <span class="badge {{ $p->enabled ? 'ok' : '' }}">{{ $p->enabled ? 'enabled' : 'disabled' }}</span></h2>
    @if ($p->driver === 'imap_smtp')
    <form method="post" action="{{ route('admin.providers.update', $p) }}">
        @csrf
        <div class="inline">
            <div><label for="imap_host-{{ $p->id }}">IMAP host</label><input id="imap_host-{{ $p->id }}" type="text" name="imap_host" value="{{ old('imap_host', $p->imap_host) }}" required></div>
            <div><label for="imap_port-{{ $p->id }}">IMAP port</label><input id="imap_port-{{ $p->id }}" type="number" name="imap_port" value="{{ old('imap_port', $p->imap_port) }}" required></div>
            <div><label for="imap_enc-{{ $p->id }}">IMAP security</label><select id="imap_enc-{{ $p->id }}" name="imap_encryption">
                @foreach (['ssl' => 'SSL/TLS', 'starttls' => 'STARTTLS (required)'] as $v => $l)<option value="{{ $v }}" @selected($p->imap_encryption === $v)>{{ $l }}</option>@endforeach</select></div>
        </div>
        <div class="inline">
            <div><label for="smtp_host-{{ $p->id }}">SMTP host</label><input id="smtp_host-{{ $p->id }}" type="text" name="smtp_host" value="{{ old('smtp_host', $p->smtp_host) }}" required></div>
            <div><label for="smtp_port-{{ $p->id }}">SMTP port</label><input id="smtp_port-{{ $p->id }}" type="number" name="smtp_port" value="{{ old('smtp_port', $p->smtp_port) }}" required></div>
            <div><label for="smtp_enc-{{ $p->id }}">SMTP security</label><select id="smtp_enc-{{ $p->id }}" name="smtp_encryption">
                @foreach (['ssl' => 'SSL/TLS', 'starttls' => 'STARTTLS (required)'] as $v => $l)<option value="{{ $v }}" @selected($p->smtp_encryption === $v)>{{ $l }}</option>@endforeach</select></div>
        </div>
        <label for="sent-{{ $p->id }}">Server saves sent mail automatically</label>
        <select id="sent-{{ $p->id }}" name="saves_sent_automatically"><option value="0" @selected(! $p->saves_sent_automatically)>No — MbunieMail copies to Sent</option><option value="1" @selected($p->saves_sent_automatically)>Yes</option></select>
        <label for="enabled-{{ $p->id }}">Status</label>
        <select id="enabled-{{ $p->id }}" name="enabled"><option value="1" @selected($p->enabled)>Enabled</option><option value="0" @selected(! $p->enabled)>Disabled</option></select>
        <p class="muted">Domains: {{ implode(', ', $p->domains) }}</p>
        <p><button type="submit">Save</button></p>
    </form>
    @else
        <p class="muted">Configured through Google OAuth settings in the server environment (GMAIL_ENABLED, GOOGLE_*).</p>
    @endif
</div>
@endforeach
@endsection
