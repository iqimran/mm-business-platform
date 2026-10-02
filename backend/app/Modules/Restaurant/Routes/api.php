<?php

use App\Modules\Restaurant\Http\Controllers\CustomerController;
use App\Modules\Restaurant\Http\Controllers\EventMenuItemController;
use App\Modules\Restaurant\Http\Controllers\ExpenseCategoryController;
use App\Modules\Restaurant\Http\Controllers\FoodSaleController;
use App\Modules\Restaurant\Http\Controllers\HallBookingController;
use App\Modules\Restaurant\Http\Controllers\HallController;
use App\Modules\Restaurant\Http\Controllers\MenuCategoryController;
use App\Modules\Restaurant\Http\Controllers\MenuItemController;
use App\Modules\Restaurant\Http\Controllers\PaymentReceiptController;
use App\Modules\Restaurant\Http\Controllers\RestaurantExpenseController;
use App\Modules\Restaurant\Http\Controllers\RestaurantReportController;
use App\Modules\Restaurant\Http\Controllers\SupplierController;
use App\Modules\Restaurant\Http\Controllers\SupplierDueController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1. Restaurant module: isolated from Car; permission gates per entity.
Route::middleware(['auth:sanctum', 'active'])->prefix('restaurant')->name('restaurant.')->group(function () {
    // Master data shared by all branches. Customers/suppliers are deactivated, never deleted.
    Route::apiResource('customers', CustomerController::class)->except('destroy');
    Route::apiResource('suppliers', SupplierController::class)->except('destroy');
    Route::apiResource('menu-categories', MenuCategoryController::class)->parameters(['menu-categories' => 'menuCategory']);
    Route::apiResource('menu-items', MenuItemController::class)->parameters(['menu-items' => 'menuItem']);
    // Items for hall booking food packages (no prices; packages are priced per head).
    Route::apiResource('event-menu-items', EventMenuItemController::class)->parameters(['event-menu-items' => 'eventMenuItem']);

    // Food sales (branch-scoped). Immutable: corrections via reversal.
    Route::get('sales', [FoodSaleController::class, 'index'])->name('sales.index');
    Route::post('sales', [FoodSaleController::class, 'store'])->name('sales.store');
    Route::scopeBindings()->group(function () {
        Route::get('sales/{sale}', [FoodSaleController::class, 'show'])->name('sales.show');
        Route::post('sales/{sale}/reverse', [FoodSaleController::class, 'reverse'])->name('sales.reverse');
        Route::post('sales/{sale}/payments', [FoodSaleController::class, 'storePayment'])->name('sales.payments.store');
        Route::post('sales/{sale}/payments/{payment}/reverse', [FoodSaleController::class, 'reversePayment'])->name('sales.payments.reverse');
        Route::get('sales/{sale}/payments/{payment}/receipt', [PaymentReceiptController::class, 'salePayment'])->name('sales.payments.receipt');
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
        Route::get('hall-bookings/{booking}/payments/{payment}/receipt', [PaymentReceiptController::class, 'bookingPayment'])->name('hall-bookings.payments.receipt');
    });

    // Daily category-wise expenses (branch-scoped). Immutable: corrections via reversal.
    Route::apiResource('expense-categories', ExpenseCategoryController::class)->parameters(['expense-categories' => 'expenseCategory']);
    Route::get('expense-summary', [RestaurantExpenseController::class, 'summary'])->name('expense-summary');
    Route::get('expenses', [RestaurantExpenseController::class, 'index'])->name('expenses.index');
    Route::post('expenses', [RestaurantExpenseController::class, 'store'])->name('expenses.store');
    Route::get('expenses/{expense}', [RestaurantExpenseController::class, 'show'])->name('expenses.show');
    Route::post('expenses/{expense}/reverse', [RestaurantExpenseController::class, 'reverse'])->name('expenses.reverse');
    // Supplier dues: pay a supplier bill (expense) / reverse a payment / print the voucher.
    Route::get('supplier-dues', [SupplierDueController::class, 'index'])->name('supplier-dues.index');
    Route::scopeBindings()->group(function () {
        Route::post('expenses/{expense}/payments', [RestaurantExpenseController::class, 'storePayment'])->name('expenses.payments.store');
        Route::post('expenses/{expense}/payments/{payment}/reverse', [RestaurantExpenseController::class, 'reversePayment'])->name('expenses.payments.reverse');
        Route::get('expenses/{expense}/payments/{payment}/voucher', [PaymentReceiptController::class, 'expensePayment'])->name('expenses.payments.voucher');
    });

    // Reports (read-only; server-side filters, sorting, pagination and totals).
    Route::get('reports/summary', [RestaurantReportController::class, 'summary'])->name('reports.summary');
    Route::get('reports/{report}', [RestaurantReportController::class, 'show'])->name('reports.show')
        ->whereIn('report', ['sales', 'bookings', 'expenses']);
    Route::get('reports/{report}/export', [RestaurantReportController::class, 'export'])->name('reports.export')
        ->whereIn('report', ['sales', 'bookings', 'expenses', 'summary']);
});
