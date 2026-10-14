<?php

namespace App\Services\Admin;

use App\Models\EmailAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\MfaService;
use App\Services\Auth\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operational user administration (SRS FR-ADM-001..006). None of these operations give
 * access to mailbox content. Every action is audited with the acting administrator.
 */
class UserAdminService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly MfaService $mfa,
        private readonly AuditLogger $audit,
    ) {}

    public function setStatus(User $admin, User $target, string $status): void
    {
        $this->notSelf($admin, $target, 'You cannot change the status of your own account.');
        if (! in_array($status, [User::STATUS_ACTIVE, User::STATUS_SUSPENDED, User::STATUS_DEACTIVATED], true)) {
            throw ValidationException::withMessages(['status' => 'Unknown status.']);
        }
        if ($status !== User::STATUS_ACTIVE && $target->hasRole(Role::SUPER_ADMIN)) {
            $this->keepOneSuperAdmin($target);
        }

        DB::transaction(function () use ($target, $status) {
            $target->forceFill([
                'status' => $status,
                'deactivated_at' => $status === User::STATUS_DEACTIVATED ? now() : null,
            ])->save();

            if ($status !== User::STATUS_ACTIVE) {
                // Immediate cut-off: tokens, sessions and background mail access (FR-AUTH-005).
                $this->tokens->revokeAllForUser($target);
                DB::table('sessions')->where('user_id', $target->id)->delete();
                if ($status === User::STATUS_DEACTIVATED) {
                    $target->emailAccounts()->update(['status' => EmailAccount::STATUS_DISABLED, 'next_sync_at' => null]);
                }
            }
        });

        $this->audit->record('admin.user.status_changed', 'success', $admin, 'user', $target->id, ['status' => $status], 'admin');
    }

    /** @param list<string> $roleNames */
    public function setRoles(User $admin, User $target, array $roleNames): void
    {
        $this->notSelf($admin, $target, 'You cannot change your own roles.');

        $roles = Role::query()->whereIn('name', $roleNames)->get();
        if ($roles->count() !== count(array_unique($roleNames))) {
            throw ValidationException::withMessages(['roles' => 'Unknown role.']);
        }

        // Granting or removing any administrator role needs admins.manage (super admin).
        $before = $target->roles->pluck('name')->all();
        $adminChange = $roles->contains('is_admin', true) || $target->roles->contains('is_admin', true);
        if ($adminChange && ! $admin->hasPermission('admins.manage')) {
            throw ValidationException::withMessages(['roles' => 'Only a super administrator can grant or remove administrator roles.']);
        }
        if (in_array(Role::SUPER_ADMIN, $before, true) && ! $roles->contains('name', Role::SUPER_ADMIN)) {
            $this->keepOneSuperAdmin($target);
        }

        $target->roles()->sync($roles->pluck('id'));
        $target->unsetRelation('roles');

        // New privileges take effect on a fresh sign-in only.
        if ($roles->contains('is_admin', true) && ! $target->hasMfaEnabled()) {
            $this->tokens->revokeAllForUser($target);
        }

        $this->audit->record('admin.user.roles_changed', 'success', $admin, 'user', $target->id, [
            'before' => implode(',', $before),
            'after' => implode(',', $roles->pluck('name')->all()),
        ], 'admin');
    }

    public function revokeSessions(User $admin, User $target): int
    {
        $count = $this->tokens->revokeAllForUser($target);
        DB::table('sessions')->where('user_id', $target->id)->delete();
        $this->audit->record('admin.user.sessions_revoked', 'success', $admin, 'user', $target->id, ['count' => $count], 'admin');

        return $count;
    }

    /** For a lost authenticator: the user must enrol again at next sign-in. */
    public function resetMfa(User $admin, User $target): void
    {
        $this->notSelf($admin, $target, 'You cannot reset your own MFA here; use your recovery codes.');

        $this->mfa->disable($target);
        $this->tokens->revokeAllForUser($target);
        DB::table('sessions')->where('user_id', $target->id)->delete();
        $this->audit->record('admin.user.mfa_reset', 'success', $admin, 'user', $target->id, actorType: 'admin');
    }

    public function disableAccount(User $admin, EmailAccount $account): void
    {
        $account->forceFill(['status' => EmailAccount::STATUS_DISABLED, 'next_sync_at' => null])->save();
        $this->audit->record('admin.account.disabled', 'success', $admin, 'email_account', $account->id, ['owner' => $account->user_id], 'admin');
    }

    public function enableAccount(User $admin, EmailAccount $account): void
    {
        $account->forceFill(['status' => EmailAccount::STATUS_ACTIVE, 'next_sync_at' => now(), 'backoff_attempts' => 0, 'last_error_code' => null])->save();
        $this->audit->record('admin.account.enabled', 'success', $admin, 'email_account', $account->id, ['owner' => $account->user_id], 'admin');
    }

    private function notSelf(User $admin, User $target, string $message): void
    {
        if ($admin->id === $target->id) {
            throw ValidationException::withMessages(['user' => $message]);
        }
    }

    private function keepOneSuperAdmin(User $target): void
    {
        $others = User::query()->where('id', '!=', $target->id)->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles', fn ($q) => $q->where('name', Role::SUPER_ADMIN))->exists();
        if (! $others) {
            throw ValidationException::withMessages(['user' => 'At least one active super administrator must remain.']);
        }
    }
}
