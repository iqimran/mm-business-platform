<?php

namespace App\Modules\Administration\Services;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Prevents privilege escalation in administration:
 * - an actor may only grant permissions/roles they hold themselves;
 * - an actor may only manage users whose permissions AND branches are within their own
 *   (otherwise resetting that user's password would let the actor act as them).
 */
class PrivilegeGuard
{
    /**
     * Users visible to the actor: global actors see everyone, others see users
     * sharing at least one of their accessible branches.
     */
    public function canSee(User $actor, User $target): bool
    {
        if ($actor->is($target) || $actor->canAccessAllBranches()) {
            return true;
        }

        return $target->branches()->whereIn('branches.id', $actor->assignedActiveBranchIdsQuery())->exists();
    }

    public function canManage(User $actor, User $target): bool
    {
        if (! $this->canSee($actor, $target) || ! $this->holdsAll($actor, $target->grantedPermissionNames())) {
            return false;
        }

        if ($actor->canAccessAllBranches()) {
            return true;
        }

        return $target->branches()->pluck('branches.id')->diff($actor->accessibleBranchIds())->isEmpty();
    }

    /**
     * @param  iterable<string>  $permissionNames
     */
    public function holdsAll(User $actor, iterable $permissionNames): bool
    {
        return collect($permissionNames)->diff($actor->permissionNames())->isEmpty();
    }

    /**
     * @param  iterable<string>  $permissionNames
     *
     * @throws AuthorizationException
     */
    public function assertCanGrantPermissions(User $actor, iterable $permissionNames): void
    {
        if (! $this->holdsAll($actor, $permissionNames)) {
            throw new AuthorizationException('You cannot grant permissions you do not hold.');
        }
    }

    /**
     * @param  Collection<int, Role>  $roles
     *
     * @throws AuthorizationException
     */
    public function assertCanGrantRoles(User $actor, Collection $roles): void
    {
        $roles->loadMissing('permissions:id,name');

        $this->assertCanGrantPermissions($actor, $roles->flatMap(fn (Role $role) => $role->permissions->pluck('name')));
    }
}
