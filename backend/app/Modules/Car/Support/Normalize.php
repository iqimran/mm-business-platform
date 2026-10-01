<?php

namespace App\Modules\Car\Support;

/**
 * Canonical forms used for storage and duplicate detection.
 */
final class Normalize
{
    /** Chassis/engine numbers: uppercase, all whitespace removed. */
    public static function identifier(?string $value): ?string
    {
        $value = $value === null ? null : strtoupper(preg_replace('/\s+/', '', $value));

        return $value === '' ? null : $value;
    }

    /** Registration plates: uppercase, single spaces. */
    public static function registration(?string $value): ?string
    {
        $value = $value === null ? null : strtoupper(trim(preg_replace('/\s+/', ' ', $value)));

        return $value === '' ? null : $value;
    }

    /** Phone numbers: digits only, keeping a leading "+". */
    public static function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        $digits = preg_replace('/\D+/', '', $trimmed);

        if ($digits === '') {
            return null;
        }

        return (str_starts_with($trimmed, '+') ? '+' : '').$digits;
    }

    public static function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
