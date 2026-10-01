<?php

namespace App\Modules\Car\Services;

use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarPurchase;
use App\Modules\Car\Models\CarSale;
use App\Modules\Car\Support\FinancialFormulas;
use App\Modules\Shared\Support\Money;

/**
 * Per-car financial position from active (non-reversed) records. The three concepts are
 * computed independently and returned in separate blocks; they are never combined.
 */
class CarFinancials
{
    public function __construct(private readonly CarCosts $costs) {}

    public function activeSale(Car $car): ?CarSale
    {
        return $car->sales()->active()->first();
    }

    public function activePurchase(Car $car): ?CarPurchase
    {
        return $car->purchases()->active()->first();
    }

    /** Payments received against the sale (Party Due input). */
    public function partyReceived(CarSale $sale): int
    {
        return (int) $sale->payments()->active()->sum('amount_minor');
    }

    /** Payments made against the purchase (Dealer Payable input). */
    public function dealerPaid(CarPurchase $purchase): int
    {
        return (int) $purchase->payments()->active()->sum('amount_minor');
    }

    /**
     * @return array{amount: string, received: string, due: string}|null
     */
    public function partyPosition(Car $car): ?array
    {
        $sale = $this->activeSale($car);
        if ($sale === null) {
            return null;
        }

        $received = $this->partyReceived($sale);

        return [
            'amount' => Money::toDecimal($sale->amount_minor),
            'received' => Money::toDecimal($received),
            'due' => Money::toDecimal(FinancialFormulas::partyDue($sale->amount_minor, $received)),
        ];
    }

    /**
     * @return array{purchase_amount: string, paid: string, payable: string}|null
     */
    public function dealerPosition(Car $car): ?array
    {
        $purchase = $this->activePurchase($car);
        if ($purchase === null) {
            return null;
        }

        $paid = $this->dealerPaid($purchase);

        return [
            'purchase_amount' => Money::toDecimal($purchase->amount_minor),
            'paid' => Money::toDecimal($paid),
            'payable' => Money::toDecimal(FinancialFormulas::dealerPayable($purchase->amount_minor, $paid)),
        ];
    }

    /** Profit = Sale Price - Purchase Cost - Car Expenses; null until the car is sold. */
    public function profit(Car $car): ?string
    {
        $sale = $this->activeSale($car);
        if ($sale === null) {
            return null;
        }

        return Money::toDecimal(FinancialFormulas::profit(
            $sale->amount_minor,
            $this->costs->purchaseCost($car),
            $this->costs->expensesTotal($car),
        ));
    }
}
