<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarExpense;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Records a car expense. Expenses add to the car's cost (investment); they never affect
 * dealer payable or party due.
 */
class RecordCarExpense
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{expense_type_id: string, expense_date: string, amount: string|int, description?: string|null, reference?: string|null}  $data
     */
    public function handle(User $actor, Car $car, array $data): CarExpense
    {
        return DB::transaction(function () use ($actor, $car, $data) {
            $car = Car::whereKey($car->getKey())->lockForUpdate()->firstOrFail();

            if ($car->status === CarStatus::Completed) {
                throw new ConflictHttpException('Expenses cannot be added to a completed car.');
            }

            $expense = CarExpense::create([
                'car_id' => $car->id,
                'branch_id' => $car->branch_id,
                'expense_type_id' => $data['expense_type_id'],
                'expense_date' => $data['expense_date'],
                'amount_minor' => Money::toMinor($data['amount']),
                'description' => $data['description'] ?? null,
                'reference' => $data['reference'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->record('car.expense_recorded', 'car_expense', $expense->id, $actor->id, $car->branch_id, newValues: [
                'car_id' => $car->id,
                'expense_type_id' => $expense->expense_type_id,
                'expense_date' => $expense->expense_date->toDateString(),
                'amount' => Money::toDecimal($expense->amount_minor),
                'description' => $expense->description,
            ]);

            return $expense;
        });
    }
}
