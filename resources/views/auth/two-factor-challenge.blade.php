@extends('layouts.app')

@php
    $authErrors = ($errors ?? null) instanceof \Illuminate\Support\ViewErrorBag
        ? $errors
        : new \Illuminate\Support\ViewErrorBag();
@endphp

@section('content')
<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4 p-md-5">
                <div class="mb-4">
                    <div class="text-uppercase text-primary small fw-semibold">Verificación en dos pasos</div>
                    <h1 class="h3 mb-1">Confirma tu acceso</h1>
                    <p class="text-muted mb-0">Ingresa el código temporal de tu aplicación autenticadora o un código de recuperación.</p>
                </div>

                @if ($authErrors->any())
                    <div class="alert alert-danger">{{ $authErrors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('two-factor.login.store') }}" class="mb-4">
                    @csrf
                    <div class="mb-3">
                        <label for="code" class="form-label field-label-with-help">
                            Código de autenticación
                            <x-field-help text="Ingresa el código temporal de 6 dígitos generado por tu aplicación autenticadora." />
                        </label>
                        <input id="code" type="text" name="code" class="form-control" inputmode="numeric" autocomplete="one-time-code" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Verificar</button>
                </form>

                <div class="border-top pt-4">
                    <div class="fw-semibold mb-2">Usar un código de recuperación</div>

                    <form method="POST" action="{{ route('two-factor.login.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="recovery_code" class="form-label">Código de recuperación</label>
                            <input id="recovery_code" type="text" name="recovery_code" class="form-control" autocomplete="one-time-code">
                        </div>

                        <button type="submit" class="btn btn-outline-secondary w-100">Ingresar con código de recuperación</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
