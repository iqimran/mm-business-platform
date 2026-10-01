<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarPurchase;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Records the purchase of a car from a dealer. A car has at most one active purchase;
 * to correct it, reverse it and record a new one.
 */
class RecordCarPurchase
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{dealer_id: string, purchase_date: string, amount: string|int, reference?: string|null, notes?: string|null}  $data
     */
    public function handle(User $actor, Car $car, array $data): CarPurchase
    {
        return DB::transaction(function () use ($actor, $car, $data) {
            // Serialize concurrent purchase recording for the same car.
            $car = Car::whereKey($car->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($car->status, [CarStatus::Sold, CarStatus::Completed], true)) {
                throw new ConflictHttpException('A purchase cannot be recorded for a sold car.');
            }

            if ($car->purchases()->active()->exists()) {
                throw new ConflictHttpException('This car already has a purchase. Reverse it first to record a correction.');
            }

            $purchase = CarPurchase::create([
                'car_id' => $car->id,
                'branch_id' => $car->branch_id,
                'dealer_id' => $data['dealer_id'],
                'purchase_date' => $data['purchase_date'],
                'amount_minor' => Money::toMinor($data['amount']),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            // The purchase is the source of truth for the car's dealer.
            $previousDealer = $car->dealer_id;
            if ($previousDealer !== $purchase->dealer_id) {
                $car->dealer_id = $purchase->dealer_id;
                $car->save();
            }

            $this->audit->record('car.purchase_recorded', 'car_purchase', $purchase->id, $actor->id, $car->branch_id, newValues: [
                'car_id' => $car->id,
                'dealer_id' => $purchase->dealer_id,
                'purchase_date' => $purchase->purchase_date->toDateString(),
                'amount' => Money::toDecimal($purchase->amount_minor),
                'reference' => $purchase->reference,
                'car_dealer_changed_from' => $previousDealer !== $purchase->dealer_id ? $previousDealer : null,
            ]);

            return $purchase;
        });
    }
}
