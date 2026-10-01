<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Daily automatic PostgreSQL backup, then retention cleanup (run by the "scheduler" container).
Schedule::command('db:backup')
    ->dailyAt(config('backup.daily_at'))
    ->withoutOverlapping(config('backup.timeout') / 60 + 5)
    ->onOneServer()
    ->name('daily-database-backup');

Schedule::command('db:backup --cleanup')
    ->dailyAt(config('backup.daily_at'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('database-backup-retention');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
