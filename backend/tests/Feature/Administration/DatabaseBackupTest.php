<?php

namespace Tests\Feature\Administration;

use App\Modules\Administration\Backups\Jobs\RunDatabaseBackup;
use App\Modules\Administration\Backups\Models\DatabaseBackup;
use App\Modules\Administration\Backups\Services\DatabaseBackupService;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Real pg_dump against the test database; fake pg_dump scripts simulate failures.
 */
class DatabaseBackupTest extends AdministrationTestCase
{
    private const ALL = ['database.backup.view', 'database.backup.create', 'database.backup.download'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
    }

    private function backupAdmin(array $permissions = self::ALL): User
    {
        return $this->userWith($permissions);
    }

    private function useFakeDump(string $script): void
    {
        config(['backup.pg_dump_binary' => base_path("tests/Fixtures/bin/{$script}")]);
    }

    private function completedBackup(string $age = 'now', ?string $path = null): DatabaseBackup
    {
        $filename = 'database-2026-01-01-000000-'.strtolower(substr(md5(uniqid()), 0, 8)).'.sql';
        $path ??= 'database/'.$filename;
        Storage::disk('backups')->put($path, "-- dump\n".DatabaseBackupService::COMPLETION_MARKER."\n");

        $backup = DatabaseBackup::create([
            'type' => 'automatic', 'status' => 'completed', 'disk' => 'backups', 'path' => $path, 'filename' => basename($path),
            'size_bytes' => 10, 'checksum_sha256' => str_repeat('a', 64), 'started_at' => now(), 'completed_at' => now(),
        ]);
        $backup->forceFill(['completed_at' => now()->sub(CarbonInterval::fromString($age === 'now' ? '0 seconds' : $age))])->save();

        return $backup;
    }

    // ---- Backup command (real pg_dump) ----

    public function test_command_creates_a_complete_sql_backup_with_metadata(): void
    {
        $this->artisan('db:backup')->assertSuccessful();

        $backup = DatabaseBackup::sole();
        $this->assertSame('completed', $backup->status);
        $this->assertSame('automatic', $backup->type);
        $this->assertNull($backup->requested_by);
        $this->assertMatchesRegularExpression(DatabaseBackup::PATH_PATTERN, $backup->path);
        $this->assertStringEndsWith('.sql', $backup->filename);

        $sql = Storage::disk('backups')->get($backup->path);
        $this->assertStringContainsString('-- PostgreSQL database dump', $sql);
        $this->assertStringContainsString('CREATE TABLE public.users', $sql);
        $this->assertStringContainsString('CREATE TABLE public.car_sales', $sql);
        $this->assertStringContainsString(DatabaseBackupService::COMPLETION_MARKER, $sql);
        $this->assertSame(strlen($sql), $backup->size_bytes);
        $this->assertSame(hash('sha256', $sql), $backup->checksum_sha256);

        // Credentials never end up in the dump or the metadata.
        $password = config('database.connections.pgsql.password');
        $this->assertStringNotContainsString($password, $sql);
        $this->assertStringNotContainsString($password, json_encode($backup->toArray()));

        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.completed', 'entity_id' => $backup->id]);
    }

    public function test_each_backup_gets_a_unique_file(): void
    {
        $this->artisan('db:backup')->assertSuccessful();
        $this->artisan('db:backup --manual')->assertSuccessful();

        $backups = DatabaseBackup::orderBy('created_at')->get();
        $this->assertCount(2, $backups);
        $this->assertNotSame($backups[0]->path, $backups[1]->path);
        $this->assertSame(['automatic', 'manual'], $backups->pluck('type')->all());
        $this->assertCount(2, Storage::disk('backups')->files('database'));
    }

    public function test_backups_are_stored_outside_the_public_web_root(): void
    {
        $root = realpath(config('filesystems.disks.backups.root')) ?: config('filesystems.disks.backups.root');

        $this->assertFalse(config('filesystems.disks.backups.serve'));
        $this->assertStringStartsNotWith(public_path(), $root);
        // Never served: no route maps to the backups disk (the private "local" disk route refuses unsigned requests).
        foreach (['/backups/database/database-2026-01-01-000000-abcdefgh.sql', '/storage/backups/database/x.sql', '/storage/app/backups/database/x.sql'] as $url) {
            $response = $this->get($url);
            $this->assertContains($response->getStatusCode(), [403, 404], $url);
            $this->assertStringNotContainsString('PostgreSQL database dump', (string) $response->getContent());
        }
    }

    // ---- Failure handling ----

    public function test_pg_dump_failure_marks_backup_failed_without_leaking_credentials(): void
    {
        $this->useFakeDump('pg-dump-fails');
        Log::spy();

        $this->artisan('db:backup')->assertFailed();

        $backup = DatabaseBackup::sole();
        $password = config('database.connections.pgsql.password');
        $this->assertSame('failed', $backup->status);
        $this->assertNull($backup->completed_at);
        $this->assertNull($backup->path);
        $this->assertStringContainsString('pg_dump failed', $backup->error_message);
        $this->assertStringNotContainsString($password, $backup->error_message);
        $this->assertStringNotContainsString('/var/lib/postgresql', $backup->error_message);
        $this->assertSame([], Storage::disk('backups')->allFiles());
        $this->assertSame([], glob(storage_path('app/backup-tmp/*')) ?: []);

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'Database backup failed.'
            && ! str_contains(json_encode($context), $password))->once();

        $audit = AuditLog::where('action', 'backup.failed')->sole();
        $this->assertStringNotContainsString($password, json_encode($audit->new_values));
    }

    public function test_partial_dump_is_never_treated_as_successful(): void
    {
        $this->useFakeDump('pg-dump-partial');

        $this->artisan('db:backup')->assertFailed();

        $backup = DatabaseBackup::sole();
        $this->assertSame('failed', $backup->status);
        $this->assertStringContainsString('incomplete', $backup->error_message);
        $this->assertSame([], Storage::disk('backups')->allFiles());
        $this->assertSame([], glob(storage_path('app/backup-tmp/*')) ?: []);
    }

    // ---- API: authorization, manual backup, download ----

    public function test_endpoints_require_authentication_and_permissions(): void
    {
        $backup = $this->completedBackup();

        $this->getJson('/api/v1/database-backups')->assertUnauthorized();
        $this->postJson('/api/v1/database-backups')->assertUnauthorized();

        $nobody = $this->userWith(['user.view', 'branch.access_all']);
        $this->actingAs($nobody)->getJson('/api/v1/database-backups')->assertForbidden();
        $this->actingAs($nobody)->postJson('/api/v1/database-backups')->assertForbidden();
        $this->actingAs($nobody)->get("/api/v1/database-backups/{$backup->id}/download")->assertForbidden();

        $viewer = $this->backupAdmin(['database.backup.view']);
        $this->actingAs($viewer)->getJson('/api/v1/database-backups')->assertOk();
        $this->actingAs($viewer)->postJson('/api/v1/database-backups')->assertForbidden();
        $this->actingAs($viewer)->get("/api/v1/database-backups/{$backup->id}/download")->assertForbidden();

        $this->assertSame(1, DatabaseBackup::count());
    }

    public function test_authorized_user_queues_a_manual_backup(): void
    {
        Queue::fake();
        $admin = $this->backupAdmin();

        $response = $this->actingAs($admin)->postJson('/api/v1/database-backups')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.type', 'manual')
            ->assertJsonPath('data.requested_by.id', $admin->id);

        Queue::assertPushed(RunDatabaseBackup::class, fn ($job) => $job->backupId === $response->json('data.id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.requested', 'user_id' => $admin->id]);

        // A second request while one is pending is refused.
        $this->actingAs($admin)->postJson('/api/v1/database-backups')->assertConflict();
    }

    public function test_manual_backup_runs_to_completion_through_the_job(): void
    {
        $admin = $this->backupAdmin();

        // Sync queue in tests: the job runs within the request.
        $id = $this->actingAs($admin)->postJson('/api/v1/database-backups')->assertStatus(202)->json('data.id');

        $this->actingAs($admin)->getJson("/api/v1/database-backups/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.downloadable', true)
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        foreach (['backup.requested', 'backup.started', 'backup.completed'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'entity_id' => $id, 'user_id' => $admin->id]);
        }
    }

    public function test_authorized_user_downloads_a_completed_backup(): void
    {
        $backup = $this->completedBackup();
        $admin = $this->backupAdmin();

        $response = $this->actingAs($admin)->get("/api/v1/database-backups/{$backup->id}/download")->assertOk();

        $this->assertStringContainsString($backup->filename, $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString(DatabaseBackupService::COMPLETION_MARKER, $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.downloaded', 'entity_id' => $backup->id, 'user_id' => $admin->id]);
    }

    public function test_only_completed_backups_can_be_downloaded(): void
    {
        $admin = $this->backupAdmin();
        $failed = DatabaseBackup::create(['type' => 'manual', 'status' => 'failed', 'disk' => 'backups', 'error_message' => 'x']);

        $this->actingAs($admin)->get("/api/v1/database-backups/{$failed->id}/download")->assertNotFound();
    }

    // ---- Security ----

    public function test_path_traversal_and_arbitrary_files_are_rejected(): void
    {
        $admin = $this->backupAdmin();
        Storage::disk('backups')->put('secret.txt', 'not a backup');

        // A tampered record pointing outside the backup naming scheme is never served.
        $tampered = DatabaseBackup::create([
            'type' => 'manual', 'status' => 'completed', 'disk' => 'backups', 'path' => 'database/../secret.txt',
            'filename' => 'secret.txt', 'size_bytes' => 1, 'checksum_sha256' => str_repeat('b', 64), 'completed_at' => now(),
        ]);
        $this->actingAs($admin)->get("/api/v1/database-backups/{$tampered->id}/download")->assertNotFound();

        // Files are addressed by record id only; names and paths in the URL resolve nothing.
        $this->actingAs($admin)->get('/api/v1/database-backups/..%2F..%2F.env/download')->assertNotFound();
        $this->actingAs($admin)->get('/api/v1/database-backups/database-2026-01-01-000000-abcdefgh.sql/download')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/v1/database-backups/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    }

    public function test_api_responses_and_audit_never_expose_credentials_or_server_paths(): void
    {
        $this->useFakeDump('pg-dump-fails');
        $this->artisan('db:backup')->assertFailed();
        $this->completedBackup();
        $admin = $this->backupAdmin();

        $json = $this->actingAs($admin)->getJson('/api/v1/database-backups')->assertOk()->getContent();
        $audit = json_encode(AuditLog::all()->toArray());

        foreach ([config('database.connections.pgsql.password'), config('filesystems.disks.backups.root'), base_path()] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
            $this->assertStringNotContainsString($secret, $audit);
        }
    }

    // ---- Retention ----

    public function test_cleanup_deletes_only_expired_backups_and_keeps_history(): void
    {
        config(['backup.retention_days' => 30, 'backup.keep_minimum' => 1]);
        $expired = $this->completedBackup('31 days');
        $withinRetention = $this->completedBackup('29 days');
        $recent = $this->completedBackup();

        $this->artisan('db:backup --cleanup')->assertSuccessful();

        Storage::disk('backups')->assertMissing($expired->path);
        Storage::disk('backups')->assertExists($withinRetention->path);
        Storage::disk('backups')->assertExists($recent->path);
        $this->assertSame('deleted', $expired->fresh()->status);
        $this->assertNotNull($expired->fresh()->deleted_at);
        $this->assertSame('completed', $withinRetention->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.deleted', 'entity_id' => $expired->id]);
    }

    public function test_cleanup_always_keeps_the_newest_backups(): void
    {
        config(['backup.retention_days' => 7, 'backup.keep_minimum' => 1]);
        $older = $this->completedBackup('60 days');
        $newestButExpired = $this->completedBackup('40 days');

        $this->artisan('db:backup --cleanup')->assertSuccessful();

        $this->assertSame('deleted', $older->fresh()->status);
        $this->assertSame('completed', $newestButExpired->fresh()->status);
        Storage::disk('backups')->assertExists($newestButExpired->path);
    }

    public function test_cleanup_never_touches_files_outside_the_backup_scheme(): void
    {
        config(['backup.retention_days' => 1, 'backup.keep_minimum' => 0]);
        Storage::disk('backups')->put('keep-me.txt', 'x');
        $tampered = DatabaseBackup::create([
            'type' => 'manual', 'status' => 'completed', 'disk' => 'backups', 'path' => 'keep-me.txt',
            'filename' => 'keep-me.txt', 'size_bytes' => 1, 'checksum_sha256' => str_repeat('c', 64), 'completed_at' => now()->subDays(10),
        ]);

        $this->artisan('db:backup --cleanup')->assertFailed();

        Storage::disk('backups')->assertExists('keep-me.txt');
        $this->assertSame('completed', $tampered->fresh()->status);
    }

    public function test_daily_backup_and_cleanup_are_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($e) => [trim(str($e->command)->after('artisan')->replace(["'", '"'], '')) => $e]);

        $this->assertArrayHasKey('db:backup', $events->all());
        $this->assertArrayHasKey('db:backup --cleanup', $events->all());
        $this->assertSame('0 2 * * *', $events['db:backup']->expression);
        $this->assertTrue($events['db:backup']->withoutOverlapping);
    }
}
