<?php

namespace App\Modules\Car\Services;

use App\Modules\Car\Models\Car;
use App\Modules\Car\Support\FinancialFormulas;
use App\Modules\Shared\Support\Money;

/**
 * Cost side of a car from active (non-reversed) records only.
 * Sale, profit, party due and dealer payable are added by the sale/payment tasks.
 */
class CarCosts
{
    public function purchaseCost(Car $car): int
    {
        return (int) $car->purchases()->active()->sum('amount_minor');
    }

    public function expensesTotal(Car $car): int
    {
        return (int) $car->expenses()->active()->sum('amount_minor');
    }

    /**
     * @return array{purchase_cost: string, expenses_total: string, total_investment: string}
     */
    public function summary(Car $car): array
    {
        $purchase = $this->purchaseCost($car);
        $expenses = $this->expensesTotal($car);

        return [
            'purchase_cost' => Money::toDecimal($purchase),
            'expenses_total' => Money::toDecimal($expenses),
            'total_investment' => Money::toDecimal(FinancialFormulas::totalInvestment($purchase, $expenses)),
        ];
    }
}
