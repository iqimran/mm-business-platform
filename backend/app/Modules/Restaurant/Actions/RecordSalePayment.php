<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\FoodSalePayment;
use App\Modules\Restaurant\Services\SaleFinancials;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Records a payment against a food sale, never exceeding the due.
 * The sale row is locked so concurrent payments cannot overpay.
 */
class RecordSalePayment
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SaleFinancials $financials,
    ) {}

    /**
     * @param  array{payment_date: string, amount: string|int, method: string, reference?: string|null, notes?: string|null}  $data
     */
    public function handle(User $actor, FoodSale $sale, array $data): FoodSalePayment
    {
        return DB::transaction(function () use ($actor, $sale, $data) {
            $sale = FoodSale::whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if ($sale->isReversed()) {
                throw new ConflictHttpException('This sale has been reversed and cannot receive payments.');
            }

            $due = $this->financials->due($sale);
            $amount = Money::toMinor($data['amount']);

            if ($due <= 0) {
                throw new ConflictHttpException('This sale is already fully paid.');
            }
            if ($amount > $due) {
                throw ValidationException::withMessages([
                    'amount' => 'The amount exceeds the remaining due of '.Money::toDecimal($due).'.',
                ]);
            }
            if ($data['payment_date'] < $sale->sold_at->toDateString()) {
                throw ValidationException::withMessages(['payment_date' => 'The payment date cannot be before the sale date.']);
            }

            $payment = FoodSalePayment::create([
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'payment_date' => $data['payment_date'],
                'amount_minor' => $amount,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->record('restaurant.sale_payment.recorded', 'restaurant_sale_payment', $payment->id, $actor->id, $sale->branch_id, newValues: [
                'sale_id' => $sale->id,
                'payment_date' => $payment->payment_date->toDateString(),
                'amount' => Money::toDecimal($amount),
                'method' => $payment->method->value,
                'due_after' => Money::toDecimal($due - $amount),
            ]);

            return $payment;
        });
    }
}
