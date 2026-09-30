<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Str;

class ChangePassword
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Other sessions are signed out on their next request: Sanctum's AuthenticateSession
     * middleware detects the changed password hash. The current session keeps working.
     */
    public function handle(User $user, string $newPassword): void
    {
        $user->forceFill([
            'password' => $newPassword,
            'remember_token' => Str::random(60),
        ])->save();

        $this->audit->record('auth.password_changed', 'user', $user->id, $user->id);
    }
}
