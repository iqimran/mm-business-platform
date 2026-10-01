<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Corrects an expense by reversing it (the original row stays in history; record a new expense if needed).
 */
class ReverseRestaurantExpense
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, RestaurantExpense $expense, string $reason): RestaurantExpense
    {
        return DB::transaction(function () use ($actor, $expense, $reason) {
            $expense = RestaurantExpense::whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($expense->isReversed()) {
                throw new ConflictHttpException('This expense has already been reversed.');
            }

            $expense->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ])->save();

            $this->audit->record('restaurant.expense.reversed', 'restaurant_expense', $expense->id, $actor->id, $expense->branch_id,
                oldValues: ['amount' => Money::toDecimal($expense->amount_minor), 'reversed' => false],
                newValues: ['expense_date' => $expense->expense_date->toDateString(), 'reversed' => true, 'reason' => $reason]);

            return $expense;
        });
    }
}
