<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;

class AccountSecurityController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user()->fresh();

        return view('account.security', [
            'user' => $user,
            'isAdmin' => $user->role === 'admin',
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'twoFactorPending' => ! is_null($user->two_factor_secret) && is_null($user->two_factor_confirmed_at),
            'manualSecret' => $user->two_factor_secret ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null,
            'qrSvg' => $user->two_factor_secret ? $user->twoFactorQrCodeSvg() : null,
            'recoveryCodes' => $user->hasEnabledTwoFactorAuthentication() ? $user->recoveryCodes() : [],
            'passwordRecentlyConfirmed' => $this->passwordRecentlyConfirmed($request),
        ]);
    }

    public function enable(Request $request, EnableTwoFactorAuthentication $enable, AuditService $auditService): RedirectResponse
    {
        $this->ensurePasswordConfirmed($request);

        $user = $request->user();
        $enable($user, true);

        $auditService->record('2FA_ENABLED', $user, $user, ['enabled' => false], ['enabled' => false, 'pending_confirmation' => true]);

        return redirect()
            ->route('account.security')
            ->with('status', 'Se generó la configuración de autenticación en dos pasos. Escanea el código QR y confirma el código temporal.');
    }

    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm, AuditService $auditService): RedirectResponse
    {
        $this->ensurePasswordConfirmed($request);

        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        try {
            $confirm($request->user(), preg_replace('/\s+/', '', $validated['code']) ?? '');
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'code' => ['El código de verificación ingresado no es válido.'],
            ]);
        }

        $user = $request->user()->fresh();
        $auditService->record('2FA_CONFIRMED', $user, $user, ['enabled' => false], ['enabled' => true]);

        return redirect()
            ->route('account.security')
            ->with('status', 'La autenticación en dos pasos quedó activada correctamente.');
    }

    public function disable(Request $request, DisableTwoFactorAuthentication $disable, AuditService $auditService): RedirectResponse
    {
        $this->ensurePasswordConfirmed($request);

        $user = $request->user();
        $disable($user);

        $auditService->record('2FA_DISABLED', $user->fresh(), $user, ['enabled' => true], ['enabled' => false]);

        return redirect()
            ->route('account.security')
            ->with('status', 'La autenticación en dos pasos fue desactivada. Como administrador, deberás configurarla nuevamente para volver a operar.');
    }

    public function regenerateRecoveryCodes(Request $request, GenerateNewRecoveryCodes $generate, AuditService $auditService): RedirectResponse
    {
        $this->ensurePasswordConfirmed($request);

        $user = $request->user();
        $generate($user);

        $auditService->record('RECOVERY_CODES_REGENERATED', $user->fresh(), $user);

        return redirect()
            ->route('account.security')
            ->with('status', 'Se generó un nuevo set de códigos de recuperación.');
    }

    private function ensurePasswordConfirmed(Request $request): void
    {
        if ($this->passwordRecentlyConfirmed($request)) {
            return;
        }

        throw ValidationException::withMessages([
            'password_confirmation' => ['Confirma tu contraseña antes de continuar con la configuración de seguridad.'],
        ]);
    }

    private function passwordRecentlyConfirmed(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $timeout = (int) config('auth.password_timeout', 10800);

        return $confirmedAt > 0 && (now()->timestamp - $confirmedAt) <= $timeout;
    }
}
