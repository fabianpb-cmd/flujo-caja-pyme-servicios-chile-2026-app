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
            <form method="POST" action="{{ route('projects.from-purchase-order.analyze') }}" enctype="multipart/form-data" data-oc-analysis-form aria-busy="false">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="purchase_order">OC en PDF</label>
                    <input class="form-control @error('purchase_order') is-invalid @enderror" id="purchase_order" name="purchase_order" type="file" accept="application/pdf,.pdf" required data-oc-analysis-file>
                    <div class="form-text">Solo PDF, máximo 10 MB.</div>
                    @error('purchase_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button class="btn btn-primary" type="submit" data-oc-analysis-submit><span data-oc-analysis-submit-label>Analizar OC</span></button>
                <div class="alert alert-light border mt-3 mb-0 d-none" data-oc-analysis-progress aria-live="polite">
                    <div class="d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><strong>Procesando orden de compra</strong></div>
                    <div class="small text-muted mt-1">Esto puede tardar algunos segundos. No cierre esta página.</div>
                    <div class="small text-muted mt-1" data-oc-analysis-file-name></div>
                    <div class="progress mt-2" role="progressbar" aria-label="Procesando orden de compra"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%"></div></div>
                    <div class="small text-muted mt-2" data-oc-analysis-stage>Validando el archivo PDF...</div>
                </div>
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
    <form method="POST" action="{{ route('operational.store', 'projects') }}" class="card shadow-sm" data-oc-project-form aria-busy="false">
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
            <button class="btn btn-primary" type="submit" data-oc-project-submit><span data-oc-project-submit-label>Crear proyecto y adjuntar OC</span></button>
            <span class="small text-muted d-none" data-oc-project-status>Validando y guardando el proyecto y su documento origen...</span>
        </div>
    </form>
@endif
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(() => {
    const analysisForm = document.querySelector('[data-oc-analysis-form]');
    if (analysisForm) {
        const submit = analysisForm.querySelector('[data-oc-analysis-submit]');
        const label = analysisForm.querySelector('[data-oc-analysis-submit-label]');
        const progress = analysisForm.querySelector('[data-oc-analysis-progress]');
        const file = analysisForm.querySelector('[data-oc-analysis-file]');
        const fileName = analysisForm.querySelector('[data-oc-analysis-file-name]');
        const stage = analysisForm.querySelector('[data-oc-analysis-stage]');
        const stages = ['Validando el archivo PDF...', 'Analizando el contenido de la OC...', 'Extrayendo datos comerciales e hitos...', 'Preparando la información del proyecto...', 'Finalizando análisis...'];
        let timer;
        analysisForm.addEventListener('submit', () => {
            if (!analysisForm.checkValidity() || analysisForm.dataset.started === '1') return;
            analysisForm.dataset.started = '1';
            analysisForm.setAttribute('aria-busy', 'true');
            submit.disabled = true;
            label.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Analizando OC...';
            progress.classList.remove('d-none');
            fileName.textContent = file?.files?.[0] ? `Archivo: ${file.files[0].name}` : '';
            const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
            if (!reduced) {
                let index = 0;
                timer = window.setInterval(() => { index = Math.min(index + 1, stages.length - 1); stage.textContent = stages[index]; }, 3000);
            } else {
                stage.textContent = stages[1];
            }
        });
        analysisForm.addEventListener('pagehide', () => window.clearInterval(timer));
    }

    const projectForm = document.querySelector('[data-oc-project-form]');
    if (projectForm) {
        projectForm.addEventListener('submit', () => {
            if (!projectForm.checkValidity() || projectForm.dataset.started === '1') return;
            projectForm.dataset.started = '1';
            projectForm.setAttribute('aria-busy', 'true');
            projectForm.querySelector('[data-oc-project-submit]').disabled = true;
            projectForm.querySelector('[data-oc-project-submit-label]').innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Creando proyecto...';
            projectForm.querySelector('[data-oc-project-status]').classList.remove('d-none');
        });
    }
})();
</script>
@endpush
