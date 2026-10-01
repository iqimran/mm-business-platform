<?php

namespace App\Modules\Branch\Concerns;

use App\Modules\Branch\Models\Branch;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For branch-scoped models (table has a branch_id column).
 * Always list records through scopeAccessibleBy() so users only see their branches.
 */
trait BelongsToBranch
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        $column = $this->qualifyColumn('branch_id');

        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->canAccessAllBranches()) {
            return $query;
        }

        // A literal ID list (a user has few branches) lets PostgreSQL use the
        // (branch_id, created_at) index in order; a subquery forces a full index walk.
        return $query->whereIn($column, $user->accessibleBranchIds()->all());
    }
}
