<?php

namespace App\Modules\Shared\Rules;

use App\Modules\Shared\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A positive amount with at most 2 decimals, sent as a string (preferred) or integer.
 * Floats are rejected to avoid binary rounding issues. Zero is accepted only when explicitly allowed.
 */
class MoneyAmount implements ValidationRule
{
    public function __construct(private readonly bool $allowZero = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Money::isValidDecimal($value)) {
            $fail('The :attribute must be an amount with at most 2 decimal places, e.g. "1500.00".');

            return;
        }

        $minor = Money::toMinor($value);

        if ($minor < 0 || ($minor === 0 && ! $this->allowZero)) {
            $fail('The :attribute must be greater than zero.');
        } elseif ($minor > Money::MAX_MINOR) {
            $fail('The :attribute is too large.');
        }
    }
}
