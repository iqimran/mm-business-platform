<?php

namespace App\Modules\Restaurant\Support;

use App\Modules\Shared\Support\Money;

/**
 * Audit values describing how a payment changed an obligation's position,
 * e.g. payment status "partial" → "paid" with the paid total and due afterwards.
 */
final class PaymentAudit
{
    /**
     * @return array{payment_status_before: string, payment_status_after: string, paid_after: string, due_after: string}
     */
    public static function transition(int $amountMinor, int $paidBeforeMinor, int $paidAfterMinor): array
    {
        return [
            'payment_status_before' => PaymentFormulas::status($amountMinor, $paidBeforeMinor)->value,
            'payment_status_after' => PaymentFormulas::status($amountMinor, $paidAfterMinor)->value,
            'paid_after' => Money::toDecimal($paidAfterMinor),
            'due_after' => Money::toDecimal(PaymentFormulas::due($amountMinor, $paidAfterMinor)),
        ];
    }
}
