<?php

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthenticationRateLimiter
{
    private const LOGIN_LIMITS = [
        'email_ip' => [5, 60],
        'email' => [20, 900],
        'ip' => [60, 600],
    ];

    private const TWO_FACTOR_LIMITS = [
        'user_ip' => [5, 60],
        'user' => [20, 900],
    ];

    public function loginIsLimited(Request $request): bool
    {
        foreach (self::LOGIN_LIMITS as $name => [$maxAttempts]) {
            if (RateLimiter::tooManyAttempts($this->loginKey($request, $name), $maxAttempts)) {
                return true;
            }
        }

        return false;
    }

    public function loginAvailableIn(Request $request): int
    {
        $seconds = 0;

        foreach (self::LOGIN_LIMITS as $name => [$maxAttempts]) {
            $key = $this->loginKey($request, $name);
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $seconds = max($seconds, RateLimiter::availableIn($key));
            }
        }

        return $seconds;
    }

    public function hitLogin(Request $request): void
    {
        foreach (self::LOGIN_LIMITS as $name => [, $decay]) {
            RateLimiter::hit($this->loginKey($request, $name), $decay);
        }
    }

    public function clearLogin(Request $request): void
    {
        foreach (array_keys(self::LOGIN_LIMITS) as $name) {
            RateLimiter::clear($this->loginKey($request, $name));
        }
    }

    public function twoFactorIsLimited(Request $request, User $user): bool
    {
        foreach (self::TWO_FACTOR_LIMITS as $name => [$maxAttempts]) {
            if (RateLimiter::tooManyAttempts($this->twoFactorKey($request, $user, $name), $maxAttempts)) {
                return true;
            }
        }

        return false;
    }

    public function twoFactorAvailableIn(Request $request, User $user): int
    {
        $seconds = 0;

        foreach (self::TWO_FACTOR_LIMITS as $name => [$maxAttempts]) {
            $key = $this->twoFactorKey($request, $user, $name);
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $seconds = max($seconds, RateLimiter::availableIn($key));
            }
        }

        return $seconds;
    }

    public function hitTwoFactor(Request $request, User $user): void
    {
        foreach (self::TWO_FACTOR_LIMITS as $name => [, $decay]) {
            RateLimiter::hit($this->twoFactorKey($request, $user, $name), $decay);
        }
    }

    public function clearTwoFactor(Request $request, User $user): void
    {
        foreach (array_keys(self::TWO_FACTOR_LIMITS) as $name) {
            RateLimiter::clear($this->twoFactorKey($request, $user, $name));
        }
    }

    private function loginKey(Request $request, string $scope): string
    {
        $email = Str::lower(trim((string) $request->input('email')));
        $ip = (string) $request->ip();

        return 'security:login:'.$scope.':'.hash('sha256', match ($scope) {
            'email_ip' => $email.'|'.$ip,
            'email' => $email,
            'ip' => $ip,
        });
    }

    private function twoFactorKey(Request $request, User $user, string $scope): string
    {
        return 'security:two-factor:'.$scope.':'.hash('sha256', match ($scope) {
            'user_ip' => $user->getKey().'|'.$request->ip(),
            'user' => (string) $user->getKey(),
        });
    }
}
