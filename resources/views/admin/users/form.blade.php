@extends('layouts.app')

@section('content')
@php
    $isEdit = $mode === 'edit';
@endphp

<section class="app-panel p-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <div class="text-uppercase text-primary small fw-semibold">Administración</div>
            <h1 class="h3 mb-1">{{ $isEdit ? 'Editar usuario' : 'Nuevo usuario' }}</h1>
            <p class="text-muted mb-0">Gestiona acceso, rol y estado del usuario.</p>
        </div>
    </div>

    <form method="POST" action="{{ $isEdit ? route('admin.users.update', $userItem) : route('admin.users.store') }}" class="row g-3">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="col-12 col-lg-6">
            <label for="name" class="form-label">Nombre</label>
            <input id="name" name="name" type="text" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $userItem->name) }}" required>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-12 col-lg-6">
            <label for="email" class="form-label">Correo</label>
            <input id="email" name="email" type="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $userItem->email) }}" required>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-12 col-md-6 col-xl-4">
            <label for="role" class="form-label">Rol</label>
            <select id="role" name="role" class="form-select @error('role') is-invalid @enderror" required>
                @foreach ($roleOptions as $value => $label)
                    <option value="{{ $value }}" @selected(old('role', $userItem->role) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-12 col-md-6 col-xl-4">
            <label for="active" class="form-label">Estado</label>
            <select id="active" name="active" class="form-select @error('active') is-invalid @enderror" required>
                <option value="1" @selected((string) old('active', $userItem->active ? '1' : '0') === '1')>Activo</option>
                <option value="0" @selected((string) old('active', $userItem->active ? '1' : '0') === '0')>Inactivo</option>
            </select>
            @error('active')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        @if (! $isEdit)
            <div class="col-12 col-lg-6">
                <label for="password" class="form-label">Contraseña inicial</label>
                <input id="password" name="password" type="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password" required>
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-12 col-lg-6">
                <label for="password_confirmation" class="form-label">Confirmar contraseña</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" autocomplete="new-password" required>
            </div>
        @endif

        <div class="col-12 d-flex flex-wrap gap-2 pt-2">
            <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Guardar cambios' : 'Crear usuario' }}</button>
            <a class="btn btn-outline-secondary" href="{{ route('admin.users.index') }}">Cancelar</a>
        </div>
    </form>
</section>
@endsection
