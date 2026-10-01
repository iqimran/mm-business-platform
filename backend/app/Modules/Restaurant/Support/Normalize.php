<?php

namespace App\Modules\Restaurant\Support;

/**
 * Canonical forms used for storage and duplicate detection in the Restaurant module.
 */
final class Normalize
{
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
