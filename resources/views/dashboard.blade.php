@extends('layouts.app')

@section('content')
<div class="hero-card rounded-4 p-4 p-md-5 mb-4 shadow-sm">
    <div class="row align-items-center g-4">
        <div class="col-lg-8">
            <div class="text-uppercase text-info small fw-semibold mb-2">Base local activa</div>
            <h1 class="display-6 fw-bold mb-3">Flujo de Caja Pyme de Servicios</h1>
            <p class="lead mb-0 text-white-75">
                Laravel + MySQL + Blade + Bootstrap, preparado para migrar desde el Excel V3 sin perder trazabilidad.
            </p>
        </div>
        <div class="col-lg-4 text-lg-end">
            <span class="badge text-bg-light text-dark px-3 py-2">Autenticacion local lista</span>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Entidades</div>
                <div class="h3 mb-0">20+</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Fuente funcional</div>
                <div class="h3 mb-0">Excel V3</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Caja real</div>
                <div class="h3 mb-0">Movimientos</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Estado</div>
                <div class="h3 mb-0 text-success">Listo</div>
            </div>
        </div>
    </div>
</div>

<div class="mt-4 bg-white border rounded shadow-sm p-4">
    <h2 class="h5 mb-3">Operacion</h2>
    <div class="d-flex flex-wrap gap-2">
        @foreach (config('operational') as $resource => $definition)
            <a class="btn btn-outline-primary btn-sm" href="{{ route('operational.index', $resource) }}">{{ $definition['title'] }}</a>
        @endforeach
    </div>
</div>
@endsection
