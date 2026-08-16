<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminTwoFactor
{
    private const ALLOWED_ROUTE_NAMES = [
        'account.security',
        'account.security.enable-2fa',
        'account.security.confirm-2fa',
        'account.security.disable-2fa',
        'account.security.regenerate-recovery-codes',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'logout',
        'session.keep-alive',
    ];

    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if (app()->runningUnitTests() && ! config('fortify.enforce_admin_two_factor_in_tests', false)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || $user->role !== 'admin' || $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if (is_string($routeName) && in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            return $next($request);
        }

        return redirect()
            ->route('account.security')
            ->with('status', 'Debes configurar y confirmar la verificación en dos pasos para continuar.');
    }
}
