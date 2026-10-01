<?php

use App\Modules\Restaurant\Http\Controllers\CustomerController;
use App\Modules\Restaurant\Http\Controllers\ExpenseCategoryController;
use App\Modules\Restaurant\Http\Controllers\FoodSaleController;
use App\Modules\Restaurant\Http\Controllers\HallBookingController;
use App\Modules\Restaurant\Http\Controllers\HallController;
use App\Modules\Restaurant\Http\Controllers\MenuCategoryController;
use App\Modules\Restaurant\Http\Controllers\MenuItemController;
use App\Modules\Restaurant\Http\Controllers\RestaurantExpenseController;
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

    // Halls (branch-scoped master data) and hall bookings. Explicit actions for the booking workflow.
    Route::apiResource('halls', HallController::class);
    Route::get('hall-availability', [HallBookingController::class, 'availability'])->name('hall-availability');
    Route::get('hall-bookings', [HallBookingController::class, 'index'])->name('hall-bookings.index');
    Route::post('hall-bookings', [HallBookingController::class, 'store'])->name('hall-bookings.store');
    Route::scopeBindings()->group(function () {
        Route::get('hall-bookings/{booking}', [HallBookingController::class, 'show'])->name('hall-bookings.show');
        Route::match(['put', 'patch'], 'hall-bookings/{booking}', [HallBookingController::class, 'update'])->name('hall-bookings.update');
        Route::post('hall-bookings/{booking}/cancel', [HallBookingController::class, 'cancel'])->name('hall-bookings.cancel');
        Route::post('hall-bookings/{booking}/complete', [HallBookingController::class, 'complete'])->name('hall-bookings.complete');
        Route::post('hall-bookings/{booking}/payments', [HallBookingController::class, 'storePayment'])->name('hall-bookings.payments.store');
        Route::post('hall-bookings/{booking}/payments/{payment}/reverse', [HallBookingController::class, 'reversePayment'])->name('hall-bookings.payments.reverse');
    });

    // Daily category-wise expenses (branch-scoped). Immutable: corrections via reversal.
    Route::apiResource('expense-categories', ExpenseCategoryController::class)->parameters(['expense-categories' => 'expenseCategory']);
    Route::get('expense-summary', [RestaurantExpenseController::class, 'summary'])->name('expense-summary');
    Route::get('expenses', [RestaurantExpenseController::class, 'index'])->name('expenses.index');
    Route::post('expenses', [RestaurantExpenseController::class, 'store'])->name('expenses.store');
    Route::get('expenses/{expense}', [RestaurantExpenseController::class, 'show'])->name('expenses.show');
    Route::post('expenses/{expense}/reverse', [RestaurantExpenseController::class, 'reverse'])->name('expenses.reverse');
});
