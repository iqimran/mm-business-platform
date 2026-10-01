<?php

namespace App\Modules\Administration\Actions\Roles;

use App\Modules\Administration\Services\PrivilegeGuard;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateRole
{
    public function __construct(
        private readonly PrivilegeGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name?: string, description?: string|null, permissions?: array<string>}  $data
     */
    public function handle(User $actor, Role $role, array $data): Role
    {
        $current = $role->permissions()->pluck('name')->sort()->values();

        // Editing a role that holds permissions the actor lacks would be escalation.
        if (! $this->guard->holdsAll($actor, $current)) {
            throw new AuthorizationException('You cannot modify a role with permissions you do not hold.');
        }

        if ($role->is_system && isset($data['name']) && $data['name'] !== $role->name) {
            throw ValidationException::withMessages(['name' => 'System roles cannot be renamed.']);
        }

        $permissions = array_key_exists('permissions', $data)
            ? collect($data['permissions'])->unique()->sort()->values()
            : null;

        if ($permissions !== null) {
            $this->guard->assertCanGrantPermissions($actor, $permissions);
        }

        return DB::transaction(function () use ($actor, $role, $data, $current, $permissions) {
            $role->fill(Arr::only($data, ['name', 'description']));
            $changed = array_keys($role->getDirty());
            $old = Arr::only($role->getOriginal(), $changed);
            $role->save();
            $new = Arr::only($role->getAttributes(), $changed);

            if ($permissions !== null && $permissions->all() !== $current->all()) {
                $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
                $old['permissions'] = $current->all();
                $new['permissions'] = $permissions->all();
            }

            if ($new !== []) {
                $this->audit->record('role.updated', 'role', $role->id, $actor->id, oldValues: $old, newValues: $new);
            }

            return $role;
        });
    }
}
