<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Http\Requests\ExpenseCategoryRequest;
use App\Modules\Restaurant\Http\Resources\ExpenseCategoryResource;
use App\Modules\Restaurant\Models\ExpenseCategory;
use App\Modules\Restaurant\Services\MasterRecordService;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Restaurant expense categories: configurable master data shared by all branches (permission-based).
 */
class ExpenseCategoryController extends Controller
{
    private const PERMISSION = 'restaurant.expense_category';

    private const ENTITY = 'restaurant_expense_category';

    public function __construct(private readonly MasterRecordService $records) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $records = ExpenseCategory::query()
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'ilike', "%{$search}%"))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($records, ExpenseCategoryResource::class);
    }

    public function store(ExpenseCategoryRequest $request): JsonResponse
    {
        $record = $this->records->create($request->user(), ExpenseCategory::class, self::ENTITY, $request->validated());

        return ApiResponse::success(ExpenseCategoryResource::make($record)->resolve(), 'Expense category created successfully.', 201);
    }

    public function show(ExpenseCategory $expenseCategory): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        return ApiResponse::success(ExpenseCategoryResource::make($expenseCategory)->resolve());
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        $record = $this->records->update($request->user(), $expenseCategory, self::ENTITY, $request->validated());

        return ApiResponse::success(ExpenseCategoryResource::make($record)->resolve(), 'Expense category updated successfully.');
    }

    public function destroy(Request $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.delete');

        $this->records->delete($request->user(), $expenseCategory, self::ENTITY, $expenseCategory->expenses()->exists()
            ? 'This category is used by expenses. Deactivate it instead.'
            : null);

        return ApiResponse::success(message: 'Expense category deleted successfully.');
    }
}
