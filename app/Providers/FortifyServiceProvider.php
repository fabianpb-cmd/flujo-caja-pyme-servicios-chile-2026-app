<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Cache\RateLimiting\Limit;
use Laravel\Fortify\Contracts\FailedPasswordConfirmationResponse as FailedPasswordConfirmationResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();

        $this->app->singleton(FailedPasswordConfirmationResponseContract::class, \App\Http\Responses\FailedPasswordConfirmationResponse::class);
    }

    public function boot(): void
    {
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));

        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = Str::lower(trim((string) $request->input('email')));

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('active', true)
                ->first();

            if (! $user || ! Hash::check((string) $request->input('password'), (string) $user->password)) {
                return null;
            }

            return $user;
        });

        RateLimiter::for('two-factor', function (Request $request): Limit {
            $challengedUser = (string) $request->session()->get('login.id', 'guest');

            return Limit::perMinute(5)->by($challengedUser.'|'.$request->ip());
        });
    }
}
