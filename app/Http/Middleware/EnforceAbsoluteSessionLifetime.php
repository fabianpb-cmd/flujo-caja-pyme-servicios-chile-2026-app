<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AuthController;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAbsoluteSessionLifetime
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $absoluteLifetimeMinutes = (int) config('session.absolute_lifetime', 480);
        if ($absoluteLifetimeMinutes <= 0) {
            return $next($request);
        }

        $session = $request->session();
        $startedAt = (int) $session->get('auth_session_started_at', 0);
        if ($startedAt <= 0) {
            $session->put('auth_session_started_at', now()->timestamp);

            return $next($request);
        }

        if (now()->timestamp - $startedAt >= ($absoluteLifetimeMinutes * 60)) {
            Auth::logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()
                ->route('login')
                ->with('session_expired', AuthController::sessionExpiredMessage());
        }

        return $next($request);
    }
}
