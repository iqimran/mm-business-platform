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
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Pays (part of) a supplier bill. The expense row is locked so concurrent payments cannot overpay;
 * the database trigger restaurant_expense_payment_check() is the final guard.
 */
class RecordExpensePayment
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ExpenseFinancials $financials,
    ) {}

    /**
     * @param  array{payment_date: string, amount: string|int, method: string, reference?: string|null, notes?: string|null}  $data
     */
    public function handle(User $actor, RestaurantExpense $expense, array $data): RestaurantExpensePayment
    {
        return DB::transaction(function () use ($actor, $expense, $data) {
            $expense = RestaurantExpense::whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($expense->isReversed()) {
                throw new ConflictHttpException('This expense has been reversed and cannot be paid.');
            }
            if ($expense->supplier_id === null) {
                throw new ConflictHttpException('This expense has no supplier; it was paid in full when recorded.');
            }

            $due = $this->financials->due($expense);
            $amount = Money::toMinor($data['amount']);

            if ($due <= 0) {
                throw new ConflictHttpException('This bill is already fully paid.');
            }
            if ($amount > $due) {
                throw ValidationException::withMessages(['amount' => 'The amount exceeds the remaining due of '.Money::toDecimal($due).'.']);
            }
            if ($data['payment_date'] < $expense->expense_date->toDateString()) {
                throw ValidationException::withMessages(['payment_date' => 'The payment date cannot be before the expense date.']);
            }

            $paidBefore = $expense->amount_minor - $due;
            $payment = RestaurantExpensePayment::create([
                'expense_id' => $expense->id,
                'branch_id' => $expense->branch_id,
                'supplier_id' => $expense->supplier_id,
                'payment_date' => $data['payment_date'],
                'amount_minor' => $amount,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->record('restaurant.supplier_payment.recorded', 'restaurant_expense_payment', $payment->id, $actor->id, $expense->branch_id, newValues: [
                'expense_id' => $expense->id,
                'supplier_id' => $expense->supplier_id,
                'payment_date' => $payment->payment_date->toDateString(),
                'amount' => Money::toDecimal($amount),
                'method' => $payment->method->value,
                ...PaymentAudit::transition($expense->amount_minor, $paidBefore, $paidBefore + $amount),
            ]);

            return $payment;
        });
    }
}
