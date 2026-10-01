<?php

namespace App\Modules\Branch\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branch\Models\Branch;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Branches are deactivated (is_active=false), never deleted, to preserve history.
 */
class UpdateBranch
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, Branch $branch, array $data): Branch
    {
        return DB::transaction(function () use ($actor, $branch, $data) {
            $branch->fill(Arr::only($data, ['code', 'name', 'phone', 'email', 'address', 'is_active']));
            $changed = array_keys($branch->getDirty());
            $old = Arr::only($branch->getOriginal(), $changed);
            $branch->save();

            if ($changed !== []) {
                $this->audit->record('branch.updated', 'branch', $branch->id, $actor->id, $branch->id,
                    oldValues: $old, newValues: Arr::only($branch->getAttributes(), $changed));
            }

            return $branch;
        });
    }
}
