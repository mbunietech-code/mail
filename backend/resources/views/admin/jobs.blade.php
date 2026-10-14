@extends('admin.layout')
@section('title', 'Failed jobs')
@section('content')
<h1>Failed background jobs</h1>
<p class="muted">Only job type and error class are shown; payloads may contain personal data. Retrying a send job is safe: outgoing mail is protected against duplicate delivery.</p>
<div class="scroll"><table>
    <thead><tr><th>Failed</th><th>Queue</th><th>Job</th><th>Error</th><th></th></tr></thead>
    <tbody>
    @forelse ($jobs as $job)
        <tr>
            <td class="mono">{{ $job['failed_at'] }}</td><td>{{ $job['queue'] }}</td><td><code>{{ $job['job'] }}</code></td><td><code>{{ $job['error'] }}</code></td>
            <td class="actions">
                <form method="post" action="{{ route('admin.jobs.retry', $job['uuid']) }}">@csrf<button type="submit">Retry</button></form>
                <form method="post" action="{{ route('admin.jobs.forget', $job['uuid']) }}">@csrf<button type="submit" class="secondary">Discard</button></form>
            </td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">No failed jobs.</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection
