<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request): void
    {
        $userId = $request->user()->id;

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $this->audit->record('auth.logout', 'user', $userId, $userId);
    }
}
