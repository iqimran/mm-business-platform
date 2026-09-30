<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Default roles. These are editable data, not hard-coded authorization rules.
 * Idempotent: default grants are applied only when a role is first created,
 * so administrator changes are preserved. Super Admin always holds every permission.
 */
class RoleSeeder extends Seeder
{
    public const SUPER_ADMIN = 'Super Admin';

    private const DEFAULT_GRANTS = [
        'Admin' => [
            'user.view', 'user.create', 'user.update',
            'role.view',
            'branch.view', 'branch.create', 'branch.update',
            'setting.view',
            'audit.view',
        ],
        'General User' => [],
    ];

    public function run(): void
    {
        $superAdmin = Role::firstOrCreate(
            ['name' => self::SUPER_ADMIN],
            ['description' => 'Full access to all permissions.', 'is_system' => true],
        );
        $superAdmin->permissions()->sync(Permission::pluck('id'));

        foreach (self::DEFAULT_GRANTS as $name => $permissions) {
            $role = Role::firstOrCreate(['name' => $name], ['is_system' => true]);

            if ($role->wasRecentlyCreated) {
                $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
            }
        }
    }
}
