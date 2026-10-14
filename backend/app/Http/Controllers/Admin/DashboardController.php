<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailAccount;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\View\View;
use Throwable;

/** Service health and sync status (FR-ADM-007). Aggregates only — no mail content. */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $checks = [
            'database' => self::check(fn () => DB::select('SELECT 1')),
            'cache' => self::check(fn () => cache()->put('health-probe', 1, 10)),
            'redis' => config('queue.default') === 'redis' || config('cache.default') === 'redis' ? self::check(fn () => Redis::connection()->ping()) : 'not used',
        ];

        $queues = [];
        foreach (['send', 'sync', 'default'] as $queue) {
            $queues[$queue] = rescue(fn () => Queue::size($queue), null, false);
        }

        $dueOverdue = EmailAccount::query()->whereIn('status', ['active', 'syncing'])->where('next_sync_at', '<', now()->subMinutes(10))->count();

        return view('admin.dashboard', [
            'checks' => $checks,
            'queues' => $queues,
            'failedJobs' => DB::table('failed_jobs')->count(),
            'users' => User::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
            'accounts' => EmailAccount::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
            'syncLagging' => $dueOverdue,
            'outbox' => OutboxMessage::query()->where('kind', 'send')->where('updated_at', '>', now()->subDay())->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
            'adminsWithoutMfa' => User::query()->where('status', 'active')->whereNull('mfa_confirmed_at')->whereHas('roles', fn ($q) => $q->where('is_admin', true))->count(),
        ]);
    }

    private static function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'failing';
        }
    }
}
