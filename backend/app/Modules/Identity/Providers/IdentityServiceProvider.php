<?php

namespace App\Modules\Identity\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();

        Password::defaults(function () {
            $rule = Password::min(10)->letters()->mixedCase()->numbers();

            // Breach check calls an external API; production only.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Reset links point at the Next.js SPA, which posts back to the API.
        ResetPassword::createUrlUsing(fn ($user, string $token) => config('app.frontend_url')
            .'/reset-password?token='.urlencode($token).'&email='.urlencode($user->getEmailForPasswordReset()));
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
