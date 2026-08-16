@extends('layouts.app')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Usuarios</h1>
        <div class="page-subtitle">Administración de accesos por empresa.</div>
    </div>
    <div class="page-toolbar">
        <a class="btn btn-primary" href="{{ route('admin.users.create') }}">Nuevo usuario</a>
    </div>
</div>

<div class="table-responsive app-panel">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
        <tr>
            <th class="table-sticky-column table-actions-head">Acciones</th>
            <th>Nombre</th>
            <th>Correo</th>
            <th>Rol</th>
            <th>Estado</th>
            <th>2FA</th>
            @if ($hasLastAccessColumn)
                <th>Último acceso</th>
            @endif
        </tr>
        </thead>
        <tbody>
        @forelse ($users as $userItem)
            @php
                $twoFactorStatus = $userItem->hasEnabledTwoFactorAuthentication()
                    ? 'Activa'
                    : ($userItem->two_factor_secret ? 'Pendiente' : 'No configurada');
            @endphp
            <tr>
                <td class="table-sticky-column">
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.users.edit', $userItem) }}">Editar</a>
                        <form method="POST" action="{{ route('admin.users.toggle-active', $userItem) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-sm {{ $userItem->active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                {{ $userItem->active ? 'Desactivar' : 'Activar' }}
                            </button>
                        </form>
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.users.password.edit', $userItem) }}">Restablecer contraseña</a>
                        @if ($userItem->two_factor_secret)
                            <form method="POST" action="{{ route('admin.users.two-factor.reset', $userItem) }}" onsubmit="return confirm('Se eliminará la configuración 2FA del usuario.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">Resetear 2FA</button>
                            </form>
                        @endif
                    </div>
                </td>
                <td>{{ $userItem->name }}</td>
                <td>{{ $userItem->email }}</td>
                <td>{{ $userItem->role === 'admin' ? 'Administrador' : 'Usuario' }}</td>
                <td>
                    <span class="badge text-bg-{{ $userItem->active ? 'success' : 'secondary' }}">
                        {{ $userItem->active ? 'Activo' : 'Inactivo' }}
                    </span>
                </td>
                <td>
                    <span class="badge text-bg-{{ $twoFactorStatus === 'Activa' ? 'success' : ($twoFactorStatus === 'Pendiente' ? 'warning text-dark' : 'secondary') }}">
                        {{ $twoFactorStatus }}
                    </span>
                </td>
                @if ($hasLastAccessColumn)
                    <td>{{ \App\Support\UiFormatter::date(data_get($userItem, $lastAccessColumn)) }}</td>
                @endif
            </tr>
        @empty
            <tr>
                <td colspan="{{ $hasLastAccessColumn ? 7 : 6 }}" class="text-center text-muted py-4">No hay usuarios registrados para esta empresa.</td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
