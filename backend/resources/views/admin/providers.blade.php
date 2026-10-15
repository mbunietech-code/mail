@extends('admin.layout')
@section('title', 'Mail servers')
@section('content')
<div class="head"><div><h1>Mail servers</h1>
<p class="lead">Where company mailboxes live. Confirm the values in Hostinger hPanel. Connections always use verified TLS; unencrypted connections are not possible.</p></div></div>
@foreach ($profiles as $p)
<section class="card">
    <div class="head">
        <div class="person"><span class="avatar c6" aria-hidden="true">@include('admin.partials.icon', ['name' => 'server', 'class' => 'sm'])</span>
            <div><h2>{{ $p->label }}</h2><span class="muted">{{ $p->domains ? implode(', ', array_map(fn ($d) => '@'.$d, $p->domains)) : 'No domains assigned' }} · <a href="{{ route('admin.domains.index') }}">Assign domains</a></span></div></div>
        <span class="badge {{ $p->enabled ? 'ok' : 'neutral' }}">{{ $p->enabled ? 'Enabled' : 'Disabled' }}</span>
    </div>
    @if ($p->driver === 'imap_smtp')
    <form method="post" action="{{ route('admin.providers.update', $p) }}">
        @csrf
        <h3>Incoming (IMAP)</h3>
        <div class="inline">
            <div><label for="imap_host-{{ $p->id }}">Host</label><input id="imap_host-{{ $p->id }}" type="text" name="imap_host" value="{{ old('imap_host', $p->imap_host) }}" required></div>
            <div><label for="imap_port-{{ $p->id }}">Port</label><input id="imap_port-{{ $p->id }}" type="number" name="imap_port" value="{{ old('imap_port', $p->imap_port) }}" required></div>
            <div><label for="imap_enc-{{ $p->id }}">Security</label><select id="imap_enc-{{ $p->id }}" name="imap_encryption">
                @foreach (['ssl' => 'SSL/TLS', 'starttls' => 'STARTTLS (required)'] as $v => $l)<option value="{{ $v }}" @selected($p->imap_encryption === $v)>{{ $l }}</option>@endforeach</select></div>
        </div>
        <h3>Outgoing (SMTP)</h3>
        <div class="inline">
            <div><label for="smtp_host-{{ $p->id }}">Host</label><input id="smtp_host-{{ $p->id }}" type="text" name="smtp_host" value="{{ old('smtp_host', $p->smtp_host) }}" required></div>
            <div><label for="smtp_port-{{ $p->id }}">Port</label><input id="smtp_port-{{ $p->id }}" type="number" name="smtp_port" value="{{ old('smtp_port', $p->smtp_port) }}" required></div>
            <div><label for="smtp_enc-{{ $p->id }}">Security</label><select id="smtp_enc-{{ $p->id }}" name="smtp_encryption">
                @foreach (['ssl' => 'SSL/TLS', 'starttls' => 'STARTTLS (required)'] as $v => $l)<option value="{{ $v }}" @selected($p->smtp_encryption === $v)>{{ $l }}</option>@endforeach</select></div>
        </div>
        <div class="inline">
            <div><label for="sent-{{ $p->id }}">Sent folder</label>
            <select id="sent-{{ $p->id }}" name="saves_sent_automatically"><option value="0" @selected(! $p->saves_sent_automatically)>MbunieMail saves a copy to Sent</option><option value="1" @selected($p->saves_sent_automatically)>Server saves sent mail itself</option></select></div>
            <div><label for="enabled-{{ $p->id }}">Status</label>
            <select id="enabled-{{ $p->id }}" name="enabled"><option value="1" @selected($p->enabled)>Enabled</option><option value="0" @selected(! $p->enabled)>Disabled</option></select></div>
        </div>
        <p><button type="submit">Save changes</button></p>
    </form>
    @else
        <p class="muted">Configured through Google OAuth settings in the server environment (GMAIL_ENABLED, GOOGLE_*). Only Google Workspace addresses on company domains can be linked.</p>
    @endif
</section>
@endforeach
@endsection
