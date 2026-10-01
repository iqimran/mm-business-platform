<?php

namespace Tests\Unit;

use App\Modules\Shared\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function validAmounts(): array
    {
        return [
            ['700000', 70000000],
            ['700000.00', 70000000],
            ['0.5', 50],
            ['1234.56', 123456],
            ['0.01', 1],
            [55000, 5500000],
            ['999999999999.99', Money::MAX_MINOR],
        ];
    }

    #[DataProvider('validAmounts')]
    public function test_parses_decimal_strings_to_minor_units(string|int $input, int $expected): void
    {
        $this->assertSame($expected, Money::toMinor($input));
    }

    public static function invalidAmounts(): array
    {
        return [['1.234'], ['-1'], ['1e5'], [' 1'], ['01'], ['1,000'], [''], ['abc'], ['1.'], ['1000000000000']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_malformed_amounts(string $input): void
    {
        $this->assertFalse(Money::isValidDecimal($input));
        $this->expectException(InvalidArgumentException::class);
        Money::toMinor($input);
    }

    public function test_floats_are_never_accepted(): void
    {
        $this->assertFalse(Money::isValidDecimal(1.5));
        $this->assertFalse(Money::isValidDecimal(0.1 + 0.2));
    }

    public function test_formats_minor_units_as_decimal_strings(): void
    {
        $this->assertSame('700000.00', Money::toDecimal(70000000));
        $this->assertSame('0.05', Money::toDecimal(5));
        $this->assertSame('-1.50', Money::toDecimal(-150));
        $this->assertSame('0.00', Money::toDecimal(0));
    }
}
