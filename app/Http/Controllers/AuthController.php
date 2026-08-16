<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const LOGIN_MAX_ATTEMPTS = 5;
    private const LOGIN_DECAY_SECONDS = 60;
    private const SESSION_EXPIRED_MESSAGE = 'Tu sesión expiró por seguridad. Ingresa nuevamente.';

    public function home(Request $request): View|RedirectResponse|Response
    {
        return $request->user()
            ? redirect()->route('dashboard')
            : response()->view('auth.login');
    }

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse|Response
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $message = "Demasiados intentos de acceso. Intente nuevamente en {$seconds} segundos.";

            return response()
                ->view('auth.login', [
                    'emailPrefill' => $credentials['email'],
                    'loginError' => $message,
                ], 429);
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower(trim((string) $credentials['email']))])
            ->where('active', true)
            ->first();

        if (! $user || ! Hash::check((string) $credentials['password'], (string) $user->password)) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => __('Las credenciales no coinciden con nuestros registros.'),
            ]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            RateLimiter::clear($throttleKey);

            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false,
            ]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, false);
        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $request->session()->put('auth_session_started_at', now()->timestamp);

        if ($user->role === 'admin' && ! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('account.security');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function keepAlive(Request $request): Response
    {
        $request->session()->put('auth_last_activity_at', now()->timestamp);

        return response()->noContent();
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public static function sessionExpiredMessage(): string
    {
        return self::SESSION_EXPIRED_MESSAGE;
    }

    private function throttleKey(Request $request): string
    {
        return Str::lower(trim((string) $request->input('email'))).'|'.$request->ip();
    }
}
