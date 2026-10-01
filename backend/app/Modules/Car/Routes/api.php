<?php

use App\Modules\Car\Http\Controllers\CarController;
use App\Modules\Car\Http\Controllers\CarImageController;
use App\Modules\Car\Http\Controllers\DealerController;
use App\Modules\Car\Http\Controllers\ExpenseTypeController;
use App\Modules\Car\Http\Controllers\PartyController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1. Authorization: CarPolicy (permission + branch) and permission gates.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::apiResource('cars', CarController::class);

    Route::scopeBindings()->group(function () {
        Route::post('cars/{car}/images', [CarImageController::class, 'store'])->name('cars.images.store');
        Route::get('cars/{car}/images/{image}/file', [CarImageController::class, 'file'])->name('cars.images.file');
        Route::delete('cars/{car}/images/{image}', [CarImageController::class, 'destroy'])->name('cars.images.destroy');
    });

    Route::apiResource('car-dealers', DealerController::class)->parameters(['car-dealers' => 'dealer']);
    Route::apiResource('car-parties', PartyController::class)->parameters(['car-parties' => 'party']);
    Route::apiResource('car-expense-types', ExpenseTypeController::class)->parameters(['car-expense-types' => 'expenseType']);
});
