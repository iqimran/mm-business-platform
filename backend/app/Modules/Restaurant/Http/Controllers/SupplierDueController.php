<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Restaurant\Services\ExpenseQuery;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Supplier dues: per supplier, billed / paid / due over active expenses in the user's branches.
 * Same access as viewing expenses.
 */
class SupplierDueController extends Controller
{
    public function index(Request $request, ExpenseQuery $expenses): JsonResponse
    {
        Gate::authorize('viewAny', RestaurantExpense::class);

        $filters = $request->validate([
            'branch_id' => ['sometimes', 'string', 'max:26'],
            'search' => ['sometimes', 'string', 'max:100'],
            'only_due' => ['sometimes', 'boolean'],
        ]);
        $onlyDue = $request->boolean('only_due', true);

        $perExpense = $expenses->filtered($request->user(), array_intersect_key($filters, ['branch_id' => true]) + ['state' => 'active'])
            ->toBase()
            ->whereNotNull('restaurant_expenses.supplier_id')
            ->select('restaurant_expenses.supplier_id', 'restaurant_expenses.amount_minor', 'restaurant_expenses.expense_date')
            ->selectRaw(RestaurantExpense::paidSql().' AS paid_minor');

        $rows = DB::query()->fromSub($perExpense, 'e')
            ->join('restaurant_suppliers as s', 's.id', '=', 'e.supplier_id')
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('s.name', 'ilike', "%{$term}%")->orWhere('s.phone', 'ilike', "%{$term}%")))
            ->groupBy('s.id', 's.name', 's.phone')
            ->select('s.id', 's.name', 's.phone')
            ->selectRaw('count(*) AS bills, sum(e.amount_minor) AS billed, sum(e.paid_minor) AS paid,
                count(*) FILTER (WHERE e.paid_minor < e.amount_minor) AS due_bills,
                min(e.expense_date) FILTER (WHERE e.paid_minor < e.amount_minor) AS oldest_due')
            ->when($onlyDue, fn ($q) => $q->havingRaw('sum(e.amount_minor) > sum(e.paid_minor)'))
            ->orderByRaw('sum(e.amount_minor) - sum(e.paid_minor) DESC')
            ->orderBy('s.name');

        $totals = DB::query()->fromSub($rows->clone(), 't')->selectRaw('count(*) AS suppliers, coalesce(sum(billed), 0) AS billed, coalesce(sum(paid), 0) AS paid')->first();
        $page = $rows->paginate(ApiResponse::perPage($request));

        return ApiResponse::success([
            'items' => $page->getCollection()->map(fn ($r) => [
                'supplier' => ['id' => $r->id, 'name' => $r->name, 'phone' => $r->phone],
                'bills' => (int) $r->bills,
                'due_bills' => (int) $r->due_bills,
                'billed' => Money::toDecimal((int) $r->billed),
                'paid' => Money::toDecimal((int) $r->paid),
                'due' => Money::toDecimal((int) $r->billed - (int) $r->paid),
                'oldest_due_date' => $r->oldest_due ? substr((string) $r->oldest_due, 0, 10) : null,
            ])->values()->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'totals' => [
                'suppliers' => (int) $totals->suppliers,
                'billed' => Money::toDecimal((int) $totals->billed),
                'paid' => Money::toDecimal((int) $totals->paid),
                'due' => Money::toDecimal((int) $totals->billed - (int) $totals->paid),
            ],
        ]);
    }
}
