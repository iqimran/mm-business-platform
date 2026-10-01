<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Enums\BookingStatus;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Shared\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Filtered, branch-scoped hall booking listing and its totals (server-side).
 */
class HallBookingQuery
{
    /**
     * @param  array{branch_id?: string, hall_id?: string, customer_id?: string, date_from?: string, date_to?: string,
     *               status?: string, payment_status?: string, search?: string}  $filters
     */
    public function filtered(User $user, array $filters): Builder
    {
        $paid = HallBooking::paidSql();

        return HallBooking::query()
            ->accessibleBy($user)
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('restaurant_hall_bookings.branch_id', $id))
            ->when($filters['hall_id'] ?? null, fn ($q, $id) => $q->where('restaurant_hall_bookings.hall_id', $id))
            ->when($filters['customer_id'] ?? null, fn ($q, $id) => $q->where('restaurant_hall_bookings.customer_id', $id))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('restaurant_hall_bookings.booking_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('restaurant_hall_bookings.booking_date', '<=', $d))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('restaurant_hall_bookings.status', $s))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('restaurant_hall_bookings.booking_no', 'ilike', "%{$term}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$term}%")->orWhere('phone', 'ilike', "%{$term}%"))))
            ->when($filters['payment_status'] ?? null, fn ($q, $status) => $q
                ->occupying()
                ->whereRaw(match ($status) {
                    'unpaid' => "{$paid} = 0",
                    'partial' => "{$paid} > 0 AND {$paid} < restaurant_hall_bookings.agreed_amount_minor",
                    default => "{$paid} >= restaurant_hall_bookings.agreed_amount_minor",
                }));
    }

    /**
     * Totals of the filtered bookings, excluding cancelled ones.
     *
     * @return array{count: int, agreed_amount: string, paid: string, due: string}
     */
    public function summary(Builder $filtered): array
    {
        $row = DB::query()
            ->fromSub($filtered->clone()
                ->where('restaurant_hall_bookings.status', '!=', BookingStatus::Cancelled->value)
                ->toBase()
                ->select('restaurant_hall_bookings.agreed_amount_minor')
                ->selectRaw(HallBooking::paidSql().' AS paid_minor'), 'b')
            ->selectRaw('count(*) AS bookings, coalesce(sum(agreed_amount_minor), 0) AS agreed, coalesce(sum(paid_minor), 0) AS paid')
            ->first();

        return [
            'count' => (int) $row->bookings,
            'agreed_amount' => Money::toDecimal((int) $row->agreed),
            'paid' => Money::toDecimal((int) $row->paid),
            'due' => Money::toDecimal((int) $row->agreed - (int) $row->paid),
        ];
    }
}
