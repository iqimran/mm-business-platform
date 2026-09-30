<?php

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureAuthorization();

        Password::defaults(function () {
            $rule = Password::min(10)->letters()->mixedCase()->numbers();

            // Breach check calls an external API; production only.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Reset links point at the Next.js SPA, which posts back to the API.
        ResetPassword::createUrlUsing(fn ($user, string $token) => config('app.frontend_url')
            .'/reset-password?token='.urlencode($token).'&email='.urlencode($user->getEmailForPasswordReset()));
    }

    /**
     * Permission names are Gate abilities: $user->can('user.view'), ->middleware('can:user.view').
     * Only argument-less checks are answered here; checks against a model go to its policy,
     * which must also enforce branch access (see BranchScopedPolicy).
     */
    private function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability, array $arguments = []) {
            if ($arguments === [] && preg_match(Permission::NAME_PATTERN, $ability) === 1) {
                return $user->hasPermission($ability);
            }

            return null;
        });
    }

    private function configureRateLimiting(): void
    {
        $emailKey = fn (Request $request) => mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip();

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.$emailKey($request)),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(3)->by('reset:'.$emailKey($request)),
            Limit::perMinute(10)->by('reset-ip:'.$request->ip()),
        ]);

        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by('password-change:'.$request->user()?->id));
    }
}
