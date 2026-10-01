<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\FoodSalePayment;
use App\Modules\Restaurant\Services\SaleFinancials;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reverses (corrects) a sale payment. The row is kept with its reversal marker; the due increases again.
 */
class ReverseSalePayment
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SaleFinancials $financials,
    ) {}

    public function handle(User $actor, FoodSale $sale, FoodSalePayment $payment, string $reason): FoodSalePayment
    {
        return DB::transaction(function () use ($actor, $sale, $payment, $reason) {
            // Lock order: sale, then payment (same as recording).
            $sale = FoodSale::whereKey($sale->getKey())->lockForUpdate()->firstOrFail();
            $payment = FoodSalePayment::whereKey($payment->getKey())->where('sale_id', $sale->id)->lockForUpdate()->firstOrFail();

            if ($payment->isReversed()) {
                throw new ConflictHttpException('This payment has already been reversed.');
            }

            $payment->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ])->save();

            $this->audit->record('restaurant.sale_payment.reversed', 'restaurant_sale_payment', $payment->id, $actor->id, $sale->branch_id,
                oldValues: ['amount' => Money::toDecimal($payment->amount_minor), 'reversed' => false],
                newValues: ['sale_id' => $sale->id, 'reversed' => true, 'reason' => $reason, 'due_after' => Money::toDecimal($this->financials->due($sale))]);

            return $payment;
        });
    }
}
