<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InvitationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Creates a single-use invitation (only its hash is stored) and emails the link.
     */
    public function invite(string $email, Role $role, ?User $invitedBy): Invitation
    {
        $email = strtolower(trim($email));
        $org = Organization::query()->firstOrFail();

        if (! $org->allowsEmail($email)) {
            throw new ApiException('DOMAIN_NOT_ALLOWED', 422, 'This email domain is not allowed.');
        }
        if (User::query()->where('email', $email)->exists()) {
            throw new ApiException('USER_EXISTS', 409, 'A user with this email already exists.');
        }

        // Supersede earlier pending invitations for the same address.
        Invitation::query()->where('email', $email)->whereNull('accepted_at')->update(['expires_at' => now()]);

        $token = Str::random(64);
        $invitation = Invitation::query()->create([
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'role_id' => $role->id,
            'invited_by' => $invitedBy?->id,
            'expires_at' => now()->addHours((int) config('mbuniemail.invitations.ttl_hours')),
        ]);

        Notification::route('mail', $email)->notify(new InvitationNotification($token, $invitation->expires_at));
        $this->audit->record('admin.user.invited', 'success', $invitedBy, 'invitation', $invitation->id, ['role' => $role->name], $invitedBy ? 'admin' : 'system');

        return $invitation;
    }
}
