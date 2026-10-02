<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Restaurant\Models\RestaurantExpensePayment;
use App\Modules\Restaurant\Services\ExpenseFinancials;
use App\Modules\Restaurant\Support\PaymentAudit;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reverses (corrects) a supplier payment; the row stays with its reversal marker and the due rises again.
 */
class ReverseExpensePayment
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ExpenseFinancials $financials,
    ) {}

    public function handle(User $actor, RestaurantExpense $expense, RestaurantExpensePayment $payment, string $reason): RestaurantExpensePayment
    {
        return DB::transaction(function () use ($actor, $expense, $payment, $reason) {
            $expense = RestaurantExpense::whereKey($expense->getKey())->lockForUpdate()->firstOrFail();
            $payment = RestaurantExpensePayment::whereKey($payment->getKey())->where('expense_id', $expense->id)->lockForUpdate()->firstOrFail();

            if ($payment->isReversed()) {
                throw new ConflictHttpException('This payment has already been reversed.');
            }

            $paidBefore = $this->financials->paid($expense);
            $payment->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ])->save();

            $this->audit->record('restaurant.supplier_payment.reversed', 'restaurant_expense_payment', $payment->id, $actor->id, $expense->branch_id,
                oldValues: ['amount' => Money::toDecimal($payment->amount_minor), 'reversed' => false],
                newValues: ['expense_id' => $expense->id, 'reversed' => true, 'reason' => $reason]
                    + PaymentAudit::transition($expense->amount_minor, $paidBefore, $paidBefore - $payment->amount_minor));

            return $payment;
        });
    }
}
