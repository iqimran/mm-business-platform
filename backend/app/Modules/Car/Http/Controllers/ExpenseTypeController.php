<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Car\Http\Requests\ExpenseTypeRequest;
use App\Modules\Car\Http\Resources\ExpenseTypeResource;
use App\Modules\Car\Models\CarExpenseType;
use App\Modules\Car\Services\MasterRecordService;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Car expense types. Shared master data: permission-based, not branch-scoped.
 */
class ExpenseTypeController extends Controller
{
    private const PERMISSION = 'car.expense_type';

    private const ENTITY = 'car_expense_type';

    public function __construct(private readonly MasterRecordService $records) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $records = CarExpenseType::query()
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($records, ExpenseTypeResource::class);
    }

    public function store(ExpenseTypeRequest $request): JsonResponse
    {
        $record = $this->records->create($request->user(), CarExpenseType::class, self::ENTITY, $request->validated());

        return ApiResponse::success(ExpenseTypeResource::make($record)->resolve(), 'Expense type created successfully.', 201);
    }

    public function show(CarExpenseType $expenseType): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.view');

        return ApiResponse::success(ExpenseTypeResource::make($expenseType)->resolve());
    }

    public function update(ExpenseTypeRequest $request, CarExpenseType $expenseType): JsonResponse
    {
        $record = $this->records->update($request->user(), $expenseType, self::ENTITY, $request->validated());

        return ApiResponse::success(ExpenseTypeResource::make($record)->resolve(), 'Expense type updated successfully.');
    }

    public function destroy(Request $request, CarExpenseType $expenseType): JsonResponse
    {
        Gate::authorize(self::PERMISSION.'.delete');

        $this->records->delete($request->user(), $expenseType, self::ENTITY, null);

        return ApiResponse::success(message: 'Expense type deleted successfully.');
    }
}
