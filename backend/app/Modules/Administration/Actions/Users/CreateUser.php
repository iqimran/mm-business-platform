<?php

namespace App\Modules\Administration\Actions\Users;

use App\Modules\Administration\Services\PrivilegeGuard;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

class CreateUser
{
    public function __construct(
        private readonly PrivilegeGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, is_active?: bool, role_ids?: array<string>, branch_ids?: array<string>}  $data
     */
    public function handle(User $actor, array $data): User
    {
        $roleIds = $data['role_ids'] ?? [];
        $branchIds = $data['branch_ids'] ?? [];

        $this->guard->assertCanGrantRoles($actor, Role::whereIn('id', $roleIds)->get());

        return DB::transaction(function () use ($actor, $data, $roleIds, $branchIds) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);
            $user->roles()->sync($roleIds);
            $user->branches()->sync($branchIds);

            $this->audit->record('user.created', 'user', $user->id, $actor->id, newValues: [
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role_ids' => array_values($roleIds),
                'branch_ids' => array_values($branchIds),
            ]);

            return $user;
        });
    }
}
