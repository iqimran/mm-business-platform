<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDealerPayment;
use App\Modules\Car\Models\CarExpense;
use App\Modules\Car\Models\CarPartyPayment;
use App\Modules\Car\Models\CarPurchase;
use App\Modules\Car\Models\CarSale;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reverses a financial record (correction pattern). The original row is kept unchanged
 * apart from its reversal marker, so history is never overwritten.
 *
 * Rules:
 * - purchase:       not for sold/completed cars; dealer payments must be reversed first
 * - expense:        not for completed cars
 * - sale:           not for completed cars; party payments must be reversed first; restores previous status
 * - party payment:  not for completed cars
 * - dealer payment: always allowed (dealer settlement is independent of the sale)
 */
class ReverseFinancialRecord
{
    private const KINDS = [
        CarPurchase::class => 'purchase',
        CarExpense::class => 'expense',
        CarSale::class => 'sale',
        CarPartyPayment::class => 'party_payment',
        CarDealerPayment::class => 'dealer_payment',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, CarPurchase|CarExpense|CarSale|CarPartyPayment|CarDealerPayment $record, string $reason): CarPurchase|CarExpense|CarSale|CarPartyPayment|CarDealerPayment
    {
        return DB::transaction(function () use ($actor, $record, $reason) {
            // Lock the car first (consistent lock order with recording actions), then the record.
            $car = Car::whereKey($record->car_id)->lockForUpdate()->firstOrFail();
            $record = $record::whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($record->isReversed()) {
                throw new ConflictHttpException('This record has already been reversed.');
            }

            $this->guard($record, $car);
            $statusBefore = $car->status;

            $record->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ])->save();

            $newValues = ['car_id' => $car->id, 'reversed' => true, 'reason' => $reason];

            if ($record instanceof CarSale) {
                $car->forceFill(['status' => $record->status_before_sale])->save();
                $newValues['status'] = $record->status_before_sale->value;
            }

            $kind = self::KINDS[$record::class];
            $this->audit->record("car.{$kind}_reversed", "car_{$kind}", $record->id, $actor->id, $record->branch_id,
                oldValues: ['amount' => Money::toDecimal($record->amount_minor), 'reversed' => false]
                    + ($record instanceof CarSale ? ['status' => $statusBefore->value] : []),
                newValues: $newValues);

            return $record;
        });
    }

    private function guard(CarPurchase|CarExpense|CarSale|CarPartyPayment|CarDealerPayment $record, Car $car): void
    {
        $status = $car->status;

        if ($record instanceof CarPurchase) {
            $this->guardPurchase($record, $status);
        } elseif ($record instanceof CarSale) {
            $this->guardSale($record, $status);
        } elseif (($record instanceof CarExpense || $record instanceof CarPartyPayment) && $status === CarStatus::Completed) {
            throw new ConflictHttpException('Records of a completed car cannot be reversed. Reopen the sale first.');
        }
    }

    private function guardPurchase(CarPurchase $purchase, CarStatus $status): void
    {
        if (in_array($status, [CarStatus::Sold, CarStatus::Completed], true)) {
            throw new ConflictHttpException('The purchase of a sold car cannot be reversed.');
        }
        if ($purchase->payments()->active()->exists()) {
            throw new ConflictHttpException('Reverse the dealer payments of this purchase first.');
        }
    }

    private function guardSale(CarSale $sale, CarStatus $status): void
    {
        if ($status === CarStatus::Completed) {
            throw new ConflictHttpException('A completed sale cannot be reversed. Reopen it first.');
        }
        if ($sale->payments()->active()->exists()) {
            throw new ConflictHttpException('Reverse the party payments of this sale first.');
        }
    }
}
