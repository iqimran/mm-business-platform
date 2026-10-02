<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\HallBookingPayment;
use App\Modules\Restaurant\Services\FoodPackages;
use App\Modules\Restaurant\Services\HallAvailability;
use App\Modules\Restaurant\Support\BookingOverlapGuard;
use App\Modules\Restaurant\Support\FoodPackageFormulas;
use App\Modules\Restaurant\Support\PaymentAudit;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Books a hall, with an optional event food package and an optional advance payment, in ONE transaction.
 * Booking total = hall charge + food package total (guest count × price per head), calculated here.
 * The hall row is locked so bookings of the same hall are serialized; the exclusion constraint
 * remains the final guard against overlapping bookings.
 */
class CreateHallBooking
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly HallAvailability $availability,
        private readonly FoodPackages $packages,
    ) {}

    /**
     * @param  array{hall_id: string, customer_id: string, booking_date: string, start_time: string, end_time: string,
     *               hall_charge: string|int, notes?: string|null,
     *               food_package?: array{name: string, guest_count: int, price_per_head: string|int, event_menu_item_ids: list<string>, notes?: ?string}|null,
     *               payment?: array{amount: string|int, method: string, reference?: string|null}|null}  $data
     */
    public function handle(User $actor, array $data): HallBooking
    {
        return BookingOverlapGuard::run(fn () => DB::transaction(function () use ($actor, $data) {
            $hall = Hall::whereKey($data['hall_id'])->lockForUpdate()->firstOrFail();

            if (! $hall->is_active) {
                throw ValidationException::withMessages(['hall_id' => 'This hall is not available for booking.']);
            }

            $conflicts = $this->availability->conflicts($hall->id, $data['booking_date'], $data['start_time'], $data['end_time']);
            if ($conflicts->isNotEmpty()) {
                throw ValidationException::withMessages(['start_time' => $this->availability->conflictMessage($conflicts)]);
            }

            $hallCharge = Money::toMinor($data['hall_charge']);
            $packageInput = $data['food_package'] ?? null;
            $agreed = $this->bookingTotal($hallCharge, $packageInput);
            $payment = $data['payment'] ?? null;
            $paid = $payment ? Money::toMinor($payment['amount']) : 0;

            if ($paid > $agreed) {
                throw ValidationException::withMessages([
                    'payment.amount' => 'The payment exceeds the booking total of '.Money::toDecimal($agreed).'.',
                ]);
            }

            $number = DB::selectOne("SELECT nextval('restaurant_booking_no_seq') AS n")->n;

            $booking = HallBooking::create([
                'booking_no' => 'HB-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT),
                'branch_id' => $hall->branch_id,
                'hall_id' => $hall->id,
                'customer_id' => $data['customer_id'],
                'booking_date' => $data['booking_date'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'hall_charge_minor' => $hallCharge,
                'agreed_amount_minor' => $agreed,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            if ($packageInput) {
                $this->packages->set($actor, $booking, $packageInput);
            }

            $this->audit->record('restaurant.booking.created', 'restaurant_hall_booking', $booking->id, $actor->id, $booking->branch_id, newValues: [
                'booking_no' => $booking->booking_no,
                'hall_id' => $hall->id,
                'customer_id' => $booking->customer_id,
                'booking_date' => $data['booking_date'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'status' => $booking->status->value,
                'hall_charge' => Money::toDecimal($hallCharge),
                'food_package_total' => $booking->foodPackage ? Money::toDecimal($booking->foodPackage->total_minor) : null,
                'booking_total' => Money::toDecimal($agreed),
                'agreed_amount' => Money::toDecimal($agreed),
            ]);

            if ($booking->foodPackage) {
                $this->audit->record('restaurant.booking.food_package_added', 'restaurant_hall_booking', $booking->id, $actor->id, $booking->branch_id, newValues: [
                    'food_package' => FoodPackages::snapshot($booking->foodPackage),
                    'booking_total' => Money::toDecimal($agreed),
                ]);
            }

            if ($payment) {
                $record = HallBookingPayment::create([
                    'booking_id' => $booking->id,
                    'branch_id' => $booking->branch_id,
                    'payment_date' => now()->toDateString(),
                    'amount_minor' => $paid,
                    'method' => $payment['method'],
                    'reference' => $payment['reference'] ?? null,
                    'recorded_by' => $actor->id,
                ]);

                $this->audit->record('restaurant.booking_payment.recorded', 'restaurant_hall_booking_payment', $record->id, $actor->id, $booking->branch_id, newValues: [
                    'booking_id' => $booking->id,
                    'payment_date' => $record->payment_date->toDateString(),
                    'amount' => Money::toDecimal($paid),
                    'method' => $record->method->value,
                    ...PaymentAudit::transition($agreed, 0, $paid),
                ]);
            }

            return $booking;
        }));
    }

    /** Hall charge + food package total; a booking must be worth something. */
    private function bookingTotal(int $hallCharge, ?array $package): int
    {
        try {
            $packageTotal = $package ? FoodPackageFormulas::packageTotal((int) $package['guest_count'], Money::toMinor($package['price_per_head'])) : null;
            $total = FoodPackageFormulas::bookingTotal($hallCharge, $packageTotal);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['food_package.guest_count' => 'The booking total is too large.']);
        }

        if ($total <= 0) {
            throw ValidationException::withMessages(['hall_charge' => 'Enter a hall charge or add a food package.']);
        }

        return $total;
    }
}
