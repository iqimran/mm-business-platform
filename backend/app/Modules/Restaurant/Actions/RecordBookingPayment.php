<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Enums\BookingStatus;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\HallBookingPayment;
use App\Modules\Restaurant\Services\BookingFinancials;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Records a payment against a booking (confirmed or completed), never exceeding the due.
 * The booking row is locked so concurrent payments cannot overpay.
 */
class RecordBookingPayment
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BookingFinancials $financials,
    ) {}

    /**
     * @param  array{payment_date: string, amount: string|int, method: string, reference?: string|null, notes?: string|null}  $data
     */
    public function handle(User $actor, HallBooking $booking, array $data): HallBookingPayment
    {
        return DB::transaction(function () use ($actor, $booking, $data) {
            $booking = HallBooking::whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($booking->status === BookingStatus::Cancelled) {
                throw new ConflictHttpException('This booking is cancelled and cannot receive payments.');
            }

            $due = $this->financials->due($booking);
            $amount = Money::toMinor($data['amount']);

            if ($due <= 0) {
                throw new ConflictHttpException('This booking is already fully paid.');
            }
            if ($amount > $due) {
                throw ValidationException::withMessages([
                    'amount' => 'The amount exceeds the remaining due of '.Money::toDecimal($due).'.',
                ]);
            }

            $payment = HallBookingPayment::create([
                'booking_id' => $booking->id,
                'branch_id' => $booking->branch_id,
                'payment_date' => $data['payment_date'],
                'amount_minor' => $amount,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->record('restaurant.booking_payment.recorded', 'restaurant_hall_booking_payment', $payment->id, $actor->id, $booking->branch_id, newValues: [
                'booking_id' => $booking->id,
                'payment_date' => $payment->payment_date->toDateString(),
                'amount' => Money::toDecimal($amount),
                'method' => $payment->method->value,
                'due_after' => Money::toDecimal($due - $amount),
            ]);

            return $payment;
        });
    }
}
