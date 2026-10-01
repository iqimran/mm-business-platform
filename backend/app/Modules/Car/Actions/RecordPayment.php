<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDealerPayment;
use App\Modules\Car\Models\CarPartyPayment;
use App\Modules\Car\Models\CarPurchase;
use App\Modules\Car\Models\CarSale;
use App\Modules\Car\Services\CarFinancials;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Records a payment against an obligation, never exceeding what is outstanding:
 * - party payment  → against the active sale     (reduces Party Due)
 * - dealer payment → against the active purchase (reduces Dealer Payable)
 * The obligation row is locked so concurrent payments cannot overpay.
 */
class RecordPayment
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CarFinancials $financials,
    ) {}

    /**
     * @param  'party'|'dealer'  $kind
     * @param  array{payment_date: string, amount: string|int, method: string, reference?: string|null, notes?: string|null}  $data
     */
    public function handle(User $actor, Car $car, string $kind, array $data): CarPartyPayment|CarDealerPayment
    {
        return DB::transaction(function () use ($actor, $car, $kind, $data) {
            $car = Car::whereKey($car->getKey())->lockForUpdate()->firstOrFail();
            $amount = Money::toMinor($data['amount']);

            if ($kind === 'party') {
                if ($car->status === CarStatus::Completed) {
                    throw new ConflictHttpException('This sale is completed. Reopen it to record more payments.');
                }
                /** @var CarSale|null $obligation */
                $obligation = $car->sales()->active()->lockForUpdate()->first()
                    ?? throw new ConflictHttpException('This car has no active sale to receive payments for.');
                $outstanding = $this->financials->partyOutstanding($obligation);
                $label = 'party due';
            } else {
                /** @var CarPurchase|null $obligation */
                $obligation = $car->purchases()->active()->lockForUpdate()->first()
                    ?? throw new ConflictHttpException('This car has no active purchase to pay the dealer for.');
                $outstanding = $this->financials->dealerOutstanding($obligation);
                $label = 'dealer payable';
            }

            if ($amount > $outstanding) {
                throw ValidationException::withMessages([
                    'amount' => "The amount exceeds the remaining {$label} of ".Money::toDecimal($outstanding).'.',
                ]);
            }

            $common = [
                'car_id' => $car->id,
                'branch_id' => $car->branch_id,
                'payment_date' => $data['payment_date'],
                'amount_minor' => $amount,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
            ];

            $payment = $kind === 'party'
                ? CarPartyPayment::create($common + ['sale_id' => $obligation->id, 'party_id' => $obligation->party_id])
                : CarDealerPayment::create($common + ['purchase_id' => $obligation->id, 'dealer_id' => $obligation->dealer_id]);

            $this->audit->record("car.{$kind}_payment_recorded", "car_{$kind}_payment", $payment->id, $actor->id, $car->branch_id, newValues: [
                'car_id' => $car->id,
                $kind === 'party' ? 'sale_id' : 'purchase_id' => $obligation->id,
                'payment_date' => $payment->payment_date->toDateString(),
                'amount' => Money::toDecimal($amount),
                'method' => $payment->method->value,
                'outstanding_after' => Money::toDecimal($outstanding - $amount),
            ]);

            return $payment;
        });
    }
}
