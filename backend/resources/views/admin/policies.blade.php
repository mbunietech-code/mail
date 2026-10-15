@extends('admin.layout')
@section('title', 'Security')
@section('content')
<div class="head"><div><h1>Security policies</h1><p class="lead">Apply to every employee. Changes are recorded in the audit log.</p></div></div>
<form method="post" action="{{ route('admin.policies.update') }}">
    @csrf
    <section class="card">
        <h2>2-Step Verification</h2>
        <p class="muted">Administrators always need it.</p>
        <label class="option"><input type="radio" name="mfa_required_for_all" value="1" @checked($mfaRequired)><span><b>Required for everyone</b><span>Employees set up an authenticator app at their next sign-in.</span></span></label>
        <label class="option"><input type="radio" name="mfa_required_for_all" value="0" @checked(! $mfaRequired)><span><b>Administrators only</b><span>Employees may turn it on themselves.</span></span></label>
    </section>
    <section class="card">
        <h2>Risky attachments</h2>
        <p class="muted">Programs and scripts such as .exe, .js, .scr, .bat, .ps1 and .iso.</p>
        <label class="option"><input type="radio" name="risky_extension_policy" value="warn" @checked($riskyPolicy === 'warn')><span><b>Warn</b><span>The employee sees a warning before sending.</span></span></label>
        <label class="option"><input type="radio" name="risky_extension_policy" value="block" @checked($riskyPolicy === 'block')><span><b>Block</b><span>These files can’t be attached.</span></span></label>
    </section>
    <section class="card">
        <h2>Company domains</h2>
        <p class="muted">Domains now have their own page. <a href="{{ route('admin.domains.index') }}">Manage domains →</a></p>
    </section>
    <p><button type="submit">Save policies</button></p>
</form>
@endsection
