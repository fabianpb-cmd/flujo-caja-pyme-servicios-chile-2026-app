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
                    <div class="text-uppercase text-primary small fw-semibold">Confirmación de seguridad</div>
                    <h1 class="h3 mb-1">Confirma tu contraseña</h1>
                    <p class="text-muted mb-0">Antes de administrar la autenticación en dos pasos, vuelve a ingresar tu contraseña.</p>
                </div>

                @if ($authErrors->any())
                    <div class="alert alert-danger">{{ $authErrors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('password.confirm.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="password" class="form-label">Contraseña actual</label>
                        <input id="password" type="password" name="password" class="form-control" required autocomplete="current-password">
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Confirmar contraseña</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
