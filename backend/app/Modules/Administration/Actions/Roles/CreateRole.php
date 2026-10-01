<?php

namespace App\Modules\Administration\Actions\Roles;

use App\Modules\Administration\Services\PrivilegeGuard;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

class CreateRole
{
    public function __construct(
        private readonly PrivilegeGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, permissions?: array<string>}  $data
     */
    public function handle(User $actor, array $data): Role
    {
        $permissions = collect($data['permissions'] ?? [])->unique()->sort()->values();
        $this->guard->assertCanGrantPermissions($actor, $permissions);

        return DB::transaction(function () use ($actor, $data, $permissions) {
            $role = Role::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
            ]);
            $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

            $this->audit->record('role.created', 'role', $role->id, $actor->id, newValues: [
                'name' => $role->name,
                'description' => $role->description,
                'permissions' => $permissions->all(),
            ]);

            return $role;
        });
    }
}
