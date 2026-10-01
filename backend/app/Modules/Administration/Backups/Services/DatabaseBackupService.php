<?php

namespace App\Modules\Administration\Backups\Services;

use App\Modules\Administration\Backups\Models\DatabaseBackup;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Creates, verifies, stores and expires PostgreSQL backups (pg_dump, plain SQL).
 *
 * Security: pg_dump runs without a shell, with fixed arguments built from server configuration only;
 * the password is passed via the PGPASSWORD environment variable and never logged, stored or returned.
 * Integrity: a dump is accepted only if pg_dump exits 0 AND the file ends with pg_dump's completion
 * marker; otherwise the partial file is deleted and the backup is marked failed.
 */
class DatabaseBackupService
{
    public const COMPLETION_MARKER = '-- PostgreSQL database dump complete';

    private const LOCK = 'database-backup';

    public function __construct(private readonly AuditLogger $audit) {}

    public function disk(): Filesystem
    {
        return Storage::disk(config('backup.disk'));
    }

    /**
     * Records a pending backup (the caller runs it now or queues it).
     */
    public function request(string $type, ?User $requester = null): DatabaseBackup
    {
        $backup = DatabaseBackup::create([
            'type' => $type,
            'status' => DatabaseBackup::PENDING,
            'disk' => config('backup.disk'),
            'requested_by' => $requester?->id,
        ]);

        $this->audit->record('backup.requested', 'database_backup', $backup->id, $requester?->id, newValues: ['type' => $type]);

        return $backup;
    }

    /**
     * Runs a pending backup. Never throws for dump failures: the result is in the returned model.
     */
    public function run(DatabaseBackup $backup): DatabaseBackup
    {
        // One backup at a time (scheduled and manual runs share the lock).
        $lock = Cache::lock(self::LOCK, config('backup.timeout') + 60);
        if (! $lock->block(min(600, config('backup.timeout')))) {
            return $this->fail($backup, 'Another backup was still running; try again later.');
        }

        $tempPath = null;

        try {
            $backup->update(['status' => DatabaseBackup::RUNNING, 'started_at' => now()]);
            $this->audit->record('backup.started', 'database_backup', $backup->id, $backup->requested_by, newValues: ['type' => $backup->type]);

            $filename = 'database-'.now()->format('Y-m-d-His').'-'.Str::lower(Str::random(8)).'.sql';
            $tempPath = $this->tempPath($filename);

            $this->dump($tempPath);
            $this->assertComplete($tempPath);

            $size = filesize($tempPath);
            $checksum = hash_file('sha256', $tempPath);
            $key = config('backup.directory').'/'.$filename;

            $stream = fopen($tempPath, 'rb');
            try {
                $this->disk()->writeStream($key, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $backup->update([
                'status' => DatabaseBackup::COMPLETED,
                'path' => $key,
                'filename' => $filename,
                'size_bytes' => $size,
                'checksum_sha256' => $checksum,
                'completed_at' => now(),
                'error_message' => null,
            ]);

            $this->audit->record('backup.completed', 'database_backup', $backup->id, $backup->requested_by, newValues: [
                'filename' => $filename, 'size_bytes' => $size, 'checksum_sha256' => $checksum,
            ]);
            Log::info('Database backup completed.', ['backup_id' => $backup->id, 'filename' => $filename, 'size_bytes' => $size]);

            return $backup->refresh();
        } catch (Throwable $e) {
            return $this->fail($backup, $e->getMessage());
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
            $lock->release();
        }
    }

    /**
     * Retention: deletes completed backups older than the retention period, always keeping the
     * newest `keep_minimum` completed backups. Metadata is kept (status "deleted") for history,
     * and only after the file is gone.
     *
     * @return array{deleted: int, kept: int, failed: int}
     */
    public function cleanup(): array
    {
        $days = max(1, (int) config('backup.retention_days'));
        $cutoff = now()->subDays($days);
        $protected = DatabaseBackup::where('status', DatabaseBackup::COMPLETED)
            ->orderByDesc('completed_at')->limit(max(0, (int) config('backup.keep_minimum')))->pluck('id');

        $expired = DatabaseBackup::where('status', DatabaseBackup::COMPLETED)
            ->where('completed_at', '<', $cutoff)
            ->whereNotIn('id', $protected)
            ->get();

        $result = ['deleted' => 0, 'kept' => $protected->count(), 'failed' => 0];

        foreach ($expired as $backup) {
            try {
                if (! $backup->hasSafePath()) {
                    throw new RuntimeException('Stored backup path is not a valid backup key.');
                }
                $disk = Storage::disk($backup->disk);
                if ($disk->exists($backup->path)) {
                    $disk->delete($backup->path);
                }
                if ($disk->exists($backup->path)) {
                    throw new RuntimeException('Backup file could not be deleted.');
                }

                $backup->update(['status' => DatabaseBackup::DELETED, 'deleted_at' => now()]);
                $this->audit->record('backup.deleted', 'database_backup', $backup->id, null, newValues: [
                    'filename' => $backup->filename, 'reason' => "retention ({$days} days)",
                ]);
                $result['deleted']++;
            } catch (Throwable $e) {
                $result['failed']++;
                Log::error('Database backup cleanup failed.', ['backup_id' => $backup->id, 'error' => $this->safe($e->getMessage())]);
            }
        }

        return $result;
    }

    private function dump(string $target): void
    {
        $db = config('database.connections.'.config('backup.connection'));

        $process = new Process(
            [
                config('backup.pg_dump_binary'),
                '--format=plain',
                '--no-owner',          // restorable as any database user
                '--no-privileges',     // no GRANT/REVOKE tied to this server's roles
                '--encoding=UTF8',
                '--host='.$db['host'],
                '--port='.$db['port'],
                '--username='.$db['username'],
                '--dbname='.$db['database'],
                '--file='.$target,
            ],
            null,
            ['PGPASSWORD' => (string) $db['password'], 'PGCONNECT_TIMEOUT' => '15'],
            null,
            (float) config('backup.timeout'),
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('pg_dump failed (exit '.$process->getExitCode().'): '.trim($process->getErrorOutput()));
        }
    }

    /**
     * Rejects empty or truncated dumps: pg_dump writes its completion marker only at the very end.
     */
    private function assertComplete(string $path): void
    {
        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('pg_dump produced no output.');
        }

        $handle = fopen($path, 'rb');
        fseek($handle, -512, SEEK_END) === 0 || rewind($handle);
        $tail = stream_get_contents($handle);
        fclose($handle);

        if (! str_contains($tail, self::COMPLETION_MARKER)) {
            throw new RuntimeException('The dump is incomplete (completion marker missing).');
        }
    }

    private function fail(DatabaseBackup $backup, string $reason): DatabaseBackup
    {
        $message = $this->safe($reason);

        $backup->update(['status' => DatabaseBackup::FAILED, 'error_message' => $message, 'completed_at' => null]);
        $this->audit->record('backup.failed', 'database_backup', $backup->id, $backup->requested_by, newValues: ['error' => $message]);
        Log::error('Database backup failed.', ['backup_id' => $backup->id, 'type' => $backup->type, 'error' => $message]);

        return $backup->refresh();
    }

    /**
     * Error text safe to store/show: credentials, connection details and paths removed, length limited.
     */
    private function safe(string $message): string
    {
        $db = config('database.connections.'.config('backup.connection'));
        $secrets = array_filter([(string) ($db['password'] ?? ''), (string) ($db['username'] ?? ''), (string) ($db['host'] ?? '')], fn ($v) => strlen($v) >= 3);

        $message = str_replace($secrets, '[hidden]', $message);
        $message = preg_replace('#(/[\w.-]+){2,}#', '[path]', $message);

        return Str::limit(trim($message) ?: 'Unknown backup error.', 990);
    }

    private function tempPath(string $filename): string
    {
        $dir = storage_path('app/backup-tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir.'/'.$filename.'.partial';
    }
}
