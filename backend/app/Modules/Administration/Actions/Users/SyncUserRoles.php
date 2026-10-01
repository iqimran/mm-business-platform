<?php

namespace App\Modules\Administration\Actions\Users;

use App\Modules\Administration\Services\PrivilegeGuard;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

class SyncUserRoles
{
    public function __construct(
        private readonly PrivilegeGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string>  $roleIds
     */
    public function handle(User $actor, User $user, array $roleIds): User
    {
        $this->guard->assertCanGrantRoles($actor, Role::whereIn('id', $roleIds)->get());

        return DB::transaction(function () use ($actor, $user, $roleIds) {
            $old = $user->roles()->pluck('roles.id')->sort()->values()->all();
            $user->roles()->sync($roleIds);
            $new = collect($roleIds)->sort()->values()->all();

            if ($old !== $new) {
                $this->audit->record('user.roles_updated', 'user', $user->id, $actor->id,
                    oldValues: ['role_ids' => $old], newValues: ['role_ids' => $new]);
            }

            return $user;
        });
    }
}
