<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Support\SaleFormulas;
use App\Modules\Shared\Support\Money;

/**
 * Per-sale figures (total, paid, due, payment status), always from SaleFormulas.
 */
class SaleFinancials
{
    /** Sum of active payments: uses the withPaid() column when loaded, otherwise queries. */
    public function paid(FoodSale $sale): int
    {
        return $sale->getAttribute('paid_minor') ?? (int) $sale->payments()->active()->sum('amount_minor');
    }

    public function due(FoodSale $sale): int
    {
        return SaleFormulas::due($sale->total_minor, $this->paid($sale));
    }

    /**
     * @return array{total: string, paid: string, due: string, payment_status: string}
     */
    public function position(FoodSale $sale): array
    {
        $paid = $this->paid($sale);

        return [
            'total' => Money::toDecimal($sale->total_minor),
            'paid' => Money::toDecimal($paid),
            'due' => Money::toDecimal(SaleFormulas::due($sale->total_minor, $paid)),
            'payment_status' => SaleFormulas::status($sale->total_minor, $paid)->value,
        ];
    }
}
