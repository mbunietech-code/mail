@extends('admin.layout')
@section('title', 'Failed jobs')
@section('content')
<div class="head"><div><h1>Failed background jobs</h1>
<p class="lead">Only the job type and error are shown, because payloads may contain personal data. Retrying a send is safe: duplicate delivery is prevented.</p></div></div>
<section class="card flush">
    <div class="scroll"><table>
        <thead><tr><th>Failed</th><th>Queue</th><th>Job</th><th>Error</th><th></th></tr></thead>
        <tbody>
        @forelse ($jobs as $job)
            <tr>
                <td class="mono">{{ $job['failed_at'] }}</td><td>{{ $job['queue'] }}</td><td><code>{{ $job['job'] }}</code></td><td><code>{{ $job['error'] }}</code></td>
                <td class="num"><div class="actions">
                    <form method="post" action="{{ route('admin.jobs.retry', $job['uuid']) }}">@csrf<button type="submit" class="sm">Retry</button></form>
                    <form method="post" action="{{ route('admin.jobs.forget', $job['uuid']) }}">@csrf<button type="submit" class="secondary sm">Discard</button></form>
                </div></td>
            </tr>
        @empty
            <tr><td colspan="5"><div class="empty">@include('admin.partials.icon', ['name' => 'check'])All clear — no failed jobs.</div></td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
