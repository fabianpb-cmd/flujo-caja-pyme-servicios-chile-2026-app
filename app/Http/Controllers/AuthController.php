<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Services\SecurityEventLogger;
use App\Support\Security\AuthenticationRateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const SESSION_EXPIRED_MESSAGE = 'Tu sesión expiró por seguridad. Ingresa nuevamente.';

    public function __construct(
        private readonly AuthenticationRateLimiter $rateLimiter,
        private readonly SecurityEventLogger $securityEvents,
    ) {
    }

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

        if ($this->rateLimiter->loginIsLimited($request)) {
            $seconds = $this->rateLimiter->loginAvailableIn($request);
            $message = "Demasiados intentos de acceso. Intente nuevamente en {$seconds} segundos.";
            $this->securityEvents->record('SECURITY_LOGIN_RATE_LIMITED', $request, email: $credentials['email']);

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
            $this->rateLimiter->hitLogin($request);
            $this->securityEvents->record('SECURITY_LOGIN_FAILED', $request, $user, $credentials['email']);

            throw ValidationException::withMessages([
                'email' => __('Las credenciales no coinciden con nuestros registros.'),
            ]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->rateLimiter->clearLogin($request);

            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false,
            ]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, false);
        $this->rateLimiter->clearLogin($request);
        $request->session()->regenerate();
        $request->session()->put('auth_session_started_at', now()->timestamp);
        $this->securityEvents->record('SECURITY_LOGIN_SUCCESS', $request, $user);

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
}
