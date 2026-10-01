<?php

namespace App\Modules\Administration\Backups\Jobs;

use App\Modules\Administration\Backups\Models\DatabaseBackup;
use App\Modules\Administration\Backups\Services\DatabaseBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a requested (manual) backup on the queue so the HTTP request returns immediately.
 */
class RunDatabaseBackup implements ShouldQueue
{
    use Queueable;

    /** A failed dump is recorded, not retried automatically (a new backup can be requested). */
    public int $tries = 1;

    public function __construct(public readonly string $backupId)
    {
        $this->timeout = (int) config('backup.timeout') + 60;
    }

    public function handle(DatabaseBackupService $service): void
    {
        $backup = DatabaseBackup::find($this->backupId);

        if ($backup && $backup->status === DatabaseBackup::PENDING) {
            $service->run($backup);
        }
    }

    /**
     * Worker crash/timeout: never leave the backup looking pending/running forever.
     */
    public function failed(?Throwable $e): void
    {
        DatabaseBackup::whereKey($this->backupId)
            ->whereIn('status', [DatabaseBackup::PENDING, DatabaseBackup::RUNNING])
            ->update(['status' => DatabaseBackup::FAILED, 'error_message' => 'The backup job stopped unexpectedly (timeout or worker failure).']);
    }
}
