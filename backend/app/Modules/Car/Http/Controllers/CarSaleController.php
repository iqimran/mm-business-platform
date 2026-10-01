<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Car\Actions\RecordCarSale;
use App\Modules\Car\Actions\RecordPayment;
use App\Modules\Car\Actions\ReverseFinancialRecord;
use App\Modules\Car\Http\Requests\RecordPaymentRequest;
use App\Modules\Car\Http\Requests\RecordSaleRequest;
use App\Modules\Car\Http\Requests\ReverseRecordRequest;
use App\Modules\Car\Http\Resources\FinancialRecordResource;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarPartyPayment;
use App\Modules\Car\Models\CarSale;
use App\Modules\Car\Services\CarFinancials;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Sale of a car and the payments received from the party (customer).
 */
class CarSaleController extends Controller
{
    private const SALE_RELATIONS = ['party:id,name,phone', 'recorder:id,name', 'reverser:id,name'];

    private const PAYMENT_RELATIONS = ['recorder:id,name', 'reverser:id,name'];

    public function __construct(private readonly CarFinancials $financials) {}

    /**
     * Active sale, sale history, payments of the active sale and the party position.
     * Profit is included only for users allowed to see purchase cost and expenses.
     */
    public function show(Request $request, Car $car): JsonResponse
    {
        Gate::authorize('viewSale', $car);

        $history = $car->sales()->with(self::SALE_RELATIONS)->orderByDesc('created_at')->orderByDesc('id')->get();
        $active = $history->first(fn (CarSale $s) => ! $s->isReversed());
        $canSeePayments = $request->user()->can('viewPartyPayments', $car);

        return ApiResponse::success([
            'status' => $car->status->value,
            'active' => $active ? FinancialRecordResource::make($active)->resolve() : null,
            'history' => FinancialRecordResource::collection($history)->resolve(),
            'payments' => $active && $canSeePayments
                ? FinancialRecordResource::collection(
                    $active->payments()->with(self::PAYMENT_RELATIONS)->orderByDesc('payment_date')->orderByDesc('created_at')->get()
                )->resolve()
                : [],
            'party' => $this->financials->partyPosition($car),
            'profit' => $request->user()->can('viewProfit', $car) ? $this->financials->profit($car) : null,
        ]);
    }

    public function store(RecordSaleRequest $request, Car $car, RecordCarSale $record): JsonResponse
    {
        $sale = $record->handle($request->user(), $car, $request->validated());

        return ApiResponse::success(FinancialRecordResource::make($sale->load(self::SALE_RELATIONS))->resolve(), 'Sale recorded successfully.', 201);
    }

    public function reverse(ReverseRecordRequest $request, Car $car, CarSale $sale, ReverseFinancialRecord $reverse): JsonResponse
    {
        $sale = $reverse->handle($request->user(), $sale, $request->validated('reason'));

        return ApiResponse::success(FinancialRecordResource::make($sale->load(self::SALE_RELATIONS))->resolve(), 'Sale reversed successfully.');
    }

    public function storePayment(RecordPaymentRequest $request, Car $car, RecordPayment $record): JsonResponse
    {
        $payment = $record->handle($request->user(), $car, 'party', $request->validated());

        return ApiResponse::success([
            'payment' => FinancialRecordResource::make($payment->load(self::PAYMENT_RELATIONS))->resolve(),
            'party' => $this->financials->partyPosition($car),
        ], 'Payment recorded successfully.', 201);
    }

    public function reversePayment(ReverseRecordRequest $request, Car $car, CarPartyPayment $partyPayment, ReverseFinancialRecord $reverse): JsonResponse
    {
        $payment = $reverse->handle($request->user(), $partyPayment, $request->validated('reason'));

        return ApiResponse::success([
            'payment' => FinancialRecordResource::make($payment->load(self::PAYMENT_RELATIONS))->resolve(),
            'party' => $this->financials->partyPosition($car),
        ], 'Payment reversed successfully.');
    }
}
