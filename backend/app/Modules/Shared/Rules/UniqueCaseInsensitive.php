<?php

namespace App\Modules\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Unique check matching a "lower(column)" unique index.
 */
class UniqueCaseInsensitive implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly string $column,
        private readonly ?string $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $taken = DB::table($this->table)
            ->whereRaw("lower({$this->column}) = ?", [mb_strtolower(trim($value))])
            ->when($this->ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($taken) {
            $fail('The :attribute has already been taken.');
        }
    }
}
