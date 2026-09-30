<?php

use App\Modules\Identity\Http\Controllers\AuthenticatedSessionController;
use App\Modules\Identity\Http\Controllers\CurrentUserController;
use App\Modules\Identity\Http\Controllers\PasswordController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 (routes/api.php).
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')->name('login');
    Route::post('forgot-password', [PasswordController::class, 'sendResetLink'])
        ->middleware('throttle:password-reset')->name('password.email');
    Route::post('reset-password', [PasswordController::class, 'reset'])
        ->middleware('throttle:password-reset')->name('password.update');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('me', CurrentUserController::class)->name('me');
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
        Route::put('password', [PasswordController::class, 'update'])
            ->middleware('throttle:password-change')->name('password.change');
    });
});
