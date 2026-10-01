<?php

namespace Tests\Unit;

use App\Modules\Car\Support\FinancialFormulas;
use App\Modules\Shared\Support\Money;
use PHPUnit\Framework\TestCase;

class FinancialFormulasTest extends TestCase
{
    public function test_profit_matches_domain_example(): void
    {
        // docs/06-car-domain.md: Purchase 700000, Expenses 55000, Sale 850000 → Profit 95000.
        $profit = FinancialFormulas::profit(Money::toMinor('850000'), Money::toMinor('700000'), Money::toMinor('55000'));

        $this->assertSame(Money::toMinor('95000'), $profit);
    }

    public function test_profit_can_be_negative_loss(): void
    {
        $this->assertSame(-Money::toMinor('5000'), FinancialFormulas::profit(Money::toMinor('750000'), Money::toMinor('700000'), Money::toMinor('55000')));
    }

    public function test_party_due_is_sale_amount_minus_payments_received(): void
    {
        $this->assertSame(Money::toMinor('350000'), FinancialFormulas::partyDue(Money::toMinor('850000'), Money::toMinor('500000')));
        $this->assertSame(0, FinancialFormulas::partyDue(Money::toMinor('850000'), Money::toMinor('850000')));
    }

    public function test_dealer_payable_is_purchase_amount_minus_payments_made(): void
    {
        $this->assertSame(Money::toMinor('200000'), FinancialFormulas::dealerPayable(Money::toMinor('700000'), Money::toMinor('500000')));
    }

    public function test_concepts_are_independent(): void
    {
        // Expenses affect profit/investment only — never what we owe the dealer or what the party owes us.
        $purchase = Money::toMinor('700000');
        $sale = Money::toMinor('850000');

        $this->assertSame($purchase, FinancialFormulas::dealerPayable($purchase, 0));
        $this->assertSame($sale, FinancialFormulas::partyDue($sale, 0));
        $this->assertSame(Money::toMinor('755000'), FinancialFormulas::totalInvestment($purchase, Money::toMinor('55000')));
    }
}
