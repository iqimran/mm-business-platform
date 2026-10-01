<?php

namespace App\Modules\Restaurant\Support;

use App\Modules\Restaurant\Enums\PaymentStatus;

/**
 * Due and payment status of any restaurant obligation (food sale total, hall booking agreed amount).
 * Integer minor units only.
 *
 *   Due = amount − active payments
 */
final class PaymentFormulas
{
    public static function due(int $amountMinor, int $paidMinor): int
    {
        return $amountMinor - $paidMinor;
    }

    public static function status(int $amountMinor, int $paidMinor): PaymentStatus
    {
        return match (true) {
            $paidMinor <= 0 => PaymentStatus::Unpaid,
            $paidMinor >= $amountMinor => PaymentStatus::Paid,
            default => PaymentStatus::Partial,
        };
    }
}
