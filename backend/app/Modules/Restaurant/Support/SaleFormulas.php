<?php

namespace App\Modules\Restaurant\Support;

use App\Modules\Restaurant\Enums\PaymentStatus;
use App\Modules\Shared\Support\Money;
use InvalidArgumentException;

/**
 * Food sale formulas: the single source for restaurant sale figures (integer minor units).
 * Independent of the Car module's formulas.
 *
 *   Line total = quantity × unit price
 *   Sale total = Σ line totals
 *   Due        = sale total − active payments
 */
final class SaleFormulas
{
    public const MAX_QUANTITY = 9999;

    public static function lineTotal(int $quantity, int $unitPriceMinor): int
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY || $unitPriceMinor <= 0) {
            throw new InvalidArgumentException('Invalid sale line.');
        }

        return self::bounded($quantity * $unitPriceMinor);
    }

    /**
     * @param  iterable<int>  $lineTotals
     */
    public static function total(iterable $lineTotals): int
    {
        $total = 0;
        foreach ($lineTotals as $lineTotal) {
            $total = self::bounded($total + $lineTotal);
        }

        return $total;
    }

    public static function due(int $totalMinor, int $paidMinor): int
    {
        return PaymentFormulas::due($totalMinor, $paidMinor);
    }

    public static function status(int $totalMinor, int $paidMinor): PaymentStatus
    {
        return PaymentFormulas::status($totalMinor, $paidMinor);
    }

    private static function bounded(int|float $minor): int
    {
        // int * int overflows to float in PHP; both cases are rejected.
        if (! is_int($minor) || $minor > Money::MAX_MINOR) {
            throw new InvalidArgumentException('The sale amount is too large.');
        }

        return $minor;
    }
}
