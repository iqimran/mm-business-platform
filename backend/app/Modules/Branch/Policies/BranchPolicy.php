<?php

namespace App\Modules\Branch\Policies;

use App\Modules\Branch\Models\Branch;
use App\Modules\Identity\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('branch.view');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->hasPermission('branch.view') && $user->canAccessBranch($branch);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('branch.create');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->hasPermission('branch.update') && $user->canAccessBranch($branch);
    }
}
