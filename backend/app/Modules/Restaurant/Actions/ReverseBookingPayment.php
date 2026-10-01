<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\HallBookingPayment;
use App\Modules\Restaurant\Services\BookingFinancials;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reverses (corrects or refunds) a booking payment; the row stays with its reversal marker.
 */
class ReverseBookingPayment
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BookingFinancials $financials,
    ) {}

    public function handle(User $actor, HallBooking $booking, HallBookingPayment $payment, string $reason): HallBookingPayment
    {
        return DB::transaction(function () use ($actor, $booking, $payment, $reason) {
            $booking = HallBooking::whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $payment = HallBookingPayment::whereKey($payment->getKey())->where('booking_id', $booking->id)->lockForUpdate()->firstOrFail();

            if ($payment->isReversed()) {
                throw new ConflictHttpException('This payment has already been reversed.');
            }

            $payment->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ])->save();

            $this->audit->record('restaurant.booking_payment.reversed', 'restaurant_hall_booking_payment', $payment->id, $actor->id, $booking->branch_id,
                oldValues: ['amount' => Money::toDecimal($payment->amount_minor), 'reversed' => false],
                newValues: ['booking_id' => $booking->id, 'reversed' => true, 'reason' => $reason, 'due_after' => Money::toDecimal($this->financials->due($booking))]);

            return $payment;
        });
    }
}
