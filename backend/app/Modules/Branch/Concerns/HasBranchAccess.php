<?php

namespace App\Modules\Branch\Concerns;

use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Branch access for a user:
 * - users holding the "branch.access_all" permission may access every branch;
 * - everyone else only the active branches assigned to them (branch_user).
 */
trait HasBranchAccess
{
    public const ACCESS_ALL_BRANCHES = 'branch.access_all';

    private ?Collection $resolvedBranchIds = null;

    public function canAccessAllBranches(): bool
    {
        return $this->hasPermission(self::ACCESS_ALL_BRANCHES);
    }

    /**
     * @return Collection<int, string>
     */
    public function accessibleBranchIds(): Collection
    {
        if (! $this->is_active) {
            return collect();
        }

        return $this->resolvedBranchIds ??= $this->canAccessAllBranches()
            ? Branch::query()->orderBy('code')->pluck('id')
            : Branch::query()->whereIn('id', $this->assignedActiveBranchIdsQuery())->orderBy('code')->pluck('id');
    }

    /**
     * Unknown branch ids are denied exactly like unassigned ones, so existence is not leaked.
     */
    public function canAccessBranch(Branch|string|null $branch): bool
    {
        $branchId = $branch instanceof Branch ? $branch->getKey() : $branch;

        return is_string($branchId) && $this->accessibleBranchIds()->contains($branchId);
    }

    /**
     * Subquery of assigned, active branch ids (used by query scopes to avoid large IN lists).
     */
    public function assignedActiveBranchIdsQuery(): QueryBuilder
    {
        return DB::table('branch_user')
            ->join('branches', 'branches.id', '=', 'branch_user.branch_id')
            ->where('branch_user.user_id', $this->getKey())
            ->where('branches.is_active', true)
            ->select('branch_user.branch_id');
    }

    public function forgetResolvedBranches(): void
    {
        $this->resolvedBranchIds = null;
    }
}
