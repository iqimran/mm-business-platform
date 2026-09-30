<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResetPassword
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(string $email, string $token, string $password): void
    {
        $status = Password::broker()->reset(
            [
                'email' => mb_strtolower(trim($email)),
                'is_active' => true,
                'token' => $token,
                'password' => $password,
            ],
            function (User $user, string $password) {
                // Changing the hash also signs out existing sessions (AuthenticateSession).
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));

                $this->audit->record('auth.password_reset', 'user', $user->id, $user->id);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // One generic message for unknown email, inactive account or bad/expired token.
            throw ValidationException::withMessages(['email' => __(Password::INVALID_TOKEN)]);
        }
    }
}
