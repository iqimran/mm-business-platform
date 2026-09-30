<?php

namespace App\Modules\Branch\Policies;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for policies of branch-scoped models: the user needs the permission
 * AND access to the record's branch. Cross-branch access is denied.
 */
abstract class BranchScopedPolicy
{
    protected function allows(User $user, string $permission, Model $record): bool
    {
        return $user->hasPermission($permission)
            && $user->canAccessBranch($record->getAttribute('branch_id'));
    }
}
