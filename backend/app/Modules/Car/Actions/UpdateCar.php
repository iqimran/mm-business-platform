<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Models\Car;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Updates descriptive car data. Status is never changed here (lifecycle actions only).
 * Moving a car to another branch requires access to both branches (policy + AccessibleBranch).
 */
class UpdateCar
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, Car $car, array $data): Car
    {
        // Financial records belong to the branch they were recorded in; moving the car would split its history.
        if (isset($data['branch_id']) && $data['branch_id'] !== $car->branch_id && $car->hasFinancialRecords()) {
            throw ValidationException::withMessages(['branch_id' => 'Cars with financial records cannot be moved to another branch.']);
        }

        return DB::transaction(function () use ($actor, $car, $data) {
            $car->fill(Arr::except($data, ['status']));
            $changed = array_keys(Arr::except($car->getDirty(), ['updated_at']));
            $old = Arr::only($car->getRawOriginal(), $changed);
            $car->save();

            if ($changed !== []) {
                $this->audit->record('car.updated', 'car', $car->id, $actor->id, $car->branch_id,
                    oldValues: $old, newValues: Arr::only($car->getAttributes(), $changed));
            }

            return $car;
        });
    }
}
