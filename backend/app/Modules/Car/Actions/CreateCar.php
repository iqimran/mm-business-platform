<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Models\Car;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Registers a car in a branch. Purchase price/date are recorded by the purchase flow (Task 008).
 * The branch is validated against the actor's access (AccessibleBranch) before this runs.
 */
class CreateCar
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, array $data): Car
    {
        return DB::transaction(function () use ($actor, $data) {
            $car = Car::create($data);

            $this->audit->record('car.created', 'car', $car->id, $actor->id, $car->branch_id,
                newValues: Arr::except($car->getAttributes(), ['id', 'created_at', 'updated_at']));

            return $car;
        });
    }
}
