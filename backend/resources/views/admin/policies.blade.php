@extends('admin.layout')
@section('title', 'Policies')
@section('content')
<h1>Security policies</h1>
<form method="post" action="{{ route('admin.policies.update') }}" class="card">
    @csrf
    <h2>Two-step verification</h2>
    <label><input type="radio" name="mfa_required_for_all" value="1" @checked($mfaRequired)> Required for every employee</label>
    <label><input type="radio" name="mfa_required_for_all" value="0" @checked(! $mfaRequired)> Required for administrators only (always on for admins)</label>

    <h2>Risky attachments (.exe, .js, .scr, …)</h2>
    <label><input type="radio" name="risky_extension_policy" value="warn" @checked($riskyPolicy === 'warn')> Warn the employee</label>
    <label><input type="radio" name="risky_extension_policy" value="block" @checked($riskyPolicy === 'block')> Block uploads</label>

    <h2>Company email domains</h2>
    <label for="domains">Domains allowed for sign-in, invitations and mailbox linking (comma or space separated)</label>
    <input id="domains" type="text" name="domains" value="{{ old('domains', implode(', ', $domains)) }}" required>
    <p class="muted">Mail from other domains is marked “external” in the app.</p>

    <p><button type="submit">Save policies</button></p>
</form>
@endsection
