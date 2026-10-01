<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\ExpenseCategory;
use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a restaurant expense (transactional, audited). The category is re-checked under a shared lock
 * so it cannot be deactivated or deleted mid-way.
 */
class RecordRestaurantExpense
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{branch_id: string, category_id: string, supplier_id?: string|null, expense_date: string,
     *               amount: string|int, description?: string|null, reference?: string|null}  $data
     */
    public function handle(User $actor, array $data): RestaurantExpense
    {
        return DB::transaction(function () use ($actor, $data) {
            $category = ExpenseCategory::whereKey($data['category_id'])->sharedLock()->first();
            if ($category === null || ! $category->is_active) {
                throw ValidationException::withMessages(['category_id' => 'Select an active expense category.']);
            }

            $expense = RestaurantExpense::create([
                'branch_id' => $data['branch_id'],
                'category_id' => $category->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'expense_date' => $data['expense_date'],
                'amount_minor' => Money::toMinor($data['amount']),
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
            ]);

            return $expense;
        });
    }
}
