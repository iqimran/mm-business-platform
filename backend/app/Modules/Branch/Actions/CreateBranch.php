<?php

namespace App\Modules\Branch\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branch\Models\Branch;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

class CreateBranch
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, phone?: string|null, email?: string|null, address?: string|null, is_active?: bool}  $data
     */
    public function handle(User $actor, array $data): Branch
    {
        return DB::transaction(function () use ($actor, $data) {
            $branch = Branch::create($data + ['is_active' => true]);

            // Creators without global access are assigned so they can manage what they created.
            if (! $actor->canAccessAllBranches()) {
                $actor->branches()->attach($branch);
                $actor->forgetResolvedBranches();
            }

            $this->audit->record('branch.created', 'branch', $branch->id, $actor->id, $branch->id,
                newValues: $branch->only('code', 'name', 'phone', 'email', 'address', 'is_active'));

            return $branch;
        });
    }
}
