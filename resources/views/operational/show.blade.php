@extends('layouts.app')

@section('content')
@php
    $fields = $config['fields'];
    $isCatalog = (bool) ($config['catalog'] ?? false);
    $assignmentEffectiveHourlyDisplay = null;
    $assignmentEffectiveHourlyOrigin = null;
    $assignmentProjectValueDisplay = null;
    $projectCommitmentExchangeRateNote = null;
    $projectCommitmentSaleCurrencyCode = null;
    $payrollDetailRowIndex = 0;
    $isTimeEntryBatch = $resource === 'time-entries' && filled($item->period_batch_id ?? null);
    $timeEntryUpdateBlockedMessage = $resource === 'time-entries' ? ($item->time_entry_update_blocked_message ?? null) : null;

    if ($resource === 'assignments' && $item instanceof \App\Models\ProjectAssignment) {
        $item->loadMissing(['project.salesCurrency', 'hourlyRateCurrency', 'person.hourlyRateCurrency']);

        if ((float) ($item->hourly_value ?? 0) > 0) {
            $assignmentEffectiveHourlyDisplay = \App\Support\UiFormatter::formatMoney($item->hourly_value, $item->hourlyRateDisplayCurrency).' / HH';
            $assignmentEffectiveHourlyOrigin = 'Asignación';
        } elseif ((float) ($item->person?->hourly_value ?? 0) > 0) {
            $assignmentEffectiveHourlyDisplay = \App\Support\UiFormatter::formatMoney($item->person->hourly_value, $item->person->hourlyRateDisplayCurrency).' / HH';
            $assignmentEffectiveHourlyOrigin = 'Persona · '.($item->person->full_name ?: $item->person->name ?: 'No informado');
        }

        $assignmentProjectValueDisplay = $item->project_value !== null
            ? \App\Support\UiFormatter::formatMoney($item->project_value, $item->hourlyRateDisplayCurrency ?: 'CLP')
            : 'No informado';
    }

    if ($resource === 'time-entries' && $item instanceof \App\Models\TimeEntry) {
        $item->loadMissing(['person.hourlyRateCurrency', 'project.salesCurrency', 'assignment.hourlyRateCurrency', 'assignment.assignmentStatus']);
    }
@endphp
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $config['title'] }}</h1>
        <div class="page-subtitle">{{ $item->{$config['display']} ?? $item->code ?? 'Registro' }}</div>
    </div>
    <div class="page-toolbar">
        <a class="btn btn-outline-secondary" href="{{ route('operational.index', $resource) }}">Volver</a>
        @if ($resource === 'payroll-records' && $item instanceof \App\Models\PayrollRecord && $item->status === 'Borrador' && $item->calculation_status === 'OK')
            <form method="POST" action="{{ route('operational.confirm', [$resource, $item->id]) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-success">Confirmar</button>
            </form>
        @endif
        @if (blank($timeEntryUpdateBlockedMessage))
            <a class="btn btn-primary" href="{{ route('operational.edit', [$resource, $item->id]) }}">Editar</a>
        @endif
    </div>
</div>

<div class="app-panel p-4">
    @if (filled($timeEntryUpdateBlockedMessage))
        <div class="alert alert-warning" role="alert">
            {{ $timeEntryUpdateBlockedMessage }}
        </div>
    @endif
    @php($payrollHoursApprovedDisplay = $resource === 'payroll-records' && filled(data_get($payrollHourlyCost ?? [], 'worked_hours')) ? \App\Support\UiFormatter::formatHours(data_get($payrollHourlyCost, 'worked_hours')) : null)
@if ($resource === 'payroll-records' && ! empty($payrollHourlyCost))
        <div class="app-panel p-3 mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <div class="section-title mb-0">Costo HH del período</div>
                @if (! empty($payrollCalculationBreakdown))
                    <x-calculation-breakdown
                        id="payroll-show-breakdown"
                        title="Cálculo de remuneración"
                        subtitle="Snapshot histórico del período"
                        :breakdown="$payrollCalculationBreakdown"
                        trigger-class="btn btn-sm btn-outline-secondary"
                    />
                @endif
            </div>
            <div class="row g-3">
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="text-muted small">Costo empresa</div>
                    <div class="fw-semibold">{{ \App\Support\UiFormatter::formatMoney($payrollHourlyCost['company_cost']) }}</div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="text-muted small">Horas período</div>
                    <div class="fw-semibold">{{ \App\Support\UiFormatter::formatHours($payrollHourlyCost['worked_hours']) }}</div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="text-muted small">Costo HH real</div>
                    <div class="fw-semibold">{{ $payrollHourlyCost['real_hourly_cost'] !== null ? \App\Support\UiFormatter::formatMoney($payrollHourlyCost['real_hourly_cost']) : '—' }}</div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="text-muted small">Costo HH ref.</div>
                    <div class="fw-semibold">{{ $payrollHourlyCost['reference_hourly_cost'] !== null ? \App\Support\UiFormatter::formatMoney($payrollHourlyCost['reference_hourly_cost']) : '—' }}</div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="text-muted small">Horas proyecto</div>
                    <div>{{ \App\Support\UiFormatter::formatHours($payrollHourlyCost['project_hours']) }}</div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="text-muted small">Horas internas</div>
                    <div>{{ \App\Support\UiFormatter::formatHours($payrollHourlyCost['internal_hours']) }}</div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="text-muted small">Capacidad ref.</div>
                    <div>{{ \App\Support\UiFormatter::formatHours($payrollHourlyCost['reference_capacity_hours']) }}</div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="text-muted small">Costo no asignado</div>
                    <div>{{ \App\Support\UiFormatter::formatMoney($payrollHourlyCost['unassigned_cost']) }}</div>
                </div>
            </div>
            <div class="small text-muted mt-3">
                Costo HH real = costo empresa del período / horas productivas registradas.
                {{ $payrollHourlyCost['reference_capacity_label'] ?? '' }}
                @if (! empty($payrollHourlyCost['real_hourly_cost_message']))
                    {{ $payrollHourlyCost['real_hourly_cost_message'] }}
                @endif
            </div>
        </div>
    @endif

    @if ($resource === 'sales-documents' && ! empty($salesCalculationBreakdown))
        <div class="app-panel p-3 mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <div class="section-title mb-1">Venta calculada</div>
                    <div class="small text-muted">Desglose del documento y sus parámetros.</div>
                </div>
                <x-calculation-breakdown
                    id="sales-show-breakdown"
                    title="Cálculo de venta"
                    subtitle="Documento confirmado o borrador"
                    :breakdown="$salesCalculationBreakdown"
                    trigger-class="btn btn-sm btn-outline-secondary"
                />
            </div>
        </div>
    @endif

    @if ($resource === 'projects' && ! empty($projectCommitment))
        @php($projectCommitmentExchangeRateNote = data_get($projectCommitment, 'exchange_rate_note'))
        @php($projectCommitmentSaleCurrencyCode = data_get($projectCommitment, 'sale_net_currency_code', 'CLP'))
        <div class="app-panel p-3 mb-4">
            <div class="section-title mb-2">Compromiso de personal</div>
            <div class="row g-3">
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="text-muted small">Venta contractual</div>
                    <div class="fw-semibold">{{ $projectCommitment['sale_net_contractual'] !== null ? \App\Support\UiFormatter::formatMoney($projectCommitment['sale_net_contractual'], $projectCommitmentSaleCurrencyCode) : 'No disponible' }}</div>
                    @if ($projectCommitmentSaleCurrencyCode !== 'CLP' && $projectCommitment['sale_net_clp'] !== null)
                        <div class="small text-muted">Equivalente para proyección: {{ \App\Support\UiFormatter::formatMoney($projectCommitment['sale_net_clp']) }}</div>
                    @endif
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="text-muted small">Personal comprometido</div>
                    <div class="fw-semibold">{{ $projectCommitment['personnel_committed_cost'] !== null ? \App\Support\UiFormatter::formatMoney($projectCommitment['personnel_committed_cost']) : 'No disponible' }}</div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="text-muted small">{{ ($projectCommitment['projected_personnel_margin'] ?? 0) < 0 ? 'Pérdida proyectada' : 'Margen proyectado de personal' }}</div>
                    <div class="fw-semibold {{ ($projectCommitment['projected_personnel_margin'] ?? 0) < 0 ? 'text-danger' : 'text-success' }}">
                        {{ $projectCommitment['projected_personnel_margin'] !== null ? \App\Support\UiFormatter::formatMoney(abs($projectCommitment['projected_personnel_margin'])) : 'No disponible' }}
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="text-muted small">Comprometido</div>
                    <div class="fw-semibold {{ ($projectCommitment['committed_percentage'] ?? 0) > 100 ? 'text-danger' : '' }}">
                        {{ $projectCommitment['committed_percentage'] !== null ? \App\Support\UiFormatter::formatPercent($projectCommitment['committed_percentage'] / 100, 1) : 'No disponible' }}
                    </div>
                </div>
            </div>
            @if (filled($projectCommitmentExchangeRateNote))
                <div class="small text-muted mt-3">{{ $projectCommitmentExchangeRateNote }}</div>
            @endif
            @if (! empty($projectCommitment['warnings']))
                <div class="alert {{ $projectCommitment['negative_margin'] ? 'alert-warning' : 'alert-secondary' }} py-2 mt-3 mb-0">
                    <ul class="mb-0 ps-3 small">
                        @foreach ($projectCommitment['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    @if ($isTimeEntryBatch)
        <div class="section-title">Carga de horas</div>
        <div class="row g-3">
            <div class="col-12 col-md-6 col-xl-4">
                <div class="text-muted small">Persona</div>
                <div class="fw-semibold">{{ $item->person?->full_name ?: $item->person?->name ?: '—' }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="text-muted small">Proyecto</div>
                <div class="fw-semibold">{{ $item->project?->name ?: '—' }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="text-muted small">Cliente</div>
                <div class="fw-semibold">{{ $item->client?->legal_name ?: '—' }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="text-muted small">Actividad</div>
                <div class="fw-semibold">{{ $item->activityCatalog?->name ?: $item->activity ?: '—' }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="text-muted small">Centro de costo</div>
                <div class="fw-semibold">{{ $item->costCenter?->name ?: '—' }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="text-muted small">Período</div>
                <div class="fw-semibold">{{ $item->period_batch_date_display ?? (filled($item->entry_date) ? \App\Support\UiFormatter::formatDate($item->entry_date) : '—') }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="text-muted small">Registros diarios</div>
                <div class="fw-semibold">{{ $item->period_batch_entry_count ?? 1 }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="text-muted small">Horas trabajadas</div>
                <div class="fw-semibold">{{ \App\Support\UiFormatter::formatHours($item->hours_worked) }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="text-muted small">Horas aprobadas</div>
                <div class="fw-semibold">{{ \App\Support\UiFormatter::formatHours($item->hours_approved) }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="text-muted small">Código</div>
                <div class="fw-semibold">{{ $item->period_batch_code_display ?? $item->code }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="text-muted small">Valor HH costeo</div>
                <div class="fw-semibold">{{ $item->period_batch_hourly_value_display ?? (\App\Support\UiFormatter::formatMoney($item->hourly_value, $item->hourlyRateDisplayCurrency).' / HH') }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="text-muted small">Aprobación</div>
                <div class="fw-semibold">{{ $item->period_batch_approval_status_display ?? ($item->approvalStatus?->name ?: '—') }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="text-muted small">Pago</div>
                <div class="fw-semibold">{{ $item->period_batch_payment_status_display ?? (strtolower((string) $item->payment_status) === 'paid' ? 'Pagado' : 'Pendiente') }}</div>
            </div>
        </div>
    @else
        @php($currentSection = null)
        @foreach ($fields as $field => $definition)
            @if (($definition['section'] ?? null) !== $currentSection)
                @php($currentSection = $definition['section'] ?? null)
                @if ($currentSection)
                    <div class="section-title">{{ $currentSection }}</div>
                @endif
            @endif
            @php($payrollDetailRowIndex++)
            <div class="row mb-3 payroll-detail-row {{ $resource === 'payroll-records' && $payrollDetailRowIndex % 2 === 1 ? 'payroll-detail-row-alt' : '' }}">
                <dt class="col-sm-4">{{ $resource === 'payroll-records' && $field === 'hours_approved' ? 'Horas aprobadas del período' : $definition['label'] }}</dt>
                @php($display = match (true) {
                    $resource === 'payroll-records' && $field === 'hours_approved' && filled($payrollHoursApprovedDisplay) => $payrollHoursApprovedDisplay,
                    $resource === 'assignments' && $field === 'hourly_value' => $assignmentEffectiveHourlyDisplay ?: 'No configurado',
                    $resource === 'assignments' && $field === 'project_value' => $assignmentProjectValueDisplay,
                    $resource === 'time-entries' && $field === 'hourly_value' => filled($item->hourlyRateDisplayCurrency) && filled($item->hourly_value) ? \App\Support\UiFormatter::formatMoney($item->hourly_value, $item->hourlyRateDisplayCurrency).' / HH' : '—',
                    default => \App\Support\UiFormatter::display($item, $field, $definition),
                })
                <dd class="col-sm-8 mb-0 {{ \App\Support\UiFormatter::isNumericField($field, $definition) ? 'text-sm-end amount-cell' : '' }}">
                    @if (str_contains(mb_strtolower($definition['label']), 'estado') || in_array($field, ['status', 'payment_status', 'approval_status', 'project_status', 'billing_status'], true))
                        <x-status-badge :status="$display" />
                    @else
                        {{ $display }}
                        @if ($resource === 'assignments' && $field === 'hourly_value' && $assignmentEffectiveHourlyOrigin)
                            <div class="small text-muted mt-1">Origen: {{ $assignmentEffectiveHourlyOrigin }}</div>
                        @endif
                    @endif
                </dd>
            </div>
        @endforeach
    @endif

    @if ($isCatalog)
        <form method="POST" action="{{ route('operational.toggle-active', [$resource, $item->id]) }}" class="mt-4">
            @csrf
            @method('PATCH')
            <button type="submit" class="btn btn-outline-warning">{{ $item->active ? 'Desactivar' : 'Activar' }}</button>
        </form>
    @else
        <form method="POST" action="{{ route('operational.destroy', [$resource, $item->id]) }}" class="mt-4">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-outline-danger">{{ $isTimeEntryBatch ? 'Eliminar carga' : 'Eliminar' }}</button>
        </form>
    @endif
</div>
@endsection

@push('styles')
    <style>
        .payroll-detail-row {
            border-radius: 0.75rem;
            padding: 0.45rem 0.75rem;
            transition: background-color 0.15s ease, box-shadow 0.15s ease;
        }

        .payroll-detail-row-alt {
            background-color: rgba(13, 110, 253, 0.045);
        }

        .payroll-detail-row:hover {
            background-color: rgba(13, 110, 253, 0.075);
        }

        .payroll-detail-row dt,
        .payroll-detail-row dd {
            margin-bottom: 0;
        }
    </style>
@endpush
