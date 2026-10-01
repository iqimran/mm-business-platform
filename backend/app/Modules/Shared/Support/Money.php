<?php

namespace App\Modules\Shared\Support;

use InvalidArgumentException;

/**
 * Money as integer minor units (2 decimal places). Never uses floats for arithmetic.
 * API values are decimal strings, e.g. "700000.00".
 */
final class Money
{
    /** Largest accepted amount: 999,999,999,999.99 (fits comfortably in bigint). */
    public const MAX_MINOR = 99_999_999_999_999;

    private const PATTERN = '/^(0|[1-9]\d{0,11})(\.\d{1,2})?$/';

    public static function isValidDecimal(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && preg_match(self::PATTERN, (string) $value) === 1;
    }

    /**
     * Parses "1234.5" → 123450. Accepts strings and integers only (no floats).
     */
    public static function toMinor(string|int $value): int
    {
        $value = (string) $value;

        if (! self::isValidDecimal($value)) {
            throw new InvalidArgumentException('Invalid money amount.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /**
     * Formats 123450 → "1234.50" (negative values keep their sign).
     */
    public static function toDecimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign.intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
