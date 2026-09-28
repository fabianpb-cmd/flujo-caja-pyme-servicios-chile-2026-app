@extends('layouts.app')

@section('content')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Crear Proyecto desde OC</h1>
        <p class="text-muted mb-0">La extracción es una ayuda de carga: revise y complete los datos antes de crear el proyecto.</p>
    </div>
    <a class="btn btn-outline-secondary" href="{{ route('operational.index', 'projects') }}">Volver a Proyectos</a>
</div>

@error('oc_import')
    <div class="alert alert-danger">{{ $message }}</div>
@enderror

@if (! $state)
    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5">Analizar orden de compra PDF</h2>
            <p class="text-muted">El archivo se guarda de forma privada y solo se asociará al proyecto después de la confirmación final.</p>
            <form method="POST" action="{{ route('projects.from-purchase-order.analyze') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="purchase_order">OC en PDF</label>
                    <input class="form-control @error('purchase_order') is-invalid @enderror" id="purchase_order" name="purchase_order" type="file" accept="application/pdf,.pdf" required>
                    <div class="form-text">Solo PDF, máximo 10 MB.</div>
                    @error('purchase_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button class="btn btn-primary" type="submit">Analizar OC</button>
            </form>
        </div>
    </div>
@else
    @php($extracted = $state['extracted'])
    <div class="alert alert-info">Revise los campos detectados. Los catálogos sin coincidencia exacta deben seleccionarse manualmente.</div>
    @if (filled($extracted['warnings'] ?? null))
        <div class="alert alert-warning"><strong>Advertencias de extracción</strong><ul class="mb-0">@foreach($extracted['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></div>
    @endif
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3 small">
                <div class="col-md-4"><span class="text-muted">Archivo</span><div>{{ $state['original_filename'] }}</div></div>
                <div class="col-md-4"><span class="text-muted">N° OC detectado</span><div>{{ $extracted['purchase_order_number'] ?: 'No detectado' }}</div></div>
                <div class="col-md-4"><span class="text-muted">Comprador detectado</span><div>{{ $extracted['buyer_name'] ?: 'No detectado' }}</div></div>
                <div class="col-md-4"><span class="text-muted">Cliente</span><div>{{ $matches['client'] ? $matches['client']->legal_name.' ('.$matches['client_reason'].')' : 'Sin coincidencia exacta: seleccione manualmente.' }}</div></div>
                <div class="col-md-4"><span class="text-muted">Moneda</span><div>{{ $matches['currency']?->code ?? 'Sin coincidencia exacta: seleccione manualmente.' }}</div></div>
                <div class="col-md-4"><span class="text-muted">Condición de pago</span><div>{{ $matches['payment_term']?->name ?? 'Sin coincidencia exacta: seleccione manualmente.' }}</div></div>
            </div>
        </div>
    </div>
    <form method="POST" action="{{ route('operational.store', 'projects') }}" class="card shadow-sm">
        @csrf
        <input type="hidden" name="oc_import_token" value="{{ $token }}">
        <div class="card-body">
            <h2 class="h5 mb-3">Revisar proyecto</h2>
            <div class="row g-3">
                @foreach ($config['fields'] as $field => $definition)
                    @continue(in_array($field, ['vat_rate', 'sale_total'], true))
                    @php($value = old($field, $item->{$field} ?? null))
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="{{ $field }}">{{ $definition['label'] }}</label>
                        @if (($definition['type'] ?? 'text') === 'relation')
                            <select class="form-select @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}">
                                <option value="">Seleccione...</option>
                                @foreach (($options[$field] ?? []) as $option)
                                    <option value="{{ $option['id'] }}" @selected((string) $value === (string) $option['id'])>{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        @elseif (($definition['type'] ?? 'text') === 'date')
                            <input class="form-control @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ $value }}">
                        @elseif (($definition['type'] ?? 'text') === 'money' || ($definition['type'] ?? 'text') === 'decimal')
                            <input class="form-control @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}" type="number" step="any" value="{{ $value }}">
                        @else
                            <input class="form-control @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}" type="text" value="{{ $value }}">
                        @endif
                        @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>
            @include('operational.partials.project-billing-plan')
            <div class="alert alert-light border mt-4 mb-0">La creación usa las mismas validaciones y reglas de negocio que el formulario normal de Proyectos.</div>
        </div>
        <div class="card-footer d-flex gap-2 justify-content-end" data-assistant-avoid-overlap>
            <a class="btn btn-outline-secondary" href="{{ route('projects.from-purchase-order') }}">Cancelar</a>
            <button class="btn btn-primary" type="submit">Crear proyecto y adjuntar OC</button>
        </div>
    </form>
@endif
@endsection
