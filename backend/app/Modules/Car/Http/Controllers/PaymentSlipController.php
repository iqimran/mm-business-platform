<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDealerPayment;
use App\Modules\Car\Models\CarPartyPayment;
use App\Modules\Car\Services\PaymentSlip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printable slips. Same access as viewing the payments (permission + the car's branch);
 * the payment must belong to the car in the URL (scoped bindings). Every print is audited.
 */
class PaymentSlipController extends Controller
{
    public function partyReceipt(Request $request, Car $car, CarPartyPayment $partyPayment, PaymentSlip $slip, AuditLogger $audit): Response
    {
        Gate::authorize('viewPartyPayments', $car);

        $audit->record('car.payment_slip_printed', 'car_party_payment', $partyPayment->id, $request->user()->id, $car->branch_id,
            newValues: ['document' => PaymentSlip::number('MR', $partyPayment), 'car_id' => $car->id]);

        return $slip->partyReceipt($partyPayment);
    }

    public function dealerVoucher(Request $request, Car $car, CarDealerPayment $dealerPayment, PaymentSlip $slip, AuditLogger $audit): Response
    {
        Gate::authorize('viewDealerPayments', $car);

        $audit->record('car.payment_slip_printed', 'car_dealer_payment', $dealerPayment->id, $request->user()->id, $car->branch_id,
            newValues: ['document' => PaymentSlip::number('PV', $dealerPayment), 'car_id' => $car->id]);

        return $slip->dealerVoucher($dealerPayment);
    }
}
