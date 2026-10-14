<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /** role => [label, is_admin, permissions] — no role is granted mail content access. */
    public const ROLES = [
        Role::EMPLOYEE => ['Employee', false, []],
        Role::ADMIN => ['Administrator', true, ['users.manage', 'audit.read', 'health.read', 'jobs.manage']],
        Role::SUPER_ADMIN => ['Super administrator', true, ['users.manage', 'admins.manage', 'policies.manage', 'audit.read', 'health.read', 'jobs.manage']],
        Role::SECURITY_AUDITOR => ['Security auditor', true, ['audit.read', 'health.read']],
        Role::BREAK_GLASS_APPROVER => ['Break-glass approver', true, ['breakglass.approve']],
    ];

    public function run(): void
    {
        foreach (Permission::ALL as $name => $label) {
            Permission::query()->updateOrCreate(['name' => $name], ['label' => $label]);
        }

        foreach (self::ROLES as $name => [$label, $isAdmin, $permissions]) {
            $role = Role::query()->updateOrCreate(['name' => $name], ['label' => $label, 'is_admin' => $isAdmin]);
            $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        }
    }
}
