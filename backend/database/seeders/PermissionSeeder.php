<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Foundation permissions. Business modules add their own permissions in their tasks.
 * Idempotent: safe to run in every environment.
 */
class PermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'user.view' => 'View users',
        'user.create' => 'Create users',
        'user.update' => 'Update users',
        'user.delete' => 'Delete users',
        'role.view' => 'View roles',
        'role.create' => 'Create roles',
        'role.update' => 'Update roles',
        'role.delete' => 'Delete roles',
        'branch.view' => 'View branches',
        'branch.create' => 'Create branches',
        'branch.update' => 'Update branches',
        'setting.view' => 'View application settings',
        'setting.update' => 'Update application settings',
        'audit.view' => 'View audit logs',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name => $description) {
            Permission::updateOrCreate(['name' => $name], ['description' => $description]);
        }
    }
}
