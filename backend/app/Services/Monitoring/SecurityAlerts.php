<?php

namespace App\Services\Monitoring;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Detective controls (SDD §11, risks R-01, R-03, R-24): evaluates recent audit and
 * operational signals and alerts the security owner. Alerts carry counts, action
 * names and record ids only — never message content or credentials. Each condition
 * alerts at most once per window (deduplicated).
 */
class SecurityAlerts
{
    /** @return list<array{key: string, severity: string, summary: string}> */
    public function evaluate(int $windowMinutes = 10): array
    {
        $since = now()->subMinutes($windowMinutes);
        $count = fn (string $action, ?string $result = null) => AuditLog::query()
            ->where('occurred_at', '>=', $since)->where('action', $action)
            ->when($result, fn ($q) => $q->where('result', $result))->count();

        $alerts = [];

        $failedLogins = $count('auth.login.failed') + $count('admin.login.failed');
        if ($failedLogins >= (int) config('mbuniemail.alerts.failed_login_threshold')) {
            $alerts[] = ['key' => 'failed-logins', 'severity' => 'high', 'summary' => "{$failedLogins} failed sign-ins in {$windowMinutes} minutes (possible credential stuffing)."];
        }

        if (($reuse = $count('security.refresh_reuse')) > 0) {
            $alerts[] = ['key' => 'refresh-reuse', 'severity' => 'high', 'summary' => "{$reuse} refresh-token reuse event(s): a session token may have been stolen; the affected sessions were revoked."];
        }

        foreach (['admin.user.mfa_reset' => 'MFA reset(s) performed by an administrator', 'admin.user.roles_changed' => 'role change(s)', 'admin.policy.changed' => 'security policy change(s)', 'admin.provider_profile.changed' => 'mail server setting change(s)', 'security.kek.rotated' => 'credential key rotation(s)'] as $action => $label) {
            $logs = AuditLog::query()->where('occurred_at', '>=', $since)->where('action', $action)->get(['id']);
            if ($logs->isNotEmpty()) {
                $alerts[] = ['key' => 'admin-'.$action, 'severity' => 'medium', 'summary' => $logs->count()." {$label} (audit #".$logs->pluck('id')->implode(', #').'). Confirm they were authorised.'];
            }
        }

        if (($denied = AuditLog::query()->where('occurred_at', '>=', $since)->where('result', 'denied')->count()) >= 20) {
            $alerts[] = ['key' => 'denied', 'severity' => 'medium', 'summary' => "{$denied} denied actions in {$windowMinutes} minutes."];
        }

        if (($sendUnknown = $count('mail.send.unknown_outcome')) >= 5) {
            $alerts[] = ['key' => 'send-unknown', 'severity' => 'medium', 'summary' => "{$sendUnknown} sends with unknown outcome: check the mail provider."];
        }

        $failedJobs = DB::table('failed_jobs')->where('failed_at', '>=', $since)->count();
        if ($failedJobs >= 10) {
            $alerts[] = ['key' => 'failed-jobs', 'severity' => 'medium', 'summary' => "{$failedJobs} background jobs failed in {$windowMinutes} minutes."];
        }

        $authFailures = $count('account.sync.auth_failed');
        if ($authFailures >= 5) {
            $alerts[] = ['key' => 'mailbox-auth', 'severity' => 'medium', 'summary' => "{$authFailures} mailboxes started failing authentication (password changes, or provider problem)."];
        }

        return $alerts;
    }

    /**
     * @param  list<array{key: string, severity: string, summary: string}>  $alerts
     * @return int number of alerts actually sent (after deduplication)
     */
    public function dispatch(array $alerts, int $dedupeMinutes = 30): int
    {
        $fresh = array_values(array_filter($alerts, fn (array $a) => Cache::add('security-alert:'.$a['key'], 1, now()->addMinutes($dedupeMinutes))));
        if ($fresh === []) {
            return 0;
        }

        foreach ($fresh as $alert) {
            Log::channel(config('mbuniemail.alerts.log_channel'))->critical('security_alert', ['alert' => $alert['key'], 'severity' => $alert['severity'], 'summary' => $alert['summary']]);
        }

        $to = (string) config('mbuniemail.alerts.email');
        if ($to !== '') {
            $body = 'MbunieMail security alerts ('.now()->toIso8601String().")\n\n"
                .implode("\n", array_map(fn ($a) => '['.strtoupper($a['severity']).'] '.$a['summary'], $fresh))
                ."\n\nReview: /admin/audit. Procedures: docs/INCIDENT_RESPONSE.md.";
            Mail::raw($body, fn ($m) => $m->to($to)->subject('[MbunieMail] '.count($fresh).' security alert(s)'));
        }

        return count($fresh);
    }
}
