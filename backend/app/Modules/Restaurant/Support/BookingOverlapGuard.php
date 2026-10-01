<?php

namespace App\Modules\Restaurant\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Turns a violation of the restaurant_hall_bookings_no_overlap exclusion constraint (SQLSTATE 23P01)
 * into a validation error. This covers the race where two requests pass the availability check at once:
 * the database accepts only one of them.
 */
final class BookingOverlapGuard
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation  Must run its own transaction (rolled back before we get here).
     * @return T
     */
    public static function run(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $e) {
            if ($e->getCode() === '23P01' && str_contains($e->getMessage(), 'restaurant_hall_bookings_no_overlap')) {
                throw ValidationException::withMessages([
                    'start_time' => 'The hall was just booked for an overlapping time. Choose another time.',
                ]);
            }

            throw $e;
        }
    }
}
