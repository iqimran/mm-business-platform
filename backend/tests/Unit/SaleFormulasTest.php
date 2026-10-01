<?php

namespace Tests\Unit;

use App\Modules\Restaurant\Enums\PaymentStatus;
use App\Modules\Restaurant\Support\SaleFormulas;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SaleFormulasTest extends TestCase
{
    public function test_line_total_is_quantity_times_unit_price(): void
    {
        $this->assertSame(12550, SaleFormulas::lineTotal(1, 12550));
        $this->assertSame(37650, SaleFormulas::lineTotal(3, 12550));
        $this->assertSame(9999 * 1, SaleFormulas::lineTotal(9999, 1));
    }

    public function test_invalid_lines_are_rejected(): void
    {
        foreach ([[0, 100], [10000, 100], [1, 0], [1, -5], [9999, PHP_INT_MAX]] as [$quantity, $price]) {
            try {
                SaleFormulas::lineTotal($quantity, $price);
                $this->fail("Accepted {$quantity} × {$price}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_total_due_and_status(): void
    {
        $this->assertSame(0, SaleFormulas::total([]));
        $this->assertSame(60150, SaleFormulas::total([37650, 22500]));
        $this->assertSame(60150, SaleFormulas::due(60150, 0));
        $this->assertSame(10150, SaleFormulas::due(60150, 50000));

        $this->assertSame(PaymentStatus::Unpaid, SaleFormulas::status(60150, 0));
        $this->assertSame(PaymentStatus::Partial, SaleFormulas::status(60150, 1));
        $this->assertSame(PaymentStatus::Partial, SaleFormulas::status(60150, 60149));
        $this->assertSame(PaymentStatus::Paid, SaleFormulas::status(60150, 60150));
    }

    public function test_total_overflow_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SaleFormulas::total([99_999_999_999_999, 1]);
    }
}
