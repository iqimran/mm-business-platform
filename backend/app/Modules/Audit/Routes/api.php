<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1. Audit logs are read-only.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
});
