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
                    <div class="text-uppercase text-primary small fw-semibold">Acceso local</div>
                    <h1 class="h3 mb-1">Flujo de Caja Pyme</h1>
                    <p class="text-muted mb-0">Ingreso local para continuar con la primera fase del aplicativo.</p>
                </div>

                @if (! empty($loginError))
                    <div class="alert alert-warning">{{ $loginError }}</div>
                @elseif (session('session_expired'))
                    <div class="alert alert-warning">{{ session('session_expired') }}</div>
                @elseif ($authErrors->any())
                    <div class="alert alert-danger">{{ $authErrors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('login.attempt') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="email" class="form-label">Correo</label>
                        <input id="email" type="email" name="email" class="form-control" value="{{ old('email', $emailPrefill ?? '') }}" required autofocus autocomplete="email">
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Clave</label>
                        <input id="password" type="password" name="password" class="form-control" required autocomplete="current-password">
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Entrar</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
