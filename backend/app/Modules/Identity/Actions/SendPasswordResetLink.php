<?php

namespace App\Modules\Identity\Actions;

use Illuminate\Support\Facades\Password;

class SendPasswordResetLink
{
    /**
     * The broker's status is intentionally ignored so callers cannot tell
     * whether an account exists. Inactive accounts receive no link.
     */
    public function handle(string $email): void
    {
        Password::broker()->sendResetLink([
            'email' => mb_strtolower(trim($email)),
            'is_active' => true,
        ]);
    }
}
