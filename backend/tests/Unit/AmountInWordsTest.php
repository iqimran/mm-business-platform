<?php

namespace Tests\Unit;

use App\Modules\Shared\Support\AmountInWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountInWordsTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            [85000000, 'Taka Eight Lakh Fifty Thousand Only'],
            [35000050, 'Taka Three Lakh Fifty Thousand and Fifty Paisa Only'],
            [10000, 'Taka One Hundred Only'],
            [1, 'Taka Zero and One Paisa Only'],
            [0, 'Taka Zero Only'],
            [1500000000, 'Taka One Crore Fifty Lakh Only'],
            [1250000000000, 'Taka One Thousand Two Hundred Fifty Crore Only'],
            [1911, 'Taka Nineteen and Eleven Paisa Only'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_lakh_crore_wording(int $minor, string $expected): void
    {
        $this->assertSame($expected, AmountInWords::taka($minor));
    }
}
