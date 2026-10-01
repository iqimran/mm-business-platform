<?php

namespace App\Modules\Car\Support;

/**
 * Immutable financial position of one car, in minor units, from active (non-reversed) records.
 * Built only by CarFinancials; the three concepts stay in separate fields and are never combined.
 */
final class CarFinancialSnapshot
{
    public function __construct(
        // Cost side
        public readonly int $purchaseCost,
        public readonly int $expensesTotal,
        public readonly int $totalInvestment,
        // Sale side (null when the car has no active sale)
        public readonly ?int $salePrice,
        public readonly ?int $partyReceived,
        public readonly ?int $partyDue,
        // Dealer side (null when the car has no active purchase)
        public readonly ?int $dealerPurchaseAmount,
        public readonly ?int $dealerPaid,
        public readonly ?int $dealerPayable,
        // Profit = Sale Price - Purchase Cost - Car Expenses (null until sold)
        public readonly ?int $profit,
    ) {}

    public function isSold(): bool
    {
        return $this->salePrice !== null;
    }

    public function isPartySettled(): bool
    {
        return $this->partyDue === 0;
    }

    public function isDealerSettled(): bool
    {
        return $this->dealerPayable === 0;
    }
}
