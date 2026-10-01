<?php

use App\Modules\Administration\Backups\Http\Controllers\DatabaseBackupController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1. Global system operation: permission-based, not branch-scoped.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('database-backups', [DatabaseBackupController::class, 'index'])->name('database-backups.index');
    Route::post('database-backups', [DatabaseBackupController::class, 'store'])
        ->middleware('throttle:6,1')->name('database-backups.store');
    Route::get('database-backups/{databaseBackup}', [DatabaseBackupController::class, 'show'])->name('database-backups.show');
    Route::get('database-backups/{databaseBackup}/download', [DatabaseBackupController::class, 'download'])
        ->middleware('throttle:20,1')->name('database-backups.download');
});
