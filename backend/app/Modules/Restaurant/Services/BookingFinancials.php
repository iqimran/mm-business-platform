<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Support\PaymentFormulas;
use App\Modules\Shared\Support\Money;

/**
 * Per-booking figures (booking total, paid, due, payment status), always from PaymentFormulas.
 * Booking total (agreed_amount_minor) = hall charge + event food package total.
 */
class BookingFinancials
{
    /** Sum of active payments: uses the withPaid() column when loaded, otherwise queries. */
    public function paid(HallBooking $booking): int
    {
        return $booking->getAttribute('paid_minor') ?? (int) $booking->payments()->active()->sum('amount_minor');
    }

    public function due(HallBooking $booking): int
    {
        return PaymentFormulas::due($booking->agreed_amount_minor, $this->paid($booking));
    }

    /**
     * @return array{booking_total: string, agreed_amount: string, paid: string, due: string, payment_status: string}
     */
    public function position(HallBooking $booking): array
    {
        $paid = $this->paid($booking);

        return [
            'booking_total' => Money::toDecimal($booking->agreed_amount_minor),
            // Former name of the booking total, kept for compatibility.
            'agreed_amount' => Money::toDecimal($booking->agreed_amount_minor),
            'paid' => Money::toDecimal($paid),
            'due' => Money::toDecimal(PaymentFormulas::due($booking->agreed_amount_minor, $paid)),
            'payment_status' => PaymentFormulas::status($booking->agreed_amount_minor, $paid)->value,
        ];
    }
}
