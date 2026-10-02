<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\FoodSalePayment;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\HallBookingPayment;
use App\Modules\Restaurant\Services\PaymentReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printable payment receipts. Same access as viewing the sale/booking (permission + its branch);
 * the payment must belong to the sale/booking in the URL (scoped bindings). Every print is audited.
 */
class PaymentReceiptController extends Controller
{
    public function salePayment(Request $request, FoodSale $sale, FoodSalePayment $payment, PaymentReceipt $receipt, AuditLogger $audit): Response
    {
        Gate::authorize('view', $sale);

        $audit->record('restaurant.payment_receipt_printed', 'restaurant_sale_payment', $payment->id, $request->user()->id, $sale->branch_id,
            newValues: ['document' => PaymentReceipt::number('FR', $payment), 'sale_id' => $sale->id]);

        return $receipt->forSalePayment($sale, $payment);
    }

    public function bookingPayment(Request $request, HallBooking $booking, HallBookingPayment $payment, PaymentReceipt $receipt, AuditLogger $audit): Response
    {
        Gate::authorize('view', $booking);

        $audit->record('restaurant.payment_receipt_printed', 'restaurant_hall_booking_payment', $payment->id, $request->user()->id, $booking->branch_id,
            newValues: ['document' => PaymentReceipt::number('BR', $payment), 'booking_id' => $booking->id]);

        return $receipt->forBookingPayment($booking, $payment);
    }
}
