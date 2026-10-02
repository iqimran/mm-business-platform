<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Reports\BookingsReport;
use App\Modules\Restaurant\Reports\ExpensesReport;
use App\Modules\Restaurant\Reports\FinancialSummary;
use App\Modules\Restaurant\Reports\RestaurantReport;
use App\Modules\Restaurant\Reports\SalesReport;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Restaurant reports: restaurant.report.view plus the view permission of the reported area.
 * All figures are branch-scoped to the user's accessible branches.
 */
class RestaurantReportController extends Controller
{
    private const REPORTS = [
        'sales' => SalesReport::class,
        'bookings' => BookingsReport::class,
        'expenses' => ExpensesReport::class,
    ];

    public function show(Request $request, string $report): JsonResponse
    {
        Gate::authorize('restaurant.report.view');

        /** @var RestaurantReport $instance */
        $instance = app(self::REPORTS[$report]);
        Gate::authorize($instance->permission());

        return ApiResponse::success($instance->run($request->user(), $request));
    }

    public function summary(Request $request, FinancialSummary $summary): JsonResponse
    {
        Gate::authorize('restaurant.report.view');

        $period = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d', 'prohibits:date_from,date_to'],
            // No "sometimes": required_with must run when only one end of the range is given.
            'date_from' => ['required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'branch_id' => ['sometimes', 'string', 'max:26'],
        ]);

        // Default period: the current month up to today.
        $from = $period['date'] ?? $period['date_from'] ?? now()->startOfMonth()->toDateString();
        $to = $period['date'] ?? $period['date_to'] ?? now()->toDateString();

        return ApiResponse::success($summary->build($request->user(), [
            'date_from' => $from,
            'date_to' => $to,
            'branch_id' => $period['branch_id'] ?? null,
        ]));
    }
}
