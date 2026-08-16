<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

class TwoFactorChallengeController extends Controller
{
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

        if ($code = $request->validRecoveryCode()) {
            $user->replaceRecoveryCode($code);
        } elseif (! $request->hasValidCode()) {
            $key = $request->filled('recovery_code') ? 'recovery_code' : 'code';
            $message = $request->filled('recovery_code')
                ? 'El código de recuperación ingresado no es válido.'
                : 'El código de verificación ingresado no es válido.';

            return back()->withErrors([$key => $message]);
        }

        $guard->login($user, false);

        $request->session()->regenerate();
        $request->session()->put('auth_session_started_at', now()->timestamp);

        return redirect()->route('dashboard');
    }
}
