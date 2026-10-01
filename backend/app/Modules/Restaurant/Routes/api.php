<?php

use App\Modules\Restaurant\Http\Controllers\CustomerController;
use App\Modules\Restaurant\Http\Controllers\FoodSaleController;
use App\Modules\Restaurant\Http\Controllers\MenuCategoryController;
use App\Modules\Restaurant\Http\Controllers\MenuItemController;
use App\Modules\Restaurant\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1. Restaurant module: isolated from Car; permission gates per entity.
Route::middleware(['auth:sanctum', 'active'])->prefix('restaurant')->name('restaurant.')->group(function () {
    // Master data shared by all branches. Customers/suppliers are deactivated, never deleted.
    Route::apiResource('customers', CustomerController::class)->except('destroy');
    Route::apiResource('suppliers', SupplierController::class)->except('destroy');
    Route::apiResource('menu-categories', MenuCategoryController::class)->parameters(['menu-categories' => 'menuCategory']);
    Route::apiResource('menu-items', MenuItemController::class)->parameters(['menu-items' => 'menuItem']);

    // Food sales (branch-scoped). Immutable: corrections via reversal.
    Route::get('sales', [FoodSaleController::class, 'index'])->name('sales.index');
    Route::post('sales', [FoodSaleController::class, 'store'])->name('sales.store');
    Route::scopeBindings()->group(function () {
        Route::get('sales/{sale}', [FoodSaleController::class, 'show'])->name('sales.show');
        Route::post('sales/{sale}/reverse', [FoodSaleController::class, 'reverse'])->name('sales.reverse');
        Route::post('sales/{sale}/payments', [FoodSaleController::class, 'storePayment'])->name('sales.payments.store');
        Route::post('sales/{sale}/payments/{payment}/reverse', [FoodSaleController::class, 'reversePayment'])->name('sales.payments.reverse');
    });
});
