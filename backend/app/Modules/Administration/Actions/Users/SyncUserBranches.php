<?php

namespace App\Modules\Administration\Actions\Users;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Branch ids are validated against the actor's own access (AccessibleBranch) before this runs.
 */
class SyncUserBranches
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string>  $branchIds
     */
    public function handle(User $actor, User $user, array $branchIds): User
    {
        return DB::transaction(function () use ($actor, $user, $branchIds) {
            $old = $user->branches()->pluck('branches.id')->sort()->values()->all();
            $user->branches()->sync($branchIds);
            $new = collect($branchIds)->sort()->values()->all();

            if ($old !== $new) {
                $this->audit->record('user.branches_updated', 'user', $user->id, $actor->id,
                    oldValues: ['branch_ids' => $old], newValues: ['branch_ids' => $new]);
            }

            return $user;
        });
    }
}
