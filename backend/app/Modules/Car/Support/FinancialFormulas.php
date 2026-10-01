<?php

namespace App\Modules\Car\Support;

/**
 * The three Car financial concepts (docs/06-car-domain.md). They are deliberately
 * separate and must never be combined. All values are integer minor units.
 */
final class FinancialFormulas
{
    /** Profit = Sale Price - Purchase Cost - Car Expenses */
    public static function profit(int $salePrice, int $purchaseCost, int $expenses): int
    {
        return $salePrice - $purchaseCost - $expenses;
    }

    /** Party Due = Sale Amount - Payments Received (what the customer still owes). */
    public static function partyDue(int $saleAmount, int $paymentsReceived): int
    {
        return $saleAmount - $paymentsReceived;
    }

    /** Dealer Payable = Purchase Amount - Payments Made (what we still owe the dealer). */
    public static function dealerPayable(int $purchaseAmount, int $paymentsMade): int
    {
        return $purchaseAmount - $paymentsMade;
    }

    /** Total investment = Purchase Cost + Car Expenses (cost side of profit). */
    public static function totalInvestment(int $purchaseCost, int $expenses): int
    {
        return $purchaseCost + $expenses;
    }
}
