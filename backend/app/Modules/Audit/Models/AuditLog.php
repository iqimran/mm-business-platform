<?php

namespace App\Modules\Audit\Models;

use App\Modules\Branch\Models\Branch;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: the database rejects UPDATE and DELETE on audit_logs.
 */
#[Fillable([
    'user_id', 'branch_id', 'action', 'entity_type', 'entity_id',
    'old_values', 'new_values', 'ip_address', 'user_agent',
])]
class AuditLog extends Model
{
    use HasUlids;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
