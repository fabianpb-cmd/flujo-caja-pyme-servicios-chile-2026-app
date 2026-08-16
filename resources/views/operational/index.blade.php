@extends('layouts.app')

@section('content')
@php
    $fields = $config['index_fields'] ?? $config['fields'];
    $isCatalog = (bool) ($config['catalog'] ?? false);
    $sortableFields = $sorts ?? [];
    $sortIndicator = fn (string $field): string => $sort === $field ? ($direction === 'asc' ? '▲' : '▼') : '↕';
    $codeField = array_key_exists('code', $fields) ? 'code' : null;
    $payrollPeriod = request('period', now()->format('m/Y'));
    $payrollSummary = session('payroll_batch_summary');
    $salesPreview = session('sales_prefacturation_preview');
@endphp
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $config['title'] }}</h1>
        <div class="page-subtitle">Núcleo operacional conectado a empresa y caja real.</div>
    </div>
    <div class="page-toolbar">
        <form method="GET" class="d-flex gap-2">
            @if (! empty($sort))
                <input type="hidden" name="sort" value="{{ $sort }}">
                <input type="hidden" name="direction" value="{{ $direction }}">
            @endif
            <input class="form-control" type="search" name="q" value="{{ $search ?? '' }}" placeholder="Buscar">
            <button class="btn btn-outline-secondary" type="submit">Filtrar</button>
        </form>
        <a class="btn btn-primary" href="{{ route('operational.create', $resource) }}">Nuevo</a>
    </div>
</div>

@if ($resource === 'payroll-records')
    <div class="app-panel p-3 mb-3">
        <div class="d-flex flex-wrap align-items-end gap-2">
            <form method="POST" action="{{ route('payroll.generate-period') }}" class="d-flex flex-wrap align-items-end gap-2">
                @csrf
                <div>
                    <label for="payroll-period" class="form-label small text-muted mb-1">Período</label>
                    <input id="payroll-period" name="period" class="form-control" value="{{ $payrollPeriod }}" placeholder="08/2026" inputmode="numeric">
                </div>
                <button class="btn btn-primary" type="submit">Generar período</button>
                <button class="btn btn-outline-primary" type="submit" formaction="{{ route('payroll.recalculate-drafts') }}">Recalcular borradores</button>
            </form>
            <a class="btn btn-outline-secondary" href="{{ route('operational.index', 'payroll-adjustments') }}">Novedades</a>
            <div class="small text-muted ms-lg-auto">Genera borradores; confirmación y pago siguen siendo pasos separados.</div>
        </div>
    </div>

    @if (is_array($payrollSummary))
        <div class="app-panel p-3 mb-3">
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <strong>Resumen {{ $payrollSummary['period'] ?? $payrollPeriod }}</strong>
                <span>Evaluadas: {{ $payrollSummary['evaluated'] ?? 0 }}</span>
                <span>Generadas: {{ $payrollSummary['generated'] ?? 0 }}</span>
                <span>Actualizadas: {{ $payrollSummary['updated'] ?? 0 }}</span>
                <span>Omitidas: {{ $payrollSummary['omitted'] ?? 0 }}</span>
                <span>Advertencias: {{ $payrollSummary['warnings'] ?? 0 }}</span>
                <span>Errores: {{ $payrollSummary['errors'] ?? 0 }}</span>
            </div>
            <div class="d-flex flex-wrap gap-3 mt-2 small">
                <span>Bruto: {{ \App\Support\UiFormatter::formatMoney($payrollSummary['gross_total'] ?? 0) }}</span>
                <span>Líquido: {{ \App\Support\UiFormatter::formatMoney($payrollSummary['net_total'] ?? 0) }}</span>
                <span>Costo empresa: {{ \App\Support\UiFormatter::formatMoney($payrollSummary['employer_cost_total'] ?? 0) }}</span>
                <span>Retención honorarios: {{ \App\Support\UiFormatter::formatMoney($payrollSummary['honorarios_retention_total'] ?? 0) }}</span>
            </div>
            @if (! empty($payrollSummary['messages']))
                <ul class="small text-muted mb-0 mt-2">
                    @foreach (array_slice($payrollSummary['messages'], 0, 6) as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
@endif

@if ($resource === 'sales-documents')
    @php
        $prefactProjects = \App\Models\Project::query()
            ->forCompany(auth()->user()->company_id)
            ->with('client')
            ->orderBy('name')
            ->get();
    @endphp
    <div class="app-panel p-3 mb-3">
        <form method="POST" action="{{ route('sales-prefacturation.preview') }}" class="d-flex flex-wrap align-items-end gap-2">
            @csrf
            <div style="min-width: 260px;">
                <label for="prefact-project" class="form-label small text-muted mb-1">Proyecto</label>
                <select id="prefact-project" name="project_id" class="form-select">
                    <option value="">Seleccione proyecto</option>
                    @foreach ($prefactProjects as $project)
                        <option value="{{ $project->id }}" @selected((string) old('project_id') === (string) $project->id)>
                            {{ $project->code }} — {{ $project->name }}{{ $project->client ? ' · '.$project->client->legal_name : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="prefact-period" class="form-label small text-muted mb-1">Período</label>
                <input id="prefact-period" name="period" class="form-control" value="{{ old('period', now()->format('m/Y')) }}" placeholder="08/2026">
            </div>
            <div>
                <label for="prefact-issue-date" class="form-label small text-muted mb-1">Fecha documento</label>
                <input id="prefact-issue-date" name="issue_date" type="date" class="form-control" value="{{ old('issue_date', now()->toDateString()) }}">
            </div>
            <div>
                <label for="prefact-taxable" class="form-label small text-muted mb-1">IVA</label>
                <select id="prefact-taxable" name="taxable" class="form-select">
                    <option value="1" @selected((string) old('taxable', '1') === '1')>Afecto</option>
                    <option value="0" @selected((string) old('taxable') === '0')>Exento</option>
                </select>
            </div>
            <div>
                <label for="prefact-adjustment" class="form-label small text-muted mb-1">Ajuste</label>
                <input id="prefact-adjustment" name="adjustment_amount" class="form-control" value="{{ old('adjustment_amount', 0) }}">
            </div>
            <div style="min-width: 220px;">
                <label for="prefact-adjustment-reason" class="form-label small text-muted mb-1">Motivo ajuste</label>
                <input id="prefact-adjustment-reason" name="adjustment_reason" class="form-control" value="{{ old('adjustment_reason') }}">
            </div>
            <button class="btn btn-outline-primary" type="submit">Calcular venta</button>
            <button class="btn btn-primary" type="submit" formaction="{{ route('sales-prefacturation.generate-draft') }}">Generar borrador</button>
        </form>
        <div class="small text-muted mt-2">Solo considera HH aprobadas, no facturadas y con tarifa vigente según asignación.</div>
    </div>

    @if (is_array($salesPreview))
        <div class="app-panel p-3 mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <strong>Prefacturación {{ $salesPreview['period_label'] ?? '' }}</strong>
                    <span>Proyecto: {{ $salesPreview['project']->code ?? '' }} — {{ $salesPreview['project']->name ?? '' }}</span>
                    <span>Cliente: {{ $salesPreview['client']->legal_name ?? '' }}</span>
                    <span>HH: {{ \App\Support\UiFormatter::formatHours($salesPreview['hours_total'] ?? 0) }}</span>
                </div>
                @if (! empty($salesPreview['calculation_breakdown']))
                    <x-calculation-breakdown
                        id="sales-preview-breakdown"
                        title="Cálculo de venta"
                        subtitle="Prefacturación por horas aprobadas"
                        :breakdown="$salesPreview['calculation_breakdown']"
                        trigger-class="btn btn-sm btn-outline-secondary"
                    />
                @endif
            </div>
            <div class="d-flex flex-wrap gap-3 mt-2 small">
                <span>Moneda: {{ \App\Support\UiFormatter::currencyCode($salesPreview['commercial_currency'] ?? 'CLP') }}</span>
                <span>Neto: {{ \App\Support\UiFormatter::formatMoney($salesPreview['commercial_net_amount'] ?? $salesPreview['net_amount'] ?? 0, $salesPreview['commercial_currency'] ?? 'CLP') }}</span>
                <span>IVA: {{ \App\Support\UiFormatter::formatPercent($salesPreview['vat_rate'] ?? 0) }} · {{ \App\Support\UiFormatter::formatMoney($salesPreview['commercial_vat_amount'] ?? $salesPreview['vat_amount'] ?? 0, $salesPreview['commercial_currency'] ?? 'CLP') }}</span>
                <span>Total: {{ \App\Support\UiFormatter::formatMoney($salesPreview['commercial_gross_amount'] ?? $salesPreview['gross_amount'] ?? 0, $salesPreview['commercial_currency'] ?? 'CLP') }}</span>
                @if (($salesPreview['commercial_currency'] ?? 'CLP') !== 'CLP')
                    <span>Equiv. CLP: {{ \App\Support\UiFormatter::formatMoney($salesPreview['net_amount'] ?? 0) }}</span>
                @endif
                @if (($salesPreview['adjustment_amount'] ?? 0) != 0)
                    <span>Ajuste: {{ \App\Support\UiFormatter::formatMoney($salesPreview['adjustment_amount']) }}</span>
                @endif
            </div>
            <div class="table-responsive mt-3">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th>HH</th>
                        <th>Tarifa</th>
                        <th>Unidad</th>
                        <th class="text-end">Subtotal comercial</th>
                        <th class="text-end">Equiv. CLP</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($salesPreview['lines'] ?? [] as $line)
                        <tr>
                            <td>{{ \App\Support\UiFormatter::formatHours($line['hours_approved']) }}</td>
                            <td>{{ \App\Support\UiFormatter::formatMoney($line['hourly_rate_amount'], $line['currency_code']) }}</td>
                            <td>{{ $line['currency_code'] }}</td>
                            <td class="text-end">{{ \App\Support\UiFormatter::formatMoney($line['subtotal_commercial'] ?? $line['subtotal_original'], $salesPreview['commercial_currency'] ?? $line['currency_code']) }}</td>
                            <td class="text-end">{{ \App\Support\UiFormatter::formatMoney($line['subtotal_clp']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endif

<div class="table-responsive app-panel">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
        <tr>
            <th class="table-sticky-column table-actions-head">Acciones</th>
            @if ($codeField)
                @php($definition = $fields[$codeField])
                @php($isNumeric = \App\Support\UiFormatter::isNumericField($codeField, $definition))
                <th class="table-sticky-column table-code-head {{ $isNumeric ? 'text-end amount-heading' : '' }}">
                    @if (isset($sortableFields[$codeField]))
                        @php($nextDirection = $sort === $codeField && $direction === 'asc' ? 'desc' : 'asc')
                        <a class="sort-link {{ $sort === $codeField ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['sort' => $codeField, 'direction' => $nextDirection]) }}">
                            <span>{{ $definition['label'] }}</span>
                            <span class="sort-indicator">{{ $sortIndicator($codeField) }}</span>
                        </a>
                    @else
                        {{ $definition['label'] }}
                    @endif
                </th>
            @endif
            @foreach ($fields as $field => $definition)
                @continue($field === $codeField)
                @php($isNumeric = \App\Support\UiFormatter::isNumericField($field, $definition))
                <th class="{{ $isNumeric ? 'text-end amount-heading' : '' }}">
                    @if (isset($sortableFields[$field]))
                        @php($nextDirection = $sort === $field && $direction === 'asc' ? 'desc' : 'asc')
                        <a class="sort-link {{ $sort === $field ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['sort' => $field, 'direction' => $nextDirection]) }}">
                            <span>{{ $definition['label'] }}</span>
                            <span class="sort-indicator">{{ $sortIndicator($field) }}</span>
                        </a>
                    @else
                        {{ $definition['label'] }}
                    @endif
                </th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @forelse ($items as $item)
            <tr>
                <td class="table-sticky-column table-actions-column">
                    <x-table-actions
                        :show-url="route('operational.show', [$resource, $item->id])"
                        :edit-url="route('operational.edit', [$resource, $item->id])"
                        :toggle-url="$isCatalog ? route('operational.toggle-active', [$resource, $item->id]) : null"
                        :active="(bool) ($item->active ?? $item->is_active ?? true)"
                    />
                </td>
                @if ($codeField)
                    @php($display = \App\Support\UiFormatter::display($item, $codeField, $fields[$codeField]))
                    <td class="table-sticky-column table-code-column {{ \App\Support\UiFormatter::isNumericField($codeField, $fields[$codeField]) ? 'text-end amount-cell' : '' }}">
                        @if (str_contains(mb_strtolower($fields[$codeField]['label']), 'estado') || in_array($codeField, ['status', 'payment_status', 'approval_status', 'project_status', 'billing_status'], true))
                            <x-status-badge :status="$display" />
                        @else
                            {{ $display }}
                        @endif
                    </td>
                @endif
                @foreach ($fields as $field => $definition)
                    @continue($field === $codeField)
                    @php($display = \App\Support\UiFormatter::display($item, $field, $definition))
                    <td class="{{ \App\Support\UiFormatter::isNumericField($field, $definition) ? 'text-end amount-cell' : '' }}">
                        @if (str_contains(mb_strtolower($definition['label']), 'estado') || in_array($field, ['status', 'payment_status', 'approval_status', 'project_status', 'billing_status'], true))
                            <x-status-badge :status="$display" />
                        @else
                            {{ $display }}
                        @endif
                    </td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ count($fields) + 1 }}" class="text-center text-muted py-5">Sin registros.</td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">
    {{ $items->links() }}
</div>
@endsection
