<?php

namespace App\Modules\Administration\Backups\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Backups\Http\Resources\DatabaseBackupResource;
use App\Modules\Administration\Backups\Jobs\RunDatabaseBackup;
use App\Modules\Administration\Backups\Models\DatabaseBackup;
use App\Modules\Administration\Backups\Services\DatabaseBackupService;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Database backups are a global, system-level operation (a dump contains every branch),
 * so access is controlled by permissions only and is not branch-scoped.
 */
class DatabaseBackupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('database.backup.view');

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'running', 'completed', 'failed', 'deleted'])],
            'type' => ['sometimes', Rule::in(['automatic', 'manual'])],
        ]);

        $backups = DatabaseBackup::query()
            ->with('requester:id,name')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($backups, DatabaseBackupResource::class);
    }

    public function show(DatabaseBackup $databaseBackup): JsonResponse
    {
        Gate::authorize('database.backup.view');

        return ApiResponse::success(DatabaseBackupResource::make($databaseBackup->load('requester:id,name'))->resolve());
    }

    /**
     * Queues a manual backup and returns its pending record immediately (poll GET /{id}).
     */
    public function store(Request $request, DatabaseBackupService $service): JsonResponse
    {
        Gate::authorize('database.backup.create');

        if (DatabaseBackup::whereIn('status', [DatabaseBackup::PENDING, DatabaseBackup::RUNNING])->exists()) {
            throw new ConflictHttpException('A backup is already in progress. Wait for it to finish.');
        }

        $backup = $service->request(DatabaseBackup::TYPE_MANUAL, $request->user());
        RunDatabaseBackup::dispatch($backup->id);

        return ApiResponse::success(
            DatabaseBackupResource::make($backup->refresh()->load('requester:id,name'))->resolve(),
            'Backup requested. It runs in the background.',
            202,
        );
    }

    /**
     * Streams a completed backup to an authorized user. The file is located only through the
     * stored, pattern-validated key of this record; no client-supplied path is ever used.
     */
    public function download(Request $request, DatabaseBackup $databaseBackup, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('database.backup.download');

        if ($databaseBackup->status !== DatabaseBackup::COMPLETED || ! $databaseBackup->hasSafePath()) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk($databaseBackup->disk);
        if (! $disk->exists($databaseBackup->path)) {
            Log::error('Database backup file missing.', ['backup_id' => $databaseBackup->id]);
            throw new NotFoundHttpException;
        }

        $audit->record('backup.downloaded', 'database_backup', $databaseBackup->id, $request->user()->id,
            newValues: ['filename' => $databaseBackup->filename]);

        return $disk->download($databaseBackup->path, $databaseBackup->filename, [
            'Content-Type' => 'application/sql',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
