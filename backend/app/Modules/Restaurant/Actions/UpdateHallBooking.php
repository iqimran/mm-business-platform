<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Enums\BookingStatus;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Services\BookingFinancials;
use App\Modules\Restaurant\Services\FoodPackages;
use App\Modules\Restaurant\Services\HallAvailability;
use App\Modules\Restaurant\Support\BookingOverlapGuard;
use App\Modules\Restaurant\Support\FoodPackageFormulas;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Changes a confirmed booking (hall within the same branch, customer, date/time, hall charge, notes) and
 * sets/replaces/removes its event food package. Recalculates booking total = hall charge + package total,
 * keeps it at or above what was paid, re-checks availability, audits old → new values.
 */
class UpdateHallBooking
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly HallAvailability $availability,
        private readonly BookingFinancials $financials,
        private readonly FoodPackages $packages,
    ) {}

    /**
     * @param  array{hall_id?: string, customer_id?: string, booking_date?: string, start_time?: string, end_time?: string,
     *               hall_charge?: string|int, notes?: string|null, food_package?: array<string, mixed>|null}  $data
     *        food_package: an array sets/replaces the package, null removes it, absent leaves it unchanged.
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
            if (array_key_exists('hall_charge', $data)) {
                $attributes['hall_charge_minor'] = Money::toMinor($data['hall_charge']);
            }

            $old = $this->snapshot($booking);
            $booking->fill($attributes);

            // Event food package: set/replace, remove, or leave as is.
            $packageBefore = null;
            $packageChanged = array_key_exists('food_package', $data);
            if ($packageChanged) {
                $packageBefore = $data['food_package'] === null
                    ? $this->packages->remove($booking)
                    : $this->packages->set($actor, $booking, $data['food_package']);
            }

            $this->applyTotal($booking);

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

            if ($packageChanged) {
                $packageAfter = $booking->foodPackage ? FoodPackages::snapshot($booking->foodPackage) : null;
                $action = match (true) {
                    $packageBefore === null && $packageAfter !== null => 'restaurant.booking.food_package_added',
                    $packageBefore !== null && $packageAfter === null => 'restaurant.booking.food_package_removed',
                    $packageBefore !== $packageAfter => 'restaurant.booking.food_package_updated',
                    default => null,
                };
                if ($action !== null) {
                    $this->audit->record($action, 'restaurant_hall_booking', $booking->id, $actor->id, $booking->branch_id,
                        oldValues: ['food_package' => $packageBefore, 'booking_total' => $old['booking_total']],
                        newValues: ['food_package' => $packageAfter, 'booking_total' => $new['booking_total']]);
                }
            }

            return $booking;
        }));
    }

    /** Booking total = hall charge + package total; never zero and never below what was paid. */
    private function applyTotal(HallBooking $booking): void
    {
        try {
            $total = FoodPackageFormulas::bookingTotal($booking->hall_charge_minor, $booking->foodPackage?->total_minor);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['hall_charge' => 'The booking total is too large.']);
        }

        if ($total <= 0) {
            throw ValidationException::withMessages(['hall_charge' => 'Enter a hall charge or add a food package.']);
        }

        $paid = $this->financials->paid($booking);
        if ($total < $paid) {
            throw ValidationException::withMessages([
                'hall_charge' => 'The booking total ('.Money::toDecimal($total).') cannot be less than the amount already paid ('.Money::toDecimal($paid).').',
            ]);
        }

        $booking->agreed_amount_minor = $total;
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
            'hall_charge' => Money::toDecimal($booking->hall_charge_minor),
            'booking_total' => Money::toDecimal($booking->agreed_amount_minor),
            'notes' => $booking->notes,
        ];
    }
}
