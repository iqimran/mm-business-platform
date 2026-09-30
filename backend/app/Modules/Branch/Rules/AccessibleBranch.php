<?php

namespace App\Modules\Branch\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/**
 * Validates a client-supplied branch_id against the authenticated user's branch access.
 */
class AccessibleBranch implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = Auth::user();

        if ($user === null || ! is_string($value) || ! $user->canAccessBranch($value)) {
            $fail('The selected branch is invalid.');
        }
    }
}
