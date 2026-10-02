<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Restaurant\Support\PaymentFormulas;
use App\Modules\Shared\Support\Money;

/**
 * Per-expense supplier figures, always from PaymentFormulas:
 *   paid = full amount (no supplier) or active supplier payments; due = amount − paid.
 */
class ExpenseFinancials
{
    public function paid(RestaurantExpense $expense): int
    {
        if ($expense->supplier_id === null) {
            return $expense->amount_minor;
        }

        return $expense->getAttribute('paid_minor') ?? (int) $expense->payments()->active()->sum('amount_minor');
    }

    public function due(RestaurantExpense $expense): int
    {
        return PaymentFormulas::due($expense->amount_minor, $this->paid($expense));
    }

    /**
     * @return array{paid: string, due: string, payment_status: string}
     */
    public function position(RestaurantExpense $expense): array
    {
        $paid = $this->paid($expense);

        return [
            'paid' => Money::toDecimal($paid),
            'due' => Money::toDecimal(PaymentFormulas::due($expense->amount_minor, $paid)),
            'payment_status' => PaymentFormulas::status($expense->amount_minor, $paid)->value,
        ];
    }
}
