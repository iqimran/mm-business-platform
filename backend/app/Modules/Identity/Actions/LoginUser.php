<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class LoginUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Authenticate against the session guard. Unknown, wrong-password and inactive
     * accounts all receive the same error to avoid account enumeration.
     */
    public function handle(Request $request, string $email, string $password): User
    {
        // Session (cookie) auth is only available to requests from allowed SPA origins.
        if (! $request->hasSession()) {
            throw new BadRequestHttpException('Login must be performed from an allowed application origin.');
        }

        $authenticated = Auth::guard('web')->attempt([
            'email' => mb_strtolower(trim($email)),
            'password' => $password,
            'is_active' => true,
        ]);

        if (! $authenticated) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // Prevent session fixation.
        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        $this->audit->record('auth.login', 'user', $user->id, $user->id);

        return $user;
    }
}
