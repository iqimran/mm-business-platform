<?php

use App\Modules\Administration\Http\Controllers\BusinessProfileController;
use App\Modules\Administration\Http\Controllers\PermissionController;
use App\Modules\Administration\Http\Controllers\RoleController;
use App\Modules\Administration\Http\Controllers\SettingController;
use App\Modules\Administration\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 (routes/api.php). Authorization is enforced per action (policies/permissions).
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::apiResource('users', UserController::class);
    Route::put('users/{user}/roles', [UserController::class, 'syncRoles'])->name('users.roles.sync');
    Route::put('users/{user}/branches', [UserController::class, 'syncBranches'])->name('users.branches.sync');

    Route::apiResource('roles', RoleController::class);
    Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');

    Route::get('business-profiles', [BusinessProfileController::class, 'index'])->name('business-profiles.index');
    Route::get('business-profiles/{module}', [BusinessProfileController::class, 'show'])->name('business-profiles.show')->whereIn('module', ['car', 'restaurant']);
    Route::put('business-profiles/{module}', [BusinessProfileController::class, 'update'])->name('business-profiles.update')->whereIn('module', ['car', 'restaurant']);
    Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
    Route::get('settings/{key}', [SettingController::class, 'show'])->name('settings.show')
        ->where('key', '[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+');
    Route::put('settings/{key}', [SettingController::class, 'update'])->name('settings.update')
        ->where('key', '[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+');
});
