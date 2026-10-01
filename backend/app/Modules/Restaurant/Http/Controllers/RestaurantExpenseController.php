<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Actions\RecordRestaurantExpense;
use App\Modules\Restaurant\Actions\ReverseRestaurantExpense;
use App\Modules\Restaurant\Http\Requests\ReverseRestaurantExpenseRequest;
use App\Modules\Restaurant\Http\Requests\StoreRestaurantExpenseRequest;
use App\Modules\Restaurant\Http\Resources\RestaurantExpenseResource;
use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Restaurant\Services\ExpenseQuery;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Restaurant expenses (RestaurantExpensePolicy: permission + branch access) and the daily category-wise summary.
 */
class RestaurantExpenseController extends Controller
{
    private const RELATIONS = ['branch:id,name,code', 'category:id,name', 'supplier:id,name', 'recorder:id,name', 'reverser:id,name'];

    /** Longest range for the daily summary (keeps the grouped result small). */
    private const SUMMARY_MAX_DAYS = 366;

    public function index(Request $request, ExpenseQuery $expenses): JsonResponse
    {
        Gate::authorize('viewAny', RestaurantExpense::class);

        $filtered = $expenses->filtered($request->user(), $this->filters($request));
        $page = $filtered->clone()
            ->with(self::RELATIONS)
            ->orderByDesc('restaurant_expenses.expense_date')
            ->orderByDesc('restaurant_expenses.id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::success([
            'items' => RestaurantExpenseResource::collection($page->getCollection())->resolve(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'summary' => $expenses->totals($filtered),
        ]);
    }

    /** Daily category-wise totals for a date or date range (default: today). */
    public function summary(Request $request, ExpenseQuery $expenses): JsonResponse
    {
        Gate::authorize('viewAny', RestaurantExpense::class);

        $filters = $this->filters($request);
        if (! isset($filters['date']) && ! isset($filters['date_from']) && ! isset($filters['date_to'])) {
            $filters['date'] = now()->toDateString();
        }
        $from = $filters['date'] ?? $filters['date_from'] ?? null;
        $to = $filters['date'] ?? $filters['date_to'] ?? null;
        if ($from === null || $to === null) {
            throw ValidationException::withMessages(['date_to' => 'Give both a start and an end date (or a single date).']);
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) >= self::SUMMARY_MAX_DAYS) {
            throw ValidationException::withMessages(['date_to' => 'The summary range can be at most '.self::SUMMARY_MAX_DAYS.' days.']);
        }

        unset($filters['state']);

        return ApiResponse::success(['date_from' => $from, 'date_to' => $to]
            + $expenses->dailyCategorySummary($expenses->filtered($request->user(), $filters)));
    }

    public function store(StoreRestaurantExpenseRequest $request, RecordRestaurantExpense $record): JsonResponse
    {
        $expense = $record->handle($request->user(), $request->validated());

        return ApiResponse::success(RestaurantExpenseResource::make($expense->load(self::RELATIONS))->resolve(), 'Expense recorded successfully.', 201);
    }

    public function show(RestaurantExpense $expense): JsonResponse
    {
        Gate::authorize('view', $expense);

        return ApiResponse::success(RestaurantExpenseResource::make($expense->load(self::RELATIONS))->resolve());
    }

    public function reverse(ReverseRestaurantExpenseRequest $request, RestaurantExpense $expense, ReverseRestaurantExpense $reverse): JsonResponse
    {
        $expense = $reverse->handle($request->user(), $expense, $request->validated('reason'));

        return ApiResponse::success(RestaurantExpenseResource::make($expense->load(self::RELATIONS))->resolve(), 'Expense reversed successfully.');
    }

    /**
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'branch_id' => ['sometimes', 'string', 'max:26'],
            'category_id' => ['sometimes', 'string', 'max:26'],
            'supplier_id' => ['sometimes', 'string', 'max:26'],
            'date' => ['sometimes', 'date_format:Y-m-d', 'prohibits:date_from,date_to'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'state' => ['sometimes', Rule::in(['active', 'reversed'])],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);
    }
}
