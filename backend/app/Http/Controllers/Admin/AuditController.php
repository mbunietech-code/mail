<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Audit log review and export (FR-ADM-005). Read-only; the log is append-only. */
class AuditController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $logs = $this->filtered($request)->orderByDesc('id')->paginate(100)->withQueryString();
        $actors = User::query()->whereIn('id', $logs->pluck('actor_user_id')->filter()->unique())->pluck('email', 'id');

        return view('admin.audit.index', ['logs' => $logs, 'actors' => $actors, 'filters' => $request->only(['action', 'actor', 'result', 'from', 'to'])]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->orderBy('id');
        $this->audit->record('admin.audit.exported', 'success', $request->user(), context: array_filter($request->only(['action', 'result', 'from', 'to'])), actorType: 'admin');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'occurred_at', 'actor_user_id', 'actor_type', 'action', 'target_type', 'target_id', 'result', 'ip', 'context', 'hash'], escape: '');
            $query->chunk(1000, function ($logs) use ($out) {
                foreach ($logs as $log) {
                    fputcsv($out, array_map([self::class, 'csvSafe'], [
                        $log->id, $log->occurred_at->format('Y-m-d H:i:s.u'), $log->actor_user_id, $log->actor_type, $log->action,
                        $log->target_type, $log->target_id, $log->result, $log->ipAddress(), json_encode($log->context), $log->hash,
                    ]), escape: '');
                }
            });
            fclose($out);
        }, 'mbuniemail-audit-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function verify(Request $request): RedirectResponse
    {
        $broken = $this->audit->verify();
        $this->audit->record('admin.audit.verified', $broken === null ? 'success' : 'failure', $request->user(), context: ['broken_at' => $broken], actorType: 'admin');

        return back()->with($broken === null ? 'status' : 'error', $broken === null ? 'Audit chain verified: intact.' : "Audit chain BROKEN at entry #{$broken}. Follow the incident response procedure.");
    }

    /** @return Builder<AuditLog> */
    private function filtered(Request $request): Builder
    {
        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.]+$/'],
            'actor' => ['nullable', 'email', 'max:254'],
            'result' => ['nullable', 'in:success,failure,denied'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return AuditLog::query()
            ->when($data['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', addcslashes($a, '%_').'%'))
            ->when($data['actor'] ?? null, fn ($q, $e) => $q->where('actor_user_id', User::query()->where('email', strtolower($e))->value('id') ?? '-'))
            ->when($data['result'] ?? null, fn ($q, $r) => $q->where('result', $r))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('occurred_at', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->where('occurred_at', '<', Carbon::parse($d)->addDay()));
    }

    /** Neutralises spreadsheet formula injection (=, +, -, @ prefixes). */
    public static function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
