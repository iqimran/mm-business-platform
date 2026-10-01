<?php

namespace App\Modules\Administration\Backups\Console;

use App\Modules\Administration\Backups\Models\DatabaseBackup;
use App\Modules\Administration\Backups\Services\DatabaseBackupService;
use Illuminate\Console\Command;

/**
 * php artisan db:backup            create a backup now (type "automatic"; used by the scheduler)
 * php artisan db:backup --manual   create a backup now, recorded as "manual"
 * php artisan db:backup --cleanup  apply the retention policy only
 * php artisan db:backup --list     show recent backups
 */
class DatabaseBackupCommand extends Command
{
    protected $signature = 'db:backup
        {--manual : Record the backup as manual instead of automatic}
        {--cleanup : Only delete backups older than the retention period}
        {--list : List the most recent backups}';

    protected $description = 'Create a PostgreSQL backup (plain SQL), apply retention, or list backups';

    public function handle(DatabaseBackupService $service): int
    {
        if ($this->option('list')) {
            return $this->listBackups();
        }

        if ($this->option('cleanup')) {
            $result = $service->cleanup();
            $this->info("Retention: deleted {$result['deleted']}, protected {$result['kept']}, failed {$result['failed']} (keeping ".config('backup.retention_days').' days).');

            return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $backup = $service->run($service->request($this->option('manual') ? DatabaseBackup::TYPE_MANUAL : DatabaseBackup::TYPE_AUTOMATIC));

        if ($backup->status !== DatabaseBackup::COMPLETED) {
            $this->error("Backup {$backup->id} FAILED: {$backup->error_message}");

            return self::FAILURE;
        }

        $this->info("Backup {$backup->id} completed: {$backup->filename} (".number_format($backup->size_bytes).' bytes, sha256 '.$backup->checksum_sha256.')');
        $this->line('Location: disk "'.$backup->disk.'", key "'.$backup->path.'"');

        return self::SUCCESS;
    }

    private function listBackups(): int
    {
        $rows = DatabaseBackup::latest()->limit(20)->get()->map(fn (DatabaseBackup $b) => [
            $b->created_at->format('Y-m-d H:i:s'), $b->type, $b->status, $b->filename ?? '—',
            $b->size_bytes ? number_format($b->size_bytes) : '—', $b->error_message ? str($b->error_message)->limit(60) : '',
        ]);

        $this->table(['Created', 'Type', 'Status', 'File', 'Bytes', 'Error'], $rows);

        return self::SUCCESS;
    }
}
