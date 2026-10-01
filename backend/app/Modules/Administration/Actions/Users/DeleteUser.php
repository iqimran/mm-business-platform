<?php

namespace App\Modules\Administration\Actions\Users;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DeleteUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Only users without activity history can be deleted; others must be deactivated
     * so the audit trail stays intact (also enforced by a foreign key).
     */
    public function handle(User $actor, User $user): void
    {
        if (AuditLog::where('user_id', $user->id)->exists()) {
            throw new ConflictHttpException('This user has activity history and cannot be deleted. Deactivate the user instead.');
        }

        DB::transaction(function () use ($actor, $user) {
            $old = $user->only('name', 'email', 'is_active');
            $user->delete(); // role/branch assignments cascade

            $this->audit->record('user.deleted', 'user', $user->id, $actor->id, oldValues: $old);
        });
    }
}
