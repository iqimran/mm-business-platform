<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Car\Actions\RecordPayment;
use App\Modules\Car\Actions\ReverseFinancialRecord;
use App\Modules\Car\Http\Requests\RecordPaymentRequest;
use App\Modules\Car\Http\Requests\ReverseRecordRequest;
use App\Modules\Car\Http\Resources\FinancialRecordResource;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDealerPayment;
use App\Modules\Car\Services\CarFinancials;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Payments made to the dealer against the car's active purchase.
 */
class DealerPaymentController extends Controller
{
    private const RELATIONS = ['recorder:id,name', 'reverser:id,name'];

    public function __construct(private readonly CarFinancials $financials) {}

    public function index(Car $car): JsonResponse
    {
        Gate::authorize('viewDealerPayments', $car);

        $purchase = $this->financials->activePurchase($car);

        return ApiResponse::success([
            'items' => $purchase
                ? FinancialRecordResource::collection(
                    $purchase->payments()->with(self::RELATIONS)->orderByDesc('payment_date')->orderByDesc('created_at')->get()
                )->resolve()
                : [],
            'dealer' => $this->financials->dealerPosition($car),
        ]);
    }

    public function store(RecordPaymentRequest $request, Car $car, RecordPayment $record): JsonResponse
    {
        $payment = $record->handle($request->user(), $car, 'dealer', $request->validated());

        return ApiResponse::success([
            'payment' => FinancialRecordResource::make($payment->load(self::RELATIONS))->resolve(),
            'dealer' => $this->financials->dealerPosition($car),
        ], 'Dealer payment recorded successfully.', 201);
    }

    public function reverse(ReverseRecordRequest $request, Car $car, CarDealerPayment $dealerPayment, ReverseFinancialRecord $reverse): JsonResponse
    {
        $payment = $reverse->handle($request->user(), $dealerPayment, $request->validated('reason'));

        return ApiResponse::success([
            'payment' => FinancialRecordResource::make($payment->load(self::RELATIONS))->resolve(),
            'dealer' => $this->financials->dealerPosition($car),
        ], 'Dealer payment reversed successfully.');
    }
}
