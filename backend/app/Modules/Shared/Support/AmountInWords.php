<?php

namespace App\Modules\Shared\Support;

/**
 * Amount in words with the South Asian (lakh/crore) numbering used in Bangladesh,
 * e.g. 85000000 minor units → "Taka Eight Lakh Fifty Thousand Only".
 */
final class AmountInWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function taka(int $minor): string
    {
        $minor = abs($minor);
        $taka = intdiv($minor, 100);
        $paisa = $minor % 100;

        $words = 'Taka '.($taka === 0 ? 'Zero' : self::number($taka));
        if ($paisa > 0) {
            $words .= ' and '.self::number($paisa).' Paisa';
        }

        return $words.' Only';
    }

    public static function number(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }

        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$size, $name]) {
            if ($n >= $size) {
                $count = intdiv($n, $size);
                // Above 99 crore the crore count itself is spelled out (e.g. "One Hundred Twenty Crore").
                $parts[] = ($size === 10000000 ? self::number($count) : self::belowHundred($count)).' '.$name;
                $n %= $size;
            }
        }
        if ($n > 0) {
            $parts[] = self::belowHundred($n);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return trim(self::TENS[intdiv($n, 10)].' '.self::ONES[$n % 10]);
    }
}
