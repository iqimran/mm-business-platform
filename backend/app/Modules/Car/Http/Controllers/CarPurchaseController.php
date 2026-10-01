<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Car\Actions\RecordCarPurchase;
use App\Modules\Car\Actions\ReverseFinancialRecord;
use App\Modules\Car\Http\Requests\RecordPurchaseRequest;
use App\Modules\Car\Http\Requests\ReverseRecordRequest;
use App\Modules\Car\Http\Resources\FinancialRecordResource;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarPurchase;
use App\Modules\Car\Services\CarCosts;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CarPurchaseController extends Controller
{
    private const RELATIONS = ['dealer:id,name', 'recorder:id,name', 'reverser:id,name'];

    public function __construct(private readonly CarCosts $costs) {}

    /**
     * Active purchase, full history (including reversed records) and cost summary.
     */
    public function show(Car $car): JsonResponse
    {
        Gate::authorize('viewPurchase', $car);

        $history = $car->purchases()->with(self::RELATIONS)->orderByDesc('created_at')->orderByDesc('id')->get();
        $active = $history->first(fn (CarPurchase $p) => ! $p->isReversed());

        return ApiResponse::success([
            'active' => $active ? FinancialRecordResource::make($active)->resolve() : null,
            'history' => FinancialRecordResource::collection($history)->resolve(),
            'costs' => $this->costs->summary($car),
        ]);
    }

    public function store(RecordPurchaseRequest $request, Car $car, RecordCarPurchase $record): JsonResponse
    {
        $purchase = $record->handle($request->user(), $car, $request->validated());

        return ApiResponse::success(FinancialRecordResource::make($purchase->load(self::RELATIONS))->resolve(), 'Purchase recorded successfully.', 201);
    }

    public function reverse(ReverseRecordRequest $request, Car $car, CarPurchase $purchase, ReverseFinancialRecord $reverse): JsonResponse
    {
        $purchase = $reverse->handle($request->user(), $purchase, $request->validated('reason'));

        return ApiResponse::success(FinancialRecordResource::make($purchase->load(self::RELATIONS))->resolve(), 'Purchase reversed successfully.');
    }
}
