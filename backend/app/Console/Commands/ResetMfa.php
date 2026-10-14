<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\MfaService;
use App\Services\Auth\TokenService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Server-console MFA reset for when no other administrator can do it in the panel
 * (OPERATIONS_RUNBOOK §4). Audited like the panel action; signs the user out everywhere.
 */
#[Signature('mbuniemail:reset-mfa {email} {--reason= : Ticket or reason, recorded in the audit log}')]
#[Description('Reset a user\'s MFA from the server console (two-person rule applies)')]
class ResetMfa extends Command
{
    public function handle(MfaService $mfa, TokenService $tokens, AuditLogger $audit): int
    {
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->error('No such user.');

            return self::FAILURE;
        }
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('A --reason (ticket reference) is required.');

            return self::FAILURE;
        }
        if (! $this->confirm("Reset MFA for {$user->email} and sign them out everywhere?")) {
            return self::FAILURE;
        }

        $mfa->disable($user);
        $tokens->revokeAllForUser($user);
        $audit->record('admin.user.mfa_reset', 'success', null, 'user', $user->id, ['via' => 'console', 'reason' => mb_substr($reason, 0, 120)], 'system');

        $this->info('MFA reset. The user must enrol again at next sign-in.');

        return self::SUCCESS;
    }
}
