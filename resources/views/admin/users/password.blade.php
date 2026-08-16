@extends('layouts.app')

@section('content')
<section class="app-panel p-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <div class="text-uppercase text-primary small fw-semibold">Administración</div>
            <h1 class="h3 mb-1">Restablecer contraseña</h1>
            <p class="text-muted mb-0">{{ $userItem->name }} · {{ $userItem->email }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.users.password.update', $userItem) }}" class="row g-3">
        @csrf
        @method('PUT')

        <div class="col-12 col-lg-6">
            <label for="password" class="form-label">Nueva contraseña</label>
            <input id="password" name="password" type="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password" required>
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-12 col-lg-6">
            <label for="password_confirmation" class="form-label">Confirmar contraseña</label>
            <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" autocomplete="new-password" required>
        </div>

        <div class="col-12 d-flex flex-wrap gap-2 pt-2">
            <button type="submit" class="btn btn-primary">Restablecer contraseña</button>
            <a class="btn btn-outline-secondary" href="{{ route('admin.users.index') }}">Cancelar</a>
        </div>
    </form>
</section>
@endsection
