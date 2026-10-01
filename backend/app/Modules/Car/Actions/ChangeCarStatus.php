<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Services\CarFinancials;
use App\Modules\Car\Support\CarLifecycle;
use App\Modules\Car\Support\FinancialFormulas;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Manual lifecycle transitions. SOLD is reached only by recording a sale.
 */
class ChangeCarStatus
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CarFinancials $financials,
    ) {}

    public function handle(User $actor, Car $car, CarStatus $to, ?string $reason = null): Car
    {
        return DB::transaction(function () use ($actor, $car, $to, $reason) {
            $car = Car::whereKey($car->getKey())->lockForUpdate()->firstOrFail();
            $from = $car->status;

            if (! CarLifecycle::canTransition($from, $to)) {
                throw new ConflictHttpException("A car cannot move from {$from->value} to {$to->value}.".
                    ($to === CarStatus::Sold ? ' Record a sale instead.' : ''));
            }

            if ($to === CarStatus::Completed) {
                $sale = $this->financials->activeSale($car) ?? throw new ConflictHttpException('This car has no active sale.');
                $due = FinancialFormulas::partyDue($sale->amount_minor, $this->financials->partyReceived($sale));
                if ($due !== 0) {
                    throw new ConflictHttpException('The sale cannot be completed while the party still owes '.Money::toDecimal($due).'.');
                }
            }

            if ($from === CarStatus::Completed && blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'A reason is required to reopen a completed sale.']);
            }

            $car->forceFill(['status' => $to])->save();

            $this->audit->record('car.status_changed', 'car', $car->id, $actor->id, $car->branch_id,
                oldValues: ['status' => $from->value],
                newValues: array_filter(['status' => $to->value, 'reason' => $reason]));

            return $car;
        });
    }
}
