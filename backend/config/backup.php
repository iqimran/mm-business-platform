<?php

/*
| Database backups (pg_dump, plain SQL). Files are stored on a private filesystem disk;
| point BACKUP_PATH at a separate (ideally off-server or mounted) location in production.
*/
return [
    // Filesystem disk holding backup files (see config/filesystems.php "backups").
    'disk' => env('BACKUP_DISK', 'backups'),

    // Directory (key prefix) inside the disk.
    'directory' => 'database',

    // Days a completed backup is kept before automatic cleanup removes it.
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    // Cleanup never removes the newest N completed backups, even if expired.
    'keep_minimum' => (int) env('BACKUP_KEEP_MINIMUM', 1),

    // Daily automatic backup time (server timezone, HH:MM).
    'daily_at' => env('BACKUP_DAILY_AT', '02:00'),

    // Maximum seconds a single pg_dump may run.
    'timeout' => (int) env('BACKUP_TIMEOUT', 3600),

    // pg_dump executable; must match the PostgreSQL server major version.
    'pg_dump_binary' => env('BACKUP_PG_DUMP_BINARY', 'pg_dump'),

    // Database connection to back up.
    'connection' => env('BACKUP_DB_CONNECTION', 'pgsql'),
];
