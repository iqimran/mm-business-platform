<?php

namespace App\Modules\Administration\Actions\Roles;

use App\Modules\Administration\Services\PrivilegeGuard;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DeleteRole
{
    public function __construct(
        private readonly PrivilegeGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $actor, Role $role): void
    {
        $permissions = $role->permissions()->pluck('name')->sort()->values();

        if (! $this->guard->holdsAll($actor, $permissions)) {
            throw new AuthorizationException('You cannot delete a role with permissions you do not hold.');
        }

        if ($role->is_system) {
            throw new ConflictHttpException('System roles cannot be deleted.');
        }

        if ($role->users()->exists()) {
            throw new ConflictHttpException('This role is assigned to users. Remove it from all users first.');
        }

        DB::transaction(function () use ($actor, $role, $permissions) {
            $old = ['name' => $role->name, 'description' => $role->description, 'permissions' => $permissions->all()];
            $role->delete(); // permission grants cascade

            $this->audit->record('role.deleted', 'role', $role->id, $actor->id, oldValues: $old);
        });
    }
}
