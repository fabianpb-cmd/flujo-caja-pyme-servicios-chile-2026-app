@extends('layouts.app')

@section('content')
@php($projects = $guidedBillingProjects ?? [])
<div class="page-header">
    <div>
        <h1 class="page-title">Nueva factura / ingreso</h1>
        <div class="page-subtitle">El origen contractual determina cómo se calcula el borrador.</div>
    </div>
    <a class="btn btn-outline-secondary" href="{{ route('operational.index', 'sales-documents') }}">Volver</a>
</div>

<form method="POST"
      action="{{ route('operational.store', 'sales-documents') }}"
      class="app-panel p-4"
      data-guided-sales-form
      data-manual-action="{{ route('operational.store', 'sales-documents') }}"
      data-hourly-action="{{ route('sales-prefacturation.generate-draft') }}"
      data-milestone-action-template="{{ route('projects.milestones.issue', ['project' => '__PROJECT__', 'milestone' => '__MILESTONE__']) }}"
      data-milestone-preview-template="{{ route('projects.milestones.preview', ['project' => '__PROJECT__', 'milestone' => '__MILESTONE__']) }}">
    @csrf

    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label class="form-label" for="client_id">Cliente</label>
            <select class="form-select @error('client_id') is-invalid @enderror" id="client_id" name="client_id" required>
                <option value="">Seleccione</option>
                @foreach (($options['client_id'] ?? []) as $id => $option)
                    <option value="{{ $id }}" @selected((string) old('client_id') === (string) $id)>{{ is_array($option) ? $option['label'] : $option }}</option>
                @endforeach
            </select>
            @error('client_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="project_id">Proyecto</label>
            <select class="form-select @error('project_id') is-invalid @enderror" id="project_id" name="project_id">
                <option value="">Sin proyecto / facturación manual</option>
                @foreach (($options['project_id'] ?? []) as $id => $option)
                    <option value="{{ $id }}" @selected((string) old('project_id') === (string) $id)>{{ is_array($option) ? $option['label'] : $option }}</option>
                @endforeach
            </select>
            @error('project_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6" data-guided-document-type>
            <label class="form-label" for="document_type_id">Tipo de documento</label>
            <select class="form-select @error('document_type_id') is-invalid @enderror" id="document_type_id" name="document_type_id" required>
                <option value="">Seleccione</option>
                @foreach (($options['document_type_id'] ?? []) as $id => $option)
                    <option value="{{ $id }}" @selected((string) old('document_type_id') === (string) $id)>{{ is_array($option) ? $option['label'] : $option }}</option>
                @endforeach
            </select>
            <div class="small text-muted mt-1" data-guided-document-type-note></div>
            @error('document_type_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="issue_date">Fecha de emisión</label>
            <input class="form-control @error('issue_date') is-invalid @enderror" id="issue_date" name="issue_date" type="date" value="{{ old('issue_date', now()->toDateString()) }}" required>
            <div class="small text-muted mt-1">Es la fecha real del borrador; puede diferir de la fecha prevista contractual.</div>
            @error('issue_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
    </div>

    <input type="hidden" name="is_voided" value="0">
    <input type="hidden" name="taxable" value="0">

    <div class="app-panel bg-light border-0 p-3 mt-4" data-guided-origin-panel>
        <div class="small text-muted">ORIGEN DE FACTURACIÓN</div>
        <div class="fw-semibold" data-guided-origin>Manual</div>
        <div class="small text-muted" data-guided-origin-message>El monto será ingresado manualmente porque este documento no posee una fuente contractual automática.</div>
    </div>

    <div class="mt-4 d-none" data-guided-milestone-panel>
        <div class="section-title">FACTURACIÓN POR HITO</div>
        <div class="small text-muted mb-3">Este proyecto tiene un plan de facturación por hitos. Seleccione el hito que desea facturar.</div>
        <label class="form-label" for="milestone_id">Hito a facturar</label>
        <select class="form-select" id="milestone_id" name="milestone_id" data-guided-milestone>
            <option value="">Seleccione un hito pendiente</option>
        </select>
        <div class="alert alert-warning py-2 mt-3 d-none" data-guided-milestone-empty>No existen hitos pendientes de facturación.</div>
    </div>

    <div class="mt-4 d-none" data-guided-hourly-panel>
        <div class="section-title">FACTURACIÓN POR HORAS APROBADAS</div>
        <div class="small text-muted mb-3">Este proyecto se factura según HH aprobadas aún no facturadas.</div>
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label" for="period">Período</label>
                <input class="form-control" id="period" name="period" value="{{ old('period', now()->format('m/Y')) }}" placeholder="mm/aaaa" inputmode="numeric" data-guided-hourly-field>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="adjustment_amount">Ajuste comercial</label>
                <input class="form-control" id="adjustment_amount" name="adjustment_amount" value="{{ old('adjustment_amount') }}" inputmode="decimal" data-guided-hourly-field>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="adjustment_reason">Motivo ajuste</label>
                <input class="form-control" id="adjustment_reason" name="adjustment_reason" value="{{ old('adjustment_reason') }}" data-guided-hourly-field>
            </div>
        </div>
    </div>

    <div class="mt-4 d-none" data-guided-summary>
        <div class="section-title">Resumen de facturación</div>
        <div class="row g-3">
            <div class="col-12 col-md-4"><div class="app-panel bg-light border-0 p-3"><div class="small text-muted">Monto contractual</div><div class="fw-semibold" data-summary-contractual>—</div></div></div>
            <div class="col-12 col-md-4"><div class="app-panel bg-light border-0 p-3"><div class="small text-muted">Neto CLP</div><div class="fw-semibold" data-summary-net>—</div></div></div>
            <div class="col-12 col-md-4"><div class="app-panel bg-light border-0 p-3"><div class="small text-muted">IVA</div><div class="fw-semibold" data-summary-vat>—</div></div></div>
            <div class="col-12 col-md-4"><div class="app-panel bg-light border-0 p-3"><div class="small text-muted">Total</div><div class="fw-semibold" data-summary-gross>—</div></div></div>
            <div class="col-12 col-md-4"><div class="app-panel bg-light border-0 p-3"><div class="small text-muted">Vencimiento</div><div class="fw-semibold" data-summary-due>—</div></div></div>
            <div class="col-12 col-md-4"><div class="app-panel bg-light border-0 p-3"><div class="small text-muted">Cobro proyectado</div><div class="fw-semibold" data-summary-projected>—</div></div></div>
        </div>
        <div class="alert alert-danger py-2 mt-3 d-none" data-guided-preview-error></div>
    </div>

    <div class="mt-4" data-guided-manual-panel>
        <div class="section-title">FACTURACIÓN MANUAL</div>
        <div class="small text-muted mb-3">El monto será ingresado manualmente porque este documento no posee una fuente contractual automática.</div>
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label" for="document_number">N° documento</label>
                <input class="form-control" id="document_number" name="document_number" value="{{ old('document_number') }}">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="due_date">Fecha de vencimiento</label>
                <input class="form-control" id="due_date" name="due_date" type="date" value="{{ old('due_date') }}">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="projected_collection_date">Cobro proyectado</label>
                <input class="form-control" id="projected_collection_date" name="projected_collection_date" type="date" value="{{ old('projected_collection_date') }}">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label" for="net_amount">Neto</label>
                <input class="form-control" id="net_amount" name="net_amount" value="{{ old('net_amount') }}" inputmode="decimal" required>
                @error('net_amount')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-6 d-flex align-items-end">
                <div class="form-check mb-2">
                    <input class="form-check-input" id="taxable" name="taxable" type="checkbox" value="1" @checked(old('taxable', true))>
                    <label class="form-check-label" for="taxable">Afecta IVA</label>
                </div>
            </div>
        </div>
    </div>

    @error('billing_source')<div class="alert alert-danger mt-3 mb-0">{{ $message }}</div>@enderror
    @error('milestone')<div class="alert alert-danger mt-3 mb-0">{{ $message }}</div>@enderror
    @error('prefacturacion')<div class="alert alert-danger mt-3 mb-0">{{ $message }}</div>@enderror

    <div class="d-flex justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="{{ route('operational.index', 'sales-documents') }}">Cancelar</a>
        <button type="submit" class="btn btn-primary" data-guided-submit>Guardar</button>
    </div>
</form>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(() => {
    const form = document.querySelector('[data-guided-sales-form]');
    if (!form) return;
    const projects = @json($projects);
    const project = form.querySelector('#project_id');
    const client = form.querySelector('#client_id');
    const documentType = form.querySelector('#document_type_id');
    const issueDate = form.querySelector('#issue_date');
    const taxable = form.querySelector('#taxable');
    const milestonePanel = form.querySelector('[data-guided-milestone-panel]');
    const hourlyPanel = form.querySelector('[data-guided-hourly-panel]');
    const manualPanel = form.querySelector('[data-guided-manual-panel]');
    const milestone = form.querySelector('[data-guided-milestone]');
    const emptyMilestones = form.querySelector('[data-guided-milestone-empty]');
    const summary = form.querySelector('[data-guided-summary]');
    const previewError = form.querySelector('[data-guided-preview-error]');
    const submit = form.querySelector('[data-guided-submit]');
    const currencyPrefix = code => ({ CLP: '$', USD: 'US$', EUR: '€', UF: 'UF' }[String(code || 'CLP').toUpperCase()] || code);
    const amount = (value, currency = 'CLP') => `${currencyPrefix(currency)} ${new Intl.NumberFormat('es-CL', { maximumFractionDigits: currency === 'CLP' ? 0 : 2 }).format(Number(value || 0))}`;
    const route = (template, projectId, milestoneId) => template.replace('__PROJECT__', projectId).replace('__MILESTONE__', milestoneId);
    const setText = (selector, value) => { const node = form.querySelector(selector); if (node) node.textContent = value || '—'; };
    const activeProject = () => projects[project.value] || null;
    const resetSummary = () => {
        summary.classList.add('d-none');
        previewError.classList.add('d-none');
        previewError.textContent = '';
    };
    const setMode = () => {
        const selected = activeProject();
        const strategy = selected?.strategy || 'MANUAL';
        const isMilestone = strategy === 'CLOSED_PROJECT';
        const isHourly = ['HOURLY', 'HOURS_BANK', 'MONTHLY_RECURRING'].includes(strategy);
        milestonePanel.classList.toggle('d-none', !isMilestone);
        hourlyPanel.classList.toggle('d-none', !isHourly);
        manualPanel.classList.toggle('d-none', isMilestone || isHourly);
        documentType.disabled = isMilestone || isHourly;
        documentType.required = !isMilestone && !isHourly;
        form.querySelector('#net_amount').disabled = isMilestone || isHourly;
        form.querySelector('#net_amount').required = !isMilestone && !isHourly;
        form.querySelectorAll('[data-guided-hourly-field]').forEach(input => input.disabled = !isHourly);
        if (selected?.client_id) client.value = String(selected.client_id);
        if (isMilestone) {
            form.action = form.dataset.manualAction;
            milestone.innerHTML = '<option value="">Seleccione un hito pendiente</option>';
            selected.milestones.forEach(row => {
                const option = document.createElement('option');
                option.value = row.id;
                option.textContent = `Hito ${row.sequence} - ${row.name} · ${row.percentage}% · ${amount(row.contractual_amount, row.currency_code)}${row.planned_invoice_date ? ` · Planificado: ${row.planned_invoice_date}` : ''}`;
                milestone.append(option);
            });
            emptyMilestones.classList.toggle('d-none', selected.milestones.length > 0);
            setText('[data-guided-origin]', 'Hito contractual');
            setText('[data-guided-origin-message]', selected.milestones.length ? 'Este proyecto tiene un plan de facturación por hitos. Seleccione el hito que desea facturar.' : 'Este proyecto está configurado como Proyecto cerrado, pero no tiene un plan de hitos disponible para facturación.');
            submit.textContent = 'Generar borrador de factura';
        } else if (isHourly) {
            form.action = form.dataset.hourlyAction;
            const description = strategy === 'HOURS_BANK'
                ? 'Este proyecto consume una bolsa total de HH aprobadas aún no facturadas.'
                : strategy === 'MONTHLY_RECURRING'
                    ? 'Este proyecto consume una bolsa mensual de HH aprobadas aún no facturadas.'
                    : 'Este proyecto se factura según HH aprobadas aún no facturadas.';
            setText('[data-guided-origin]', 'Horas aprobadas');
            setText('[data-guided-origin-message]', description);
            submit.textContent = 'Generar borrador de factura';
        } else {
            form.action = form.dataset.manualAction;
            setText('[data-guided-origin]', 'Manual');
            setText('[data-guided-origin-message]', selected ? 'El tipo de contrato no tiene una estrategia de facturación configurada; el monto se ingresará manualmente.' : 'El monto será ingresado manualmente porque este documento no posee una fuente contractual automática.');
            submit.textContent = 'Guardar';
        }
        resetSummary();
    };
    const preview = async () => {
        const selected = activeProject();
        if (!selected || selected.strategy !== 'CLOSED_PROJECT' || !milestone.value || !issueDate.value) return;
        const response = await fetch(route(form.dataset.milestonePreviewTemplate, project.value, milestone.value), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ _token: '{{ csrf_token() }}', issue_date: issueDate.value, taxable: taxable.checked ? '1' : '0' }).toString(),
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message || 'No fue posible calcular el hito.');
        setText('[data-summary-contractual]', amount(payload.contractual_amount, payload.contractual_currency));
        setText('[data-summary-net]', amount(payload.net_amount));
        setText('[data-summary-vat]', amount(payload.vat_amount));
        setText('[data-summary-gross]', amount(payload.gross_amount));
        setText('[data-summary-due]', payload.due_date || 'Sin condición de pago');
        setText('[data-summary-projected]', payload.projected_collection_date || 'Sin condición de pago');
        summary.classList.remove('d-none');
        form.action = route(form.dataset.milestoneActionTemplate, project.value, milestone.value);
    };
    const safePreview = () => preview().catch(error => { previewError.textContent = error.message; previewError.classList.remove('d-none'); summary.classList.remove('d-none'); });
    project.addEventListener('change', setMode);
    milestone.addEventListener('change', safePreview);
    issueDate.addEventListener('change', safePreview);
    taxable.addEventListener('change', safePreview);
    form.addEventListener('submit', event => {
        const selected = activeProject();
        if (selected?.strategy === 'CLOSED_PROJECT') {
            if (!milestone.value) { event.preventDefault(); emptyMilestones.classList.remove('d-none'); return; }
            form.action = route(form.dataset.milestoneActionTemplate, project.value, milestone.value);
        }
        if (['HOURLY', 'HOURS_BANK', 'MONTHLY_RECURRING'].includes(selected?.strategy)) form.action = form.dataset.hourlyAction;
    });
    setMode();
})();
</script>
@endpush
