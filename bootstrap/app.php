<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\EnforceAbsoluteSessionLifetime;
use App\Http\Middleware\RequireAdminTwoFactor;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = env('TRUSTED_PROXIES');
        $trustedProxyHeaders = match ((string) env('TRUSTED_PROXY_HEADERS', '')) {
            'HEADER_X_FORWARDED_AWS_ELB' => Request::HEADER_X_FORWARDED_AWS_ELB,
            'HEADER_FORWARDED' => Request::HEADER_FORWARDED,
            'HEADER_X_FORWARDED_FOR' => Request::HEADER_X_FORWARDED_FOR,
            'HEADER_X_FORWARDED_HOST' => Request::HEADER_X_FORWARDED_HOST,
            'HEADER_X_FORWARDED_PORT' => Request::HEADER_X_FORWARDED_PORT,
            'HEADER_X_FORWARDED_PROTO' => Request::HEADER_X_FORWARDED_PROTO,
            'HEADER_X_FORWARDED_PREFIX' => Request::HEADER_X_FORWARDED_PREFIX,
            default => null,
        };

        if ($trustedProxies !== null && trim((string) $trustedProxies) !== '') {
            $middleware->trustProxies(
                at: trim((string) $trustedProxies) === '*'
                    ? '*'
                    : array_values(array_filter(array_map(trim(...), explode(',', (string) $trustedProxies)))),
                headers: $trustedProxyHeaders,
            );
        } elseif ($trustedProxyHeaders !== null) {
            $middleware->trustProxies(headers: $trustedProxyHeaders);
        }

        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'absolute.session' => EnforceAbsoluteSessionLifetime::class,
            'admin' => EnsureAdmin::class,
            'admin.2fa' => RequireAdminTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            $sessionCookie = (string) config('session.cookie');
            $hasPreviousSessionCookie = $sessionCookie !== '' && $request->cookies->has($sessionCookie);

            $response = redirect()->route('login');

            if ($hasPreviousSessionCookie) {
                $response->with('session_expired', 'Tu sesión expiró por seguridad. Ingresa nuevamente.');
            }

            return $response;
        });
    })->create();
