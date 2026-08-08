@props([
    'user',
])

@php
    $initial = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($user->name, 0, 1));
    $role = $user->role === 'admin' ? 'Administrador' : \Illuminate\Support\Str::headline((string) $user->role);
@endphp

<div class="app-topbar">
    <div class="app-topbar-inner">
        <button class="app-menu-toggle d-none d-md-inline-flex" type="button" id="sidebarCollapseToggle" aria-label="Colapsar menú">
            <i class="bi bi-list"></i>
        </button>

        <button class="app-menu-toggle d-inline-flex d-md-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar" aria-label="Abrir menú">
            <i class="bi bi-list"></i>
        </button>

        <div class="app-topbar-actions ms-auto">
            <div class="dropdown">
                <button class="app-user-trigger dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="app-user-avatar">{{ $initial }}</span>
                    <span class="app-user-meta d-none d-sm-inline-block">
                        <span class="app-user-name d-block">{{ $user->name }}</span>
                        <span class="app-user-role">{{ $role }}</span>
                    </span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="dropdown-header">{{ $user->email }}</li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="dropdown-item" type="submit">
                                <i class="bi bi-box-arrow-right me-2"></i>Salir
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
