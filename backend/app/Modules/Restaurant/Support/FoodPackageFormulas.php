<?php

namespace App\Modules\Restaurant\Support;

use App\Modules\Shared\Support\Money;
use InvalidArgumentException;

/**
 * Hall booking figures (integer minor units, never floats):
 *
 *   Food package total = guest count × price per head
 *   Booking total      = hall charge + food package total
 */
final class FoodPackageFormulas
{
    public const MAX_GUESTS = 100000;

    public static function packageTotal(int $guestCount, int $pricePerHeadMinor): int
    {
        if ($guestCount < 1 || $guestCount > self::MAX_GUESTS || $pricePerHeadMinor <= 0) {
            throw new InvalidArgumentException('Invalid food package.');
        }

        return self::bounded($guestCount * $pricePerHeadMinor);
    }

    public static function bookingTotal(int $hallChargeMinor, ?int $packageTotalMinor): int
    {
        if ($hallChargeMinor < 0) {
            throw new InvalidArgumentException('Invalid hall charge.');
        }

        return self::bounded($hallChargeMinor + ($packageTotalMinor ?? 0));
    }

    private static function bounded(int|float $minor): int
    {
        if (! is_int($minor) || $minor > Money::MAX_MINOR) {
            throw new InvalidArgumentException('The booking amount is too large.');
        }

        return $minor;
    }
}
