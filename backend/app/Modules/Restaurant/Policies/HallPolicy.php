<?php

namespace App\Modules\Restaurant\Policies;

use App\Modules\Branch\Policies\BranchScopedPolicy;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\Hall;

/**
 * Halls belong to a branch: permission AND access to the hall's branch.
 */
class HallPolicy extends BranchScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('restaurant.hall.view');
    }

    public function view(User $user, Hall $hall): bool
    {
        return $this->allows($user, 'restaurant.hall.view', $hall);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('restaurant.hall.create');
    }

    public function update(User $user, Hall $hall): bool
    {
        return $this->allows($user, 'restaurant.hall.update', $hall);
    }

    public function delete(User $user, Hall $hall): bool
    {
        return $this->allows($user, 'restaurant.hall.delete', $hall);
    }
}
