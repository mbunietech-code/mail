<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Operational permissions. By design no permission grants access to mail content.
 *
 * @property int $id
 * @property string $name
 */
#[Fillable(['name', 'label'])]
class Permission extends Model
{
    public const ALL = [
        'users.manage' => 'Invite, suspend, deactivate users and revoke their sessions',
        'admins.manage' => 'Grant or remove administrator roles',
        'policies.manage' => 'Change security policies and provider profiles',
        'audit.read' => 'Read and export the audit log',
        'health.read' => 'View service health and sync status',
        'jobs.manage' => 'Inspect and retry failed background jobs',
        'breakglass.approve' => 'Approve exceptional mailbox access requests',
    ];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
