<?php

namespace App\Http\Controllers;

use App\Services\SecurityEventLogger;
use App\Support\Security\AuthenticationRateLimiter;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly AuthenticationRateLimiter $rateLimiter,
        private readonly SecurityEventLogger $securityEvents,
    ) {
    }

    public function create(TwoFactorLoginRequest $request): View|RedirectResponse
    {
        if (! $request->hasChallengedUser()) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(TwoFactorLoginRequest $request, StatefulGuard $guard): RedirectResponse
    {
        $user = $request->challengedUser();

        if ($this->rateLimiter->twoFactorIsLimited($request, $user)) {
            $seconds = $this->rateLimiter->twoFactorAvailableIn($request, $user);
            $this->securityEvents->record('SECURITY_2FA_RATE_LIMITED', $request, $user);

            return back()->withErrors(['code' => "Demasiados intentos de verificación. Intente nuevamente en {$seconds} segundos."]);
        }

        if ($code = $request->validRecoveryCode()) {
            $user->replaceRecoveryCode($code);
        } elseif (! $request->hasValidCode()) {
            $this->rateLimiter->hitTwoFactor($request, $user);
            $this->securityEvents->record('SECURITY_2FA_FAILED', $request, $user);
            $key = $request->filled('recovery_code') ? 'recovery_code' : 'code';
            $message = $request->filled('recovery_code')
                ? 'El código de recuperación ingresado no es válido.'
                : 'El código de verificación ingresado no es válido.';

            return back()->withErrors([$key => $message]);
        }

        $guard->login($user, false);

        $this->rateLimiter->clearTwoFactor($request, $user);
        $request->session()->regenerate();
        $request->session()->put('auth_session_started_at', now()->timestamp);
        $this->securityEvents->record('SECURITY_2FA_SUCCESS', $request, $user);

        return redirect()->route('dashboard');
    }
}
