# Database Backups & Restore

Real PostgreSQL backups made with `pg_dump` (plain SQL). Use this guide to find, create, download and
**restore** a backup when the application database is lost or corrupted.

> Backup files contain the **entire business database** (users, password hashes, financial records).
> Treat them like the production database itself.

---

## 1. Where backups are stored

| Item | Value |
|---|---|
| Storage | Laravel filesystem disk `backups` (`config/filesystems.php`), private, never web-served |
| Directory | `${BACKUP_PATH}/database/` |
| Local default | `backend/storage/app/backups/database/` on the host (bind-mounted into the containers) |
| File name | `database-YYYY-MM-DD-HHmmss-<8 random chars>.sql`, e.g. `database-2026-10-01-180500-pilv7yl9.sql` (UTC time) |
| File permissions | `0600` files, `0700` directories (owner only) |
| Metadata | table `database_backups` (status, size, SHA-256 checksum, who/when) |

Configuration (environment variables, see `backend/config/backup.php`):

| Variable | Default | Meaning |
|---|---|---|
| `BACKUP_PATH` | `storage/app/backups` | Root of the backups disk. **Production: point at a separate mounted volume.** |
| `BACKUP_DISK` | `backups` | Disk name (switch to an S3-compatible disk later without code changes) |
| `BACKUP_RETENTION_DAYS` | `30` | Completed backups older than this are deleted automatically |
| `BACKUP_KEEP_MINIMUM` | `1` | The newest N completed backups are never deleted, even if expired |
| `BACKUP_DAILY_AT` | `02:00` | Daily backup time (server timezone) |
| `BACKUP_TIMEOUT` | `3600` | Max seconds per `pg_dump` |
| `BACKUP_PG_DUMP_BINARY` | `pg_dump` | Must match the server's PostgreSQL **major** version (17) |

The backups directory is outside `backend/public` (the only directory nginx serves), so `.sql` files are
never reachable by URL. Downloads go through the authenticated API only.

## 2. Automatic daily backup

- The `scheduler` container runs `php artisan schedule:work`.
- `routes/console.php` schedules `db:backup` daily at `BACKUP_DAILY_AT`, followed by `db:backup --cleanup` (retention).
- Overlapping runs are prevented (`withoutOverlapping` + a Redis lock shared with manual backups).
- Check the schedule: `docker compose exec backend php artisan schedule:list`.

## 3. Creating a backup manually

**In the application:** Administration → **Database backups** → **Back up now** (permission `database.backup.create`).
The backup runs in the background on the `queue` container; the list refreshes automatically.

**From the command line** (immediately, no queue):

```bash
docker compose exec backend php artisan db:backup            # recorded as "automatic"
docker compose exec backend php artisan db:backup --manual   # recorded as "manual"
docker compose exec backend php artisan db:backup --list     # recent backups and their status
docker compose exec backend php artisan db:backup --cleanup  # apply the retention policy now
```

## 4. How to recognise a good backup

A backup is valid only when **all** of these hold:

1. Status is **Completed** (UI / `db:backup --list`). Failed backups show the reason and have no file.
2. The file exists and is non-empty.
3. The file ends with the pg_dump completion marker (the system refuses to mark a backup completed without it):
   ```bash
   grep -c -- '-- PostgreSQL database dump complete' database-....sql   # must print 1
   ```
4. The checksum matches the stored SHA-256 (shown in `db:backup` output / API `checksum_sha256`):
   ```bash
   shasum -a 256 database-....sql
   ```

## 5. Copying / downloading a backup safely

- **UI:** Database backups → **Download** (permission `database.backup.download`; every download is audit-logged).
- **API:** `GET /api/v1/database-backups/{id}/download` (authenticated session).
- **Server/host:** copy from `${BACKUP_PATH}/database/` with `scp`/`rsync` over SSH.

Never send backups by email or chat, never put them in a public bucket or folder, and delete local copies when done.

## 6. Restoring a backup

### Requirements and compatibility

- Restore with **`psql` 17.6 or newer** (the server and backups are PostgreSQL 17.11).
  Dumps from current `pg_dump` start with a `\restrict` line; older `psql` builds stop with
  `invalid command \restrict`. Restoring into a **newer** major version (e.g. 18) is supported; into an **older** major version it is not.
- The dump uses `--no-owner --no-privileges`: objects are created owned by the user running the restore, so any database user can restore it.
- The dump contains no passwords or connection settings for the server (user **password hashes** in the `users` table are data and are included).

### ⚠️ Before you restore

- **Always restore into a new, empty database.** The dump does not contain `DROP` statements: restoring into a database that already has tables fails with "already exists" errors (by design, so nothing is overwritten by accident).
- Stop the application (or at least the `queue` and `scheduler` containers) before switching it to a restored database.
- Make a backup of the current (damaged) database first if it is still readable.

### Procedure (tested)

```text
Backup file  →  create an empty database  →  psql restore (stop on first error)  →  verify  →  switch the app
```

Run from the project root (uses the PostgreSQL 17 client inside the `postgres` container):

```bash
# 1. Create an empty target database
docker compose exec -T postgres psql -U mm_platform -d postgres \
  -c "CREATE DATABASE mm_restore OWNER mm_platform"

# 2. Restore (ON_ERROR_STOP aborts on the first error instead of continuing half-restored)
docker compose exec -T postgres psql -U mm_platform -d mm_restore -v ON_ERROR_STOP=1 -q \
  < backend/storage/app/backups/database/database-2026-10-01-180118-pyjvxvox.sql
echo "exit code: $?"    # must be 0
```

Outside Docker (any machine with psql ≥ 17.6):

```bash
createdb -h HOST -U USER mm_restore
psql -h HOST -U USER -d mm_restore -v ON_ERROR_STOP=1 -f database-....sql
```

### Verify the restored database

```bash
docker compose exec -T postgres psql -U mm_platform -d mm_restore -c "
  select (select count(*) from migrations)  as migrations,
         (select count(*) from users)       as users,
         (select count(*) from branches)    as branches,
         (select count(*) from cars)        as cars,
         (select count(*) from car_sales)   as car_sales;"

# Financial figures are recomputed by the database view:
docker compose exec -T postgres psql -U mm_platform -d mm_restore -c \
  "select brand, total_investment, party_due, dealer_payable, profit from car_financial_positions limit 10;"

# Integrity triggers are restored (should print 6 or more):
docker compose exec -T postgres psql -U mm_platform -d mm_restore -tc \
  "select count(*) from pg_trigger where not tgisinternal;"
```

Compare the counts with the last known state (e.g. the reports/dashboard) and check the latest
`audit_logs` entries to see up to when data exists. Note: a backup never contains its own
`backup.completed` audit entry (it is written after the dump finishes).

### Switch the application to the restored database

Point `DB_DATABASE` (root `.env`) at the restored database, or rename databases while the app is stopped:

```bash
docker compose stop backend queue scheduler
docker compose exec -T postgres psql -U mm_platform -d postgres \
  -c "ALTER DATABASE mm_platform RENAME TO mm_platform_broken" \
  -c "ALTER DATABASE mm_restore RENAME TO mm_platform"
docker compose start backend queue scheduler
```

### Migrations after a restore — only when appropriate

- First check: `docker compose exec backend php artisan migrate:status`.
- If the restored database is from the **same** application version, all migrations show *Ran*: **do not run anything**.
- If the backup is **older than the deployed code**, run `php artisan migrate` (only forward migrations) **after** verifying the restore. Read the pending migrations first.
- **Never** run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` or `db:seed` against a restored database: they delete or overwrite the data you just recovered.
- Do not restore a backup from a **newer** application version into older code.

### Clean up a verification database

```bash
docker compose exec -T postgres psql -U mm_platform -d postgres -c "DROP DATABASE mm_restore"
```

## 7. Failure handling

- A failed dump (connection error, timeout, missing tool, incomplete output) is marked **Failed** with a safe reason (credentials and server paths removed), logged (`storage/logs`), and audit-logged. Partial files are deleted; they are never presented as backups.
- A queue worker crash/timeout also marks the backup **Failed** instead of leaving it "running".
- Only one backup runs at a time; a second manual request is refused (409) while one is in progress.

## 8. Retention

`db:backup --cleanup` (scheduled daily) deletes completed backups whose completion is older than
`BACKUP_RETENTION_DAYS`, never touching the newest `BACKUP_KEEP_MINIMUM` backups or any file outside the
`database/database-*.sql` naming scheme. Metadata is kept with status **deleted** for history, and only
after the file has been removed.

## 9. Permissions & audit

| Permission | Allows |
|---|---|
| `database.backup.view` | Backup history and status |
| `database.backup.create` | Manual backups |
| `database.backup.download` | Downloading files (whole database!) |

Backups are a **global** system operation (a dump contains every branch), so these permissions are not
branch-scoped; grant them only to people allowed to see all data.

Audit actions: `backup.requested`, `backup.started`, `backup.completed`, `backup.failed`,
`backup.downloaded`, `backup.deleted`. They never contain credentials.

## 10. Production notes

- Mount a dedicated volume (ideally separate disk/host) and set `BACKUP_PATH` to it; copy backups off the server regularly. A backup stored only on the database server does not survive losing that server.
- Keep the `scheduler` and `queue` processes running (supervisor/systemd or the Compose services).
- Test a restore periodically using the procedure above.
