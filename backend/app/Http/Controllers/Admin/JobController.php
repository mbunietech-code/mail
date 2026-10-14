<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Failed background jobs (FR-ADM-009). Shows the job type and exception class only —
 * payloads and messages can contain addresses or provider text, so they are not shown.
 */
class JobController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $jobs = DB::table('failed_jobs')->orderByDesc('failed_at')->limit(200)->get()->map(fn ($j) => [
            'uuid' => $j->uuid,
            'queue' => $j->queue,
            'job' => class_basename((string) (json_decode((string) $j->payload, true)['displayName'] ?? 'unknown')),
            'error' => strtok((string) $j->exception, ':') ?: 'unknown',
            'failed_at' => $j->failed_at,
        ]);

        return view('admin.jobs', ['jobs' => $jobs]);
    }

    public function retry(Request $request, string $uuid): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->audit->record('admin.job.retried', 'success', $request->user(), 'failed_job', null, ['uuid' => $uuid], 'admin');

        return back()->with('status', 'Job queued for retry.');
    }

    public function forget(Request $request, string $uuid): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->delete() === 1, 404);
        $this->audit->record('admin.job.discarded', 'success', $request->user(), 'failed_job', null, ['uuid' => $uuid], 'admin');

        return back()->with('status', 'Failed job discarded.');
    }
}
