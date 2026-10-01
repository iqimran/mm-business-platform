<?php

namespace App\Modules\Administration\Backups\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata of one database backup (the SQL file is on the backups disk).
 */
#[Fillable(['type', 'status', 'disk', 'path', 'filename', 'size_bytes', 'checksum_sha256', 'error_message', 'requested_by', 'started_at', 'completed_at', 'deleted_at'])]
class DatabaseBackup extends Model
{
    use HasUlids;

    public const TYPE_AUTOMATIC = 'automatic';

    public const TYPE_MANUAL = 'manual';

    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const DELETED = 'deleted';

    /** Shape every stored backup key must match (checked before any file access). */
    public const PATH_PATTERN = '#^database/database-\d{4}-\d{2}-\d{2}-\d{6}-[0-9a-z]{8}\.sql$#';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [self::PENDING, self::RUNNING], true);
    }

    public function hasSafePath(): bool
    {
        return is_string($this->path) && preg_match(self::PATH_PATTERN, $this->path) === 1;
    }
}
