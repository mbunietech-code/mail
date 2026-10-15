@extends('admin.layout')
@section('title', 'Domains')
@section('content')
<div class="head">
    <div><h1>Company domains</h1>
    <p class="lead">Only addresses on these domains can sign in, be invited, or link a mailbox in MbunieMail. Mail from any other domain is marked “external”.</p></div>
</div>

<section class="card">
    <h2>Add a domain</h2>
    <p class="muted">Use the part after the @, for example <code>mbuniehub.com</code>. Choose the mail server that hosts its mailboxes.</p>
    <form method="post" action="{{ route('admin.domains.store') }}" class="inline">
        @csrf
        <div><label for="domain">Domain</label><input id="domain" type="text" name="domain" value="{{ old('domain') }}" placeholder="example.com" autocomplete="off" spellcheck="false" required></div>
        <div><label for="server">Mail server</label>
            <select id="server" name="server">
                @foreach ($servers as $s)<option value="{{ $s->id }}" @selected(old('server') === $s->id)>{{ $s->label }}{{ $s->enabled ? '' : ' (disabled)' }}</option>@endforeach
                <option value="" @selected(old('server') === '')>None yet</option>
            </select></div>
        <button type="submit">@include('admin.partials.icon', ['name' => 'add'])Add domain</button>
    </form>
</section>

<section class="card flush">
    <div class="card-head"><h2>{{ count($rows) }} {{ \Illuminate\Support\Str::plural('domain', count($rows)) }}</h2></div>
    <div class="scroll"><table>
        <thead><tr><th>Domain</th><th class="num">Employees</th><th class="num">Mailboxes</th><th>Mail server</th><th></th></tr></thead>
        <tbody>
        @foreach ($rows as $r)
            @php($mine = \App\Support\CompanyDomains::domainOf(auth('web')->user()->email) === $r['domain'])
            <tr>
                <td><div class="person"><span class="avatar c{{ abs(crc32($r['domain'])) % 6 + 1 }}" aria-hidden="true">@include('admin.partials.icon', ['name' => 'globe', 'class' => 'sm'])</span>
                    <div><b>{{ $r['domain'] }}</b><span>{{ $mine ? 'Your domain' : 'name@'.$r['domain'] }}</span></div></div></td>
                <td class="num">{{ $r['active_users'] }}@if ($r['users'] > $r['active_users']) <span class="muted">/ {{ $r['users'] }}</span>@endif</td>
                <td class="num">{{ $r['mailboxes'] }}</td>
                <td>
                    <form method="post" action="{{ route('admin.domains.server', $r['domain']) }}" class="actions">
                        @csrf
                        <label for="srv-{{ $loop->index }}" class="sr-only">Mail server for {{ $r['domain'] }}</label>
                        <select id="srv-{{ $loop->index }}" name="server">
                            @unless ($r['server'])<option value="" selected disabled>Choose…</option>@endunless
                            @foreach ($servers as $s)<option value="{{ $s->id }}" @selected($r['server']?->is($s))>{{ $s->label }}</option>@endforeach
                        </select>
                        <button type="submit" class="secondary sm">Save</button>
                    </form>
                    @unless ($r['server'])<span class="badge warn">Mailboxes can’t be linked yet</span>@endunless
                </td>
                <td class="num">
                    @if ($mine)
                        <span class="muted">—</span>
                    @else
                    <details class="inline-confirm">
                        <summary class="button ghost-danger sm">@include('admin.partials.icon', ['name' => 'delete', 'class' => 'sm'])Remove</summary>
                        <div class="pop">
                            <h3>Remove {{ $r['domain'] }}?</h3>
                            @if ($r['users'] > 0)
                                <p><b>{{ $r['users'] }} {{ \Illuminate\Support\Str::plural('employee', $r['users']) }}</b> will be signed out and blocked immediately, and {{ $r['mailboxes'] }} {{ \Illuminate\Support\Str::plural('mailbox', $r['mailboxes']) }} will stop syncing. Adding the domain back restores access.</p>
                            @else
                                <p>No employees use this domain.</p>
                            @endif
                            <form method="post" action="{{ route('admin.domains.destroy', $r['domain']) }}">
                                @csrf
                                <label class="check"><input type="checkbox" name="confirm" value="1" required> I understand</label>
                                <button type="submit" class="danger block">Remove domain</button>
                            </form>
                        </div>
                    </details>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</section>
<p class="muted">Each domain also needs correct DNS (MX, SPF, DKIM, DMARC) at your host. Check with <code>infra/scripts/check-dns.sh &lt;domain&gt;</code>.</p>
@endsection
