<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\ExpenseCategory;
use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Restaurant\Models\RestaurantExpensePayment;
use App\Modules\Restaurant\Support\PaymentAudit;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a restaurant expense / supplier bill (transactional, audited). The category is re-checked under a
 * shared lock so it cannot be deactivated or deleted mid-way.
 *
 * Supplier dues: with a supplier, "paid now" may be 0..amount (default: the full amount) and is recorded as a
 * supplier payment; the rest stays due. Without a supplier the expense is always paid in full.
 */
class RecordRestaurantExpense
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{branch_id: string, category_id: string, supplier_id?: string|null, expense_date: string,
     *               amount: string|int, description?: string|null, reference?: string|null,
     *               paid_amount?: string|int|null, payment_method?: string|null, payment_reference?: string|null}  $data
     */
    public function handle(User $actor, array $data): RestaurantExpense
    {
        return DB::transaction(function () use ($actor, $data) {
            $category = ExpenseCategory::whereKey($data['category_id'])->sharedLock()->first();
            if ($category === null || ! $category->is_active) {
                throw ValidationException::withMessages(['category_id' => 'Select an active expense category.']);
            }

            $amount = Money::toMinor($data['amount']);
            $supplierId = $data['supplier_id'] ?? null;
            $paidNow = isset($data['paid_amount']) ? Money::toMinor($data['paid_amount']) : $amount;

            if ($paidNow > $amount) {
                throw ValidationException::withMessages(['paid_amount' => 'The amount paid cannot exceed the expense amount.']);
            }
            if ($supplierId === null && $paidNow !== $amount) {
                throw ValidationException::withMessages(['paid_amount' => 'Select a supplier for an expense that is not fully paid.']);
            }

            $expense = RestaurantExpense::create([
                'branch_id' => $data['branch_id'],
                'category_id' => $category->id,
                'supplier_id' => $supplierId,
                'expense_date' => $data['expense_date'],
                'amount_minor' => $amount,
                'description' => $data['description'] ?? null,
                'reference' => $data['reference'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->record('restaurant.expense.recorded', 'restaurant_expense', $expense->id, $actor->id, $expense->branch_id, newValues: [
                'category_id' => $category->id,
                'category' => $category->name,
                'supplier_id' => $expense->supplier_id,
                'expense_date' => $expense->expense_date->toDateString(),
                'amount' => Money::toDecimal($expense->amount_minor),
                'description' => $expense->description,
                'reference' => $expense->reference,
                'paid_now' => Money::toDecimal($paidNow),
                'supplier_due' => Money::toDecimal($amount - $paidNow),
            ]);

            // Supplier bills: what was paid now is a supplier payment; the rest stays due.
            if ($supplierId !== null && $paidNow > 0) {
                $payment = RestaurantExpensePayment::create([
                    'expense_id' => $expense->id,
                    'branch_id' => $expense->branch_id,
                    'supplier_id' => $supplierId,
                    'payment_date' => $expense->expense_date->toDateString(),
                    'amount_minor' => $paidNow,
                    'method' => $data['payment_method'] ?? 'cash',
                    'reference' => $data['payment_reference'] ?? null,
                    'recorded_by' => $actor->id,
                ]);

                $this->audit->record('restaurant.supplier_payment.recorded', 'restaurant_expense_payment', $payment->id, $actor->id, $expense->branch_id, newValues: [
                    'expense_id' => $expense->id,
                    'supplier_id' => $supplierId,
                    'payment_date' => $payment->payment_date->toDateString(),
                    'amount' => Money::toDecimal($paidNow),
                    'method' => $payment->method->value,
                    ...PaymentAudit::transition($amount, 0, $paidNow),
                ]);
            }

            return $expense;
        });
    }
}
