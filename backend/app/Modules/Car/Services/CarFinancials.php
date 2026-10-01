<?php

namespace App\Modules\Car\Services;

use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarPurchase;
use App\Modules\Car\Models\CarSale;
use App\Modules\Car\Support\CarFinancialSnapshot;
use App\Modules\Car\Support\FinancialFormulas;
use App\Modules\Shared\Support\Money;

/**
 * The single source of per-car financial figures. Every amount comes from active
 * (non-reversed) records, and every formula from FinancialFormulas. Actions, controllers
 * and the frontend must use this service instead of summing or subtracting themselves.
 */
class CarFinancials
{
    // ---- Inputs (sums of active records, minor units) ----

    public function purchaseCost(Car $car): int
    {
        return (int) $car->purchases()->active()->sum('amount_minor');
    }

    public function expensesTotal(Car $car): int
    {
        return (int) $car->expenses()->active()->sum('amount_minor');
    }

    public function activeSale(Car $car): ?CarSale
    {
        return $car->sales()->active()->first();
    }

    public function activePurchase(Car $car): ?CarPurchase
    {
        return $car->purchases()->active()->first();
    }

    public function partyReceived(CarSale $sale): int
    {
        return (int) $sale->payments()->active()->sum('amount_minor');
    }

    public function dealerPaid(CarPurchase $purchase): int
    {
        return (int) $purchase->payments()->active()->sum('amount_minor');
    }

    // ---- Outstanding amounts (used by payment validation and completion) ----

    /** Party Due of a sale: what the customer still owes. */
    public function partyOutstanding(CarSale $sale): int
    {
        return FinancialFormulas::partyDue($sale->amount_minor, $this->partyReceived($sale));
    }

    /** Dealer Payable of a purchase: what is still owed to the dealer. */
    public function dealerOutstanding(CarPurchase $purchase): int
    {
        return FinancialFormulas::dealerPayable($purchase->amount_minor, $this->dealerPaid($purchase));
    }

    // ---- Full position ----

    public function snapshot(Car $car): CarFinancialSnapshot
    {
        $purchaseCost = $this->purchaseCost($car);
        $expenses = $this->expensesTotal($car);

        $sale = $this->activeSale($car);
        $received = $sale ? $this->partyReceived($sale) : null;

        $purchase = $this->activePurchase($car);
        $paid = $purchase ? $this->dealerPaid($purchase) : null;

        return new CarFinancialSnapshot(
            purchaseCost: $purchaseCost,
            expensesTotal: $expenses,
            totalInvestment: FinancialFormulas::totalInvestment($purchaseCost, $expenses),
            salePrice: $sale?->amount_minor,
            partyReceived: $received,
            partyDue: $sale ? FinancialFormulas::partyDue($sale->amount_minor, $received) : null,
            dealerPurchaseAmount: $purchase?->amount_minor,
            dealerPaid: $paid,
            dealerPayable: $purchase ? FinancialFormulas::dealerPayable($purchase->amount_minor, $paid) : null,
            profit: $sale ? FinancialFormulas::profit($sale->amount_minor, $purchaseCost, $expenses) : null,
        );
    }

    // ---- API shapes (decimal strings) ----

    /**
     * @return array{purchase_cost: string, expenses_total: string, total_investment: string}
     */
    public function costs(Car $car, ?CarFinancialSnapshot $snapshot = null): array
    {
        $s = $snapshot ?? $this->snapshot($car);

        return [
            'purchase_cost' => Money::toDecimal($s->purchaseCost),
            'expenses_total' => Money::toDecimal($s->expensesTotal),
            'total_investment' => Money::toDecimal($s->totalInvestment),
        ];
    }

    /**
     * @return array{amount: string, received: string, due: string, is_settled: bool}|null
     */
    public function partyPosition(Car $car, ?CarFinancialSnapshot $snapshot = null): ?array
    {
        $s = $snapshot ?? $this->snapshot($car);
        if (! $s->isSold()) {
            return null;
        }

        return [
            'amount' => Money::toDecimal($s->salePrice),
            'received' => Money::toDecimal($s->partyReceived),
            'due' => Money::toDecimal($s->partyDue),
            'is_settled' => $s->isPartySettled(),
        ];
    }

    /**
     * @return array{purchase_amount: string, paid: string, payable: string, is_settled: bool}|null
     */
    public function dealerPosition(Car $car, ?CarFinancialSnapshot $snapshot = null): ?array
    {
        $s = $snapshot ?? $this->snapshot($car);
        if ($s->dealerPurchaseAmount === null) {
            return null;
        }

        return [
            'purchase_amount' => Money::toDecimal($s->dealerPurchaseAmount),
            'paid' => Money::toDecimal($s->dealerPaid),
            'payable' => Money::toDecimal($s->dealerPayable),
            'is_settled' => $s->isDealerSettled(),
        ];
    }

    public function profit(Car $car, ?CarFinancialSnapshot $snapshot = null): ?string
    {
        $s = $snapshot ?? $this->snapshot($car);

        return $s->profit === null ? null : Money::toDecimal($s->profit);
    }
}
