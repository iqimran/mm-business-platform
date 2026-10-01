<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Restaurant\Models\HallBooking;
use Illuminate\Support\Collection;

/**
 * Overlap check for a hall on a date: [start, end) ranges, cancelled bookings ignored.
 * Used for friendly validation messages and the availability endpoint; the database
 * exclusion constraint (restaurant_hall_bookings_no_overlap) is the final, race-proof guard.
 */
class HallAvailability
{
    /**
     * @return Collection<int, HallBooking>
     */
    public function conflicts(string $hallId, string $date, string $start, string $end, ?string $ignoreBookingId = null): Collection
    {
        return HallBooking::query()
            ->occupying()
            ->where('hall_id', $hallId)
            ->where('booking_date', $date)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->when($ignoreBookingId, fn ($q, $id) => $q->whereKeyNot($id))
            ->orderBy('start_time')
            ->get();
    }

    /**
     * @return Collection<int, HallBooking>
     */
    public function bookedOn(string $hallId, string $date): Collection
    {
        return HallBooking::query()->occupying()->where('hall_id', $hallId)->where('booking_date', $date)->orderBy('start_time')->get();
    }

    /** "The hall is already booked 18:00–22:00 (HB-000003)." */
    public function conflictMessage(Collection $conflicts): string
    {
        $slots = $conflicts->map(fn (HallBooking $b) => "{$b->startsAt()}–{$b->endsAt()} ({$b->booking_no})")->implode(', ');

        return "The hall is already booked {$slots}.";
    }
}
