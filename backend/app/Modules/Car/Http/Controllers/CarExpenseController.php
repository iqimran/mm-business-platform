<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Car\Actions\RecordCarExpense;
use App\Modules\Car\Actions\ReverseFinancialRecord;
use App\Modules\Car\Http\Requests\RecordExpenseRequest;
use App\Modules\Car\Http\Requests\ReverseRecordRequest;
use App\Modules\Car\Http\Resources\FinancialRecordResource;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarExpense;
use App\Modules\Car\Services\CarCosts;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CarExpenseController extends Controller
{
    private const RELATIONS = ['expenseType:id,name', 'recorder:id,name', 'reverser:id,name'];

    public function __construct(private readonly CarCosts $costs) {}

    /**
     * All expenses (reversed ones flagged) plus the cost summary from active records.
     */
    public function index(Car $car): JsonResponse
    {
        Gate::authorize('viewExpenses', $car);

        $expenses = $car->expenses()->with(self::RELATIONS)
            ->orderByDesc('expense_date')->orderByDesc('created_at')->orderByDesc('id')
            ->get();

        return ApiResponse::success([
            'items' => FinancialRecordResource::collection($expenses)->resolve(),
            'costs' => $this->costs->summary($car),
        ]);
    }

    public function store(RecordExpenseRequest $request, Car $car, RecordCarExpense $record): JsonResponse
    {
        $expense = $record->handle($request->user(), $car, $request->validated());

        return ApiResponse::success(FinancialRecordResource::make($expense->load(self::RELATIONS))->resolve(), 'Expense recorded successfully.', 201);
    }

    public function reverse(ReverseRecordRequest $request, Car $car, CarExpense $expense, ReverseFinancialRecord $reverse): JsonResponse
    {
        $expense = $reverse->handle($request->user(), $expense, $request->validated('reason'));

        return ApiResponse::success(FinancialRecordResource::make($expense->load(self::RELATIONS))->resolve(), 'Expense reversed successfully.');
    }
}
