<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Enums\BookingStatus;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Services\BookingFinancials;
use App\Modules\Restaurant\Services\HallAvailability;
use App\Modules\Restaurant\Support\BookingOverlapGuard;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Changes a confirmed booking (hall within the same branch, customer, date/time, agreed amount, notes).
 * Re-checks availability, keeps the agreed amount at or above what was paid, audits old → new values.
 */
class UpdateHallBooking
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly HallAvailability $availability,
        private readonly BookingFinancials $financials,
    ) {}

    /**
     * @param  array{hall_id?: string, customer_id?: string, booking_date?: string, start_time?: string, end_time?: string,
     *               agreed_amount?: string|int, notes?: string|null}  $data
     */
    public function handle(User $actor, HallBooking $booking, array $data): HallBooking
    {
        return BookingOverlapGuard::run(fn () => DB::transaction(function () use ($actor, $booking, $data) {
            // Lock order: hall, then booking (same as creating), so bookings of a hall are serialized.
            $hallId = $data['hall_id'] ?? $booking->hall_id;
            $hall = Hall::whereKey($hallId)->lockForUpdate()->firstOrFail();
            $booking = HallBooking::whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($booking->status !== BookingStatus::Confirmed) {
                throw new ConflictHttpException("This booking is {$booking->status->value} and can no longer be changed.");
            }
            if ($hall->id !== $booking->hall_id && (! $hall->is_active || $hall->branch_id !== $booking->branch_id)) {
                throw ValidationException::withMessages(['hall_id' => 'Select an active hall of the same branch.']);
            }

            $attributes = array_intersect_key($data, array_flip(['hall_id', 'customer_id', 'booking_date', 'start_time', 'end_time', 'notes']));
            if (array_key_exists('agreed_amount', $data)) {
                $attributes['agreed_amount_minor'] = Money::toMinor($data['agreed_amount']);
                $paid = $this->financials->paid($booking);

                if ($attributes['agreed_amount_minor'] < $paid) {
                    throw ValidationException::withMessages([
                        'agreed_amount' => 'The agreed amount cannot be less than the amount already paid ('.Money::toDecimal($paid).').',
                    ]);
                }
            }

            $old = $this->snapshot($booking);
            $booking->fill($attributes);

            if ($booking->isDirty(['hall_id', 'booking_date', 'start_time', 'end_time'])) {
                $conflicts = $this->availability->conflicts(
                    $booking->hall_id, $booking->booking_date->toDateString(), $booking->startsAt(), $booking->endsAt(), $booking->id,
                );
                if ($conflicts->isNotEmpty()) {
                    throw ValidationException::withMessages(['start_time' => $this->availability->conflictMessage($conflicts)]);
                }
            }

            $booking->save();
            $new = $this->snapshot($booking);
            $changed = array_keys(array_diff_assoc($new, $old));

            if ($changed !== []) {
                $this->audit->record('restaurant.booking.updated', 'restaurant_hall_booking', $booking->id, $actor->id, $booking->branch_id,
                    oldValues: array_intersect_key($old, array_flip($changed)),
                    newValues: array_intersect_key($new, array_flip($changed)));
            }

            return $booking;
        }));
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(HallBooking $booking): array
    {
        return [
            'hall_id' => $booking->hall_id,
            'customer_id' => $booking->customer_id,
            'booking_date' => $booking->booking_date->toDateString(),
            'start_time' => $booking->startsAt(),
            'end_time' => $booking->endsAt(),
            'agreed_amount' => Money::toDecimal($booking->agreed_amount_minor),
            'notes' => $booking->notes,
        ];
    }
}
