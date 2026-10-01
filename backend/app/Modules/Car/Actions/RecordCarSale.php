<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarSale;
use App\Modules\Car\Support\CarLifecycle;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Sells a car to a party. Moves the car to SOLD; at most one active sale per car,
 * so a car cannot be sold twice accidentally (also enforced by a unique index).
 */
class RecordCarSale
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{party_id: string, sale_date: string, amount: string|int, reference?: string|null, notes?: string|null}  $data
     */
    public function handle(User $actor, Car $car, array $data): CarSale
    {
        return DB::transaction(function () use ($actor, $car, $data) {
            $car = Car::whereKey($car->getKey())->lockForUpdate()->firstOrFail();

            if ($car->sales()->active()->exists() || in_array($car->status, [CarStatus::Sold, CarStatus::Completed], true)) {
                throw new ConflictHttpException('This car has already been sold. Reverse the existing sale to correct it.');
            }
            if (! CarLifecycle::isSellable($car->status)) {
                throw new ConflictHttpException('Move the car into stock before selling it.');
            }
            if (! $car->purchases()->active()->exists()) {
                throw new ConflictHttpException('Record the purchase of this car before selling it.');
            }

            $previous = $car->status;
            $sale = CarSale::create([
                'car_id' => $car->id,
                'branch_id' => $car->branch_id,
                'party_id' => $data['party_id'],
                'sale_date' => $data['sale_date'],
                'amount_minor' => Money::toMinor($data['amount']),
                'status_before_sale' => $previous,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            $car->forceFill(['status' => CarStatus::Sold])->save();

            $this->audit->record('car.sale_recorded', 'car_sale', $sale->id, $actor->id, $car->branch_id,
                oldValues: ['status' => $previous->value],
                newValues: [
                    'car_id' => $car->id,
                    'party_id' => $sale->party_id,
                    'sale_date' => $sale->sale_date->toDateString(),
                    'amount' => Money::toDecimal($sale->amount_minor),
                    'status' => CarStatus::Sold->value,
                ]);

            return $sale;
        });
    }
}
