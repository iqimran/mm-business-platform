<?php

namespace App\Modules\Administration\Policies;

use App\Modules\Administration\Services\PrivilegeGuard;
use App\Modules\Identity\Models\User;

class UserPolicy
{
    public function __construct(private readonly PrivilegeGuard $guard) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('user.view');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->hasPermission('user.view') && $this->guard->canSee($actor, $target);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('user.create');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->hasPermission('user.update') && $this->guard->canManage($actor, $target);
    }

    /**
     * Role and branch assignment of one's own account is not allowed (prevents lockout and self-escalation).
     */
    public function assign(User $actor, User $target): bool
    {
        return ! $actor->is($target) && $this->update($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        return ! $actor->is($target)
            && $actor->hasPermission('user.delete')
            && $this->guard->canManage($actor, $target);
    }
}
