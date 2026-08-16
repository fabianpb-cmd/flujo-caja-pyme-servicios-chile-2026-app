@extends('layouts.app')

@section('content')
    <x-page-help
        id="account-security-help"
        title="¿Cómo funciona esta pantalla?"
        :bullets="[
            'La verificación en dos pasos agrega un código temporal al ingreso con contraseña.',
            'Para administradores, esta configuración es obligatoria antes de operar normalmente.',
            'El QR y la clave manual solo se muestran durante la configuración.',
            'Los códigos de recuperación deben guardarse fuera de la aplicación.',
        ]"
    />

    @php
        $statusLabel = $twoFactorEnabled ? 'Activa' : ($twoFactorPending ? 'Pendiente de confirmación' : 'No configurada');
        $statusClass = $twoFactorEnabled ? 'success' : ($twoFactorPending ? 'warning text-dark' : 'secondary');
    @endphp

    <section class="app-panel p-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
            <div>
                <div class="text-uppercase text-primary small fw-semibold">Mi cuenta</div>
                <h1 class="h3 mb-1">Seguridad</h1>
                <p class="text-muted mb-0">Administra la autenticación en dos pasos de tu cuenta.</p>
            </div>
            <div class="align-self-start">
                <span class="badge text-bg-{{ $statusClass }}">{{ $statusLabel }}</span>
            </div>
        </div>

        @if ($isAdmin && ! $twoFactorEnabled)
            <div class="alert alert-warning">
                Como administrador, debes confirmar la autenticación en dos pasos antes de acceder a los módulos operacionales.
            </div>
        @endif

        <div class="row g-4">
            <div class="col-12 col-xl-7">
                <div class="border rounded-3 p-4 h-100">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <h2 class="h5 mb-0">Autenticación en dos pasos</h2>
                        <x-field-help text="La verificación en dos pasos agrega un código temporal al ingreso con contraseña." />
                    </div>
                    <p class="text-muted small mb-4">Estado actual: <strong>{{ $statusLabel }}</strong></p>

                    @if (! $passwordRecentlyConfirmed)
                        <div class="alert alert-info mb-3">
                            Confirma tu contraseña antes de habilitar, deshabilitar o regenerar códigos de recuperación.
                        </div>

                        <a href="{{ route('password.confirm') }}" class="btn btn-outline-primary">
                            Confirmar contraseña
                        </a>
                    @elseif (! $twoFactorPending && ! $twoFactorEnabled)
                        <form method="POST" action="{{ route('account.security.enable-2fa') }}">
                            @csrf
                            <button type="submit" class="btn btn-primary">
                                Activar autenticación en dos pasos
                            </button>
                        </form>
                    @elseif ($twoFactorPending)
                        <div class="mb-4">
                            <div class="small text-muted mb-2">Escanea el QR con tu aplicación autenticadora y luego confirma el código temporal de 6 dígitos.</div>
                            <div class="border rounded-3 p-3 bg-light-subtle d-inline-block">{!! $qrSvg !!}</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label field-label-with-help">
                                Configuración manual
                                <x-field-help text="Usa esta clave solo si tu aplicación no puede escanear el código QR." />
                            </label>
                            <div class="form-control bg-light">{{ $manualSecret }}</div>
                        </div>

                        <form method="POST" action="{{ route('account.security.confirm-2fa') }}" class="row g-3">
                            @csrf
                            <div class="col-12 col-md-7">
                                <label for="two_factor_code" class="form-label field-label-with-help">
                                    Código de verificación
                                    <x-field-help text="Ingresa el código temporal de 6 dígitos generado por tu aplicación autenticadora." />
                                </label>
                                <input id="two_factor_code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">Confirmar autenticación en dos pasos</button>
                            </div>
                        </form>
                    @else
                        <div class="d-flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('account.security.regenerate-recovery-codes') }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-primary">Regenerar códigos de recuperación</button>
                            </form>

                            <form method="POST" action="{{ route('account.security.disable-2fa') }}" onsubmit="return confirm('Se desactivará la autenticación en dos pasos. Como administrador, deberás configurarla nuevamente para continuar operando.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger">Desactivar autenticación en dos pasos</button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>

            <div class="col-12 col-xl-5">
                <div class="border rounded-3 p-4 h-100">
                    <h2 class="h5 mb-3">Códigos de recuperación</h2>
                    <p class="text-muted small">Guarda estos códigos en un lugar seguro. Puedes utilizarlos si pierdes acceso a tu aplicación autenticadora.</p>

                    @if ($twoFactorEnabled && ! empty($recoveryCodes))
                        <div class="bg-light rounded-3 p-3">
                            <ul class="list-unstyled mb-0 d-grid gap-2 small">
                                @foreach ($recoveryCodes as $code)
                                    <li><code>{{ $code }}</code></li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        <div class="text-muted small">Los códigos de recuperación estarán disponibles una vez que la autenticación en dos pasos quede confirmada.</div>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endsection
