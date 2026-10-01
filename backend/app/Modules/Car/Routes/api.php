<?php

use App\Modules\Car\Http\Controllers\CarController;
use App\Modules\Car\Http\Controllers\CarDocumentController;
use App\Modules\Car\Http\Controllers\CarExpenseController;
use App\Modules\Car\Http\Controllers\CarFinancialsController;
use App\Modules\Car\Http\Controllers\CarImageController;
use App\Modules\Car\Http\Controllers\CarPurchaseController;
use App\Modules\Car\Http\Controllers\CarReportController;
use App\Modules\Car\Http\Controllers\CarSaleController;
use App\Modules\Car\Http\Controllers\CarStatusController;
use App\Modules\Car\Http\Controllers\DealerController;
use App\Modules\Car\Http\Controllers\DealerPaymentController;
use App\Modules\Car\Http\Controllers\DocumentExpiryController;
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

        // Financial records: immutable; corrections via reversal.
        Route::get('cars/{car}/purchase', [CarPurchaseController::class, 'show'])->name('cars.purchase.show');
        Route::post('cars/{car}/purchases', [CarPurchaseController::class, 'store'])->name('cars.purchases.store');
        Route::post('cars/{car}/purchases/{purchase}/reverse', [CarPurchaseController::class, 'reverse'])->name('cars.purchases.reverse');
        Route::get('cars/{car}/expenses', [CarExpenseController::class, 'index'])->name('cars.expenses.index');
        Route::post('cars/{car}/expenses', [CarExpenseController::class, 'store'])->name('cars.expenses.store');
        Route::post('cars/{car}/expenses/{expense}/reverse', [CarExpenseController::class, 'reverse'])->name('cars.expenses.reverse');

        // Sale, party payments (Party Due) and dealer payments (Dealer Payable); immutable with reversal.
        Route::get('cars/{car}/sale', [CarSaleController::class, 'show'])->name('cars.sale.show');
        Route::post('cars/{car}/sales', [CarSaleController::class, 'store'])->name('cars.sales.store');
        Route::post('cars/{car}/sales/{sale}/reverse', [CarSaleController::class, 'reverse'])->name('cars.sales.reverse');
        Route::post('cars/{car}/party-payments', [CarSaleController::class, 'storePayment'])->name('cars.party-payments.store');
        Route::post('cars/{car}/party-payments/{partyPayment}/reverse', [CarSaleController::class, 'reversePayment'])->name('cars.party-payments.reverse');
        Route::get('cars/{car}/dealer-payments', [DealerPaymentController::class, 'index'])->name('cars.dealer-payments.index');
        Route::post('cars/{car}/dealer-payments', [DealerPaymentController::class, 'store'])->name('cars.dealer-payments.store');
        Route::post('cars/{car}/dealer-payments/{dealerPayment}/reverse', [DealerPaymentController::class, 'reverse'])->name('cars.dealer-payments.reverse');
        Route::post('cars/{car}/status', [CarStatusController::class, 'update'])->name('cars.status.update');

        // Compliance documents (fitness, tax token, insurance, route permit, other).
        Route::get('cars/{car}/documents', [CarDocumentController::class, 'index'])->name('cars.documents.index');
        Route::post('cars/{car}/documents', [CarDocumentController::class, 'store'])->name('cars.documents.store');
        Route::put('cars/{car}/documents/{document}', [CarDocumentController::class, 'update'])->name('cars.documents.update');
        Route::delete('cars/{car}/documents/{document}', [CarDocumentController::class, 'destroy'])->name('cars.documents.destroy');
    });

    // Financial intelligence (read-only; figures computed by CarFinancials / CarPortfolio).
    Route::get('car-dashboard', [CarFinancialsController::class, 'dashboard'])->name('car-dashboard');
    Route::get('cars/{car}/financial-summary', [CarFinancialsController::class, 'summary'])->name('cars.financial-summary');
    Route::get('cars/{car}/timeline', [CarFinancialsController::class, 'timeline'])->name('cars.timeline');

    // Reports (server-side filters, sorting, pagination and totals).
    Route::prefix('car-reports')->name('car-reports.')->controller(CarReportController::class)->group(function () {
        Route::get('cars', 'cars')->name('cars');
        Route::get('sales', 'sales')->name('sales');
        Route::get('receivables', 'receivables')->name('receivables');
        Route::get('payables', 'payables')->name('payables');
        Route::get('expenses', 'expenses')->name('expenses');
        Route::get('branches', 'branches')->name('branches');
        Route::get('{report}/export', 'export')->name('export')
            ->whereIn('report', ['cars', 'sales', 'receivables', 'payables', 'expenses', 'branches']);
    });

    Route::get('car-documents/expiring', [DocumentExpiryController::class, 'index'])->name('car-documents.expiring');
    Route::get('car-documents/expiry-summary', [DocumentExpiryController::class, 'summary'])->name('car-documents.expiry-summary');

    Route::apiResource('car-dealers', DealerController::class)->parameters(['car-dealers' => 'dealer']);
    Route::apiResource('car-parties', PartyController::class)->parameters(['car-parties' => 'party']);
    Route::apiResource('car-expense-types', ExpenseTypeController::class)->parameters(['car-expense-types' => 'expenseType']);
});
