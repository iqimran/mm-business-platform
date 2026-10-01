<?php

use App\Modules\Branch\Http\Controllers\BranchController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1. Branches are deactivated, never deleted.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::apiResource('branches', BranchController::class)->except('destroy');
});
