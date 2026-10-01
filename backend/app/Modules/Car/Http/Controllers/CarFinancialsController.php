<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Services\CarFinancials;
use App\Modules\Car\Services\CarPortfolio;
use App\Modules\Car\Services\CarTimeline;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only financial intelligence. All figures come from CarFinancials / CarPortfolio;
 * each block is returned only to users allowed to see its inputs (null otherwise).
 */
class CarFinancialsController extends Controller
{
    public function summary(Request $request, Car $car, CarFinancials $financials): JsonResponse
    {
        Gate::authorize('view', $car);

        $user = $request->user();
        $s = $financials->snapshot($car);
        $canPurchase = $user->can('viewPurchase', $car);
        $canExpenses = $user->can('viewExpenses', $car);

        return ApiResponse::success([
            'car_id' => $car->id,
            'status' => $car->status->value,
            'costs' => $canPurchase || $canExpenses ? [
                'purchase_cost' => $canPurchase ? Money::toDecimal($s->purchaseCost) : null,
                'expenses_total' => $canExpenses ? Money::toDecimal($s->expensesTotal) : null,
                'total_investment' => $canPurchase && $canExpenses ? Money::toDecimal($s->totalInvestment) : null,
            ] : null,
            'party' => $user->can('viewSale', $car) ? $financials->partyPosition($car, $s) : null,
            'dealer' => $user->can('viewDealerPayments', $car) ? $financials->dealerPosition($car, $s) : null,
            'profit' => $user->can('viewProfit', $car) ? $financials->profit($car, $s) : null,
        ]);
    }

    public function timeline(Request $request, Car $car, CarTimeline $timeline): JsonResponse
    {
        Gate::authorize('view', $car);

        return ApiResponse::success($timeline->for($car, $request->user())->all());
    }

    public function dashboard(Request $request, CarPortfolio $portfolio): JsonResponse
    {
        Gate::authorize('car.view');

        $filters = $request->validate([
            'branch_id' => ['sometimes', 'string', 'max:26'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return ApiResponse::success($portfolio->summary($request->user(), $filters));
    }
}
