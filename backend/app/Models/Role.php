<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string $label
 * @property bool $is_admin
 */
#[Fillable(['name', 'label', 'is_admin'])]
class Role extends Model
{
    public const EMPLOYEE = 'employee';

    public const ADMIN = 'admin';

    public const SUPER_ADMIN = 'super_admin';

    public const SECURITY_AUDITOR = 'security_auditor';

    public const BREAK_GLASS_APPROVER = 'break_glass_approver';

    protected function casts(): array
    {
        return ['is_admin' => 'boolean'];
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
