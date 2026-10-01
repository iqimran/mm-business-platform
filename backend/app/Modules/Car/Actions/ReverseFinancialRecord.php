<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\CarExpense;
use App\Modules\Car\Models\CarPurchase;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reverses a purchase or expense (correction pattern). The original record is kept
 * unchanged apart from its reversal marker, so history is never overwritten.
 */
class ReverseFinancialRecord
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, CarPurchase|CarExpense $record, string $reason): CarPurchase|CarExpense
    {
        return DB::transaction(function () use ($actor, $record, $reason) {
            $record = $record::whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($record->isReversed()) {
                throw new ConflictHttpException('This record has already been reversed.');
            }

            $car = $record->car;
            $blocking = $record instanceof CarPurchase
                ? [CarStatus::Sold, CarStatus::Completed] // purchase cost of a sold car is fixed
                : [CarStatus::Completed];
            if (in_array($car->status, $blocking, true)) {
                throw new ConflictHttpException('Records of a '.strtolower($car->status->name).' car cannot be reversed.');
            }

            $record->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ])->save();

            $kind = $record instanceof CarPurchase ? 'purchase' : 'expense';
            $this->audit->record("car.{$kind}_reversed", "car_{$kind}", $record->id, $actor->id, $record->branch_id,
                oldValues: ['amount' => Money::toDecimal($record->amount_minor), 'reversed' => false],
                newValues: ['car_id' => $car->id, 'reversed' => true, 'reason' => $reason]);

            return $record;
        });
    }
}
