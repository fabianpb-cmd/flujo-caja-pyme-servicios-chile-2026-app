@extends('layouts.app')

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

                <form method="POST" action="{{ route('login.attempt') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="email" class="form-label">Correo</label>
                        <input id="email" type="email" name="email" class="form-control" value="{{ old('email') }}" required autofocus>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Clave</label>
                        <input id="password" type="password" name="password" class="form-control" required>
                    </div>

                    <div class="form-check mb-3">
                        <input id="remember" type="checkbox" name="remember" class="form-check-input">
                        <label for="remember" class="form-check-label">Recordarme</label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Entrar</button>
                </form>

                <hr class="my-4">
                <div class="small text-muted">
                    Usuario semilla: <code>admin@flujo.local</code> / <code>password</code>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
