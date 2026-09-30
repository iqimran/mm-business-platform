<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Writes append-only audit records. Callers must never pass passwords, tokens or secrets.
 */
class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    public function record(
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?string $userId = null,
        ?string $branchId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $userId,
            'branch_id' => $branchId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }
}
