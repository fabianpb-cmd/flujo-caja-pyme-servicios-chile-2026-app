<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\SecurityEventLogger;
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
        $passwordRecentlyConfirmed = $this->passwordRecentlyConfirmed($request);
        $canRevealTwoFactorSecrets = $passwordRecentlyConfirmed && ! is_null($user->two_factor_secret);

        return view('account.security', [
            'user' => $user,
            'isAdmin' => $user->role === 'admin',
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'twoFactorPending' => ! is_null($user->two_factor_secret) && is_null($user->two_factor_confirmed_at),
            'manualSecret' => $canRevealTwoFactorSecrets ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null,
            'qrSvg' => $canRevealTwoFactorSecrets ? $user->twoFactorQrCodeSvg() : null,
            'recoveryCodes' => $canRevealTwoFactorSecrets && $user->hasEnabledTwoFactorAuthentication() ? $user->recoveryCodes() : [],
            'passwordRecentlyConfirmed' => $passwordRecentlyConfirmed,
        ]);
    }

    public function enable(Request $request, EnableTwoFactorAuthentication $enable, AuditService $auditService, SecurityEventLogger $securityEvents): RedirectResponse
    {
        $this->ensurePasswordConfirmed($request);

        $user = $request->user();
        $enable($user, true);

        $auditService->record('2FA_ENABLED', $user, $user, ['enabled' => false], ['enabled' => false, 'pending_confirmation' => true]);
        $securityEvents->record('SECURITY_2FA_ENABLED', $request, $user);

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

    public function disable(Request $request, DisableTwoFactorAuthentication $disable, AuditService $auditService, SecurityEventLogger $securityEvents): RedirectResponse
    {
        $this->ensurePasswordConfirmed($request);

        $user = $request->user();
        $disable($user);

        $auditService->record('2FA_DISABLED', $user->fresh(), $user, ['enabled' => true], ['enabled' => false]);
        $securityEvents->record('SECURITY_2FA_DISABLED', $request, $user);

        return redirect()
            ->route('account.security')
            ->with('status', 'La autenticación en dos pasos fue desactivada. Como administrador, deberás configurarla nuevamente para volver a operar.');
    }

    public function regenerateRecoveryCodes(Request $request, GenerateNewRecoveryCodes $generate, AuditService $auditService, SecurityEventLogger $securityEvents): RedirectResponse
    {
        $this->ensurePasswordConfirmed($request);

        $user = $request->user();
        $generate($user);

        $auditService->record('RECOVERY_CODES_REGENERATED', $user->fresh(), $user);
        $securityEvents->record('SECURITY_RECOVERY_CODES_REGENERATED', $request, $user);

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
