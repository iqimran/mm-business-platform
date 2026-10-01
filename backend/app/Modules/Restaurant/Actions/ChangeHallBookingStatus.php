<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Enums\BookingStatus;
use App\Modules\Restaurant\Models\HallBooking;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Booking workflow: confirmed → completed (on or after the event date) or confirmed → cancelled.
 * - Cancel: reason required; only when nothing is paid (reverse payments first). Frees the hall.
 * - Complete: the event has taken place; remaining due can still be collected afterwards.
 */
class ChangeHallBookingStatus
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function cancel(User $actor, HallBooking $booking, string $reason): HallBooking
    {
        return DB::transaction(function () use ($actor, $booking, $reason) {
            $booking = $this->lockConfirmed($booking);

            if ($booking->payments()->active()->exists()) {
                throw new ConflictHttpException('This booking has payments. Reverse its payments before cancelling.');
            }

            $booking->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ])->save();

            $this->audit->record('restaurant.booking.cancelled', 'restaurant_hall_booking', $booking->id, $actor->id, $booking->branch_id,
                oldValues: ['status' => BookingStatus::Confirmed->value],
                newValues: ['status' => BookingStatus::Cancelled->value, 'reason' => $reason]);

            return $booking;
        });
    }

    public function complete(User $actor, HallBooking $booking): HallBooking
    {
        return DB::transaction(function () use ($actor, $booking) {
            $booking = $this->lockConfirmed($booking);

            if ($booking->booking_date->toDateString() > now()->toDateString()) {
                throw new ConflictHttpException('A booking can be completed on or after its event date.');
            }

            $booking->forceFill(['status' => BookingStatus::Completed])->save();

            $this->audit->record('restaurant.booking.completed', 'restaurant_hall_booking', $booking->id, $actor->id, $booking->branch_id,
                oldValues: ['status' => BookingStatus::Confirmed->value],
                newValues: ['status' => BookingStatus::Completed->value]);

            return $booking;
        });
    }

    private function lockConfirmed(HallBooking $booking): HallBooking
    {
        $booking = HallBooking::whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

        if ($booking->status !== BookingStatus::Confirmed) {
            throw new ConflictHttpException("This booking is already {$booking->status->value}.");
        }

        return $booking;
    }
}
