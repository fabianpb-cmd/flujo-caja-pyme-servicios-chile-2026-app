@extends('layouts.app')

@php
    $scenarioData = $data['scenario'];
    $kpis = $data['kpis'];
    $flows = collect($data['flows']);
    $profitability = collect($data['profitability']);
    $fmtMoney = fn ($value) => '$'.number_format((float) $value, 0, ',', '.');
    $fmtPct = fn ($value) => number_format(((float) $value) * 100, 1, ',', '.').'%';
    $fromPeriod = \Illuminate\Support\Carbon::parse($dashboardMeta['from']);
    $toPeriod = \Illuminate\Support\Carbon::parse($dashboardMeta['to']);
    $expenseTotal = collect($expenseBreakdown)->sum('amount');
@endphp

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Dashboard ejecutivo</h1>
        <p class="page-subtitle">Resumen financiero actualizado al {{ $dashboardMeta['updated_at']->format('d-m-Y') }}</p>
    </div>
    <form method="GET" class="dashboard-filters">
        <div class="filter-chip">
            <label class="form-label small text-muted mb-1">Escenario</label>
            <select class="form-select" name="scenario" onchange="this.form.submit()">
                @foreach (['CONSERVADOR', 'BASE', 'OPTIMISTA'] as $option)
                    <option value="{{ $option }}" @selected(($scenario ?: $scenarioData->code) === $option)>{{ ucfirst(strtolower($option)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="filter-chip">
            <label class="form-label small text-muted mb-1">Desde</label>
            <input class="form-control" type="text" value="{{ $fromPeriod->format('d-m-Y') }}" readonly>
        </div>
        <div class="filter-chip">
            <label class="form-label small text-muted mb-1">Hasta</label>
            <input class="form-control" type="text" value="{{ $toPeriod->format('d-m-Y') }}" readonly>
        </div>
    </form>
</div>

<div class="kpi-grid">
    <x-kpi-card title="Saldo Disponible" :value="$fmtMoney($kpis['cash_available'])" icon="bi bi-bank" tone="primary" subtitle="Actual" />
    <x-kpi-card title="Ingresos del Mes" :value="$fmtMoney($kpis['income_month'])" icon="bi bi-arrow-up-circle" tone="success" subtitle="Caja real del período" />
    <x-kpi-card title="Egresos del Mes" :value="$fmtMoney($kpis['expense_month'])" icon="bi bi-arrow-down-circle" tone="danger" subtitle="Caja real del período" />
    <x-kpi-card title="Flujo Neto del Mes" :value="$fmtMoney($kpis['net_flow'])" icon="bi bi-graph-up-arrow" :tone="$kpis['net_flow'] >= 0 ? 'info' : 'danger'" subtitle="Real" />
    <x-kpi-card title="Cuentas por Cobrar" :value="$fmtMoney($kpis['receivables'])" icon="bi bi-person-lines-fill" tone="warning" :subtitle="$dashboardMeta['receivable_documents'].' documentos'" />
    <x-kpi-card title="Cuentas por Pagar" :value="$fmtMoney($kpis['payables'])" icon="bi bi-wallet2" tone="warning" :subtitle="$dashboardMeta['payable_documents'].' documentos'" />
</div>

<div class="dashboard-grid mb-4">
    <div class="dashboard-main">
        <div class="app-panel section-card chart-panel h-100">
            <div class="section-card-header">
                <div>
                    <h2 class="section-card-title">Flujo de Caja Proyectado vs Real</h2>
                    <div class="section-card-note">{{ $flows->count() }} períodos visibles</div>
                </div>
            </div>
            <div class="chart-container chart-container-lg">
                <canvas id="cashFlowChart"></canvas>
            </div>
        </div>
    </div>
    <div class="dashboard-side">
        <div class="app-panel section-card chart-panel h-100">
            <div class="section-card-header">
                <div>
                    <h2 class="section-card-title">Distribución de Egresos del Mes</h2>
                    <div class="section-card-note">Basado en movimientos reales</div>
                </div>
            </div>
            @if ($expenseBreakdown->isNotEmpty())
                <div class="row g-3 align-items-center">
                    <div class="col-lg-6">
                        <div class="chart-container chart-container-sm">
                            <canvas id="expenseChart"></canvas>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="d-flex flex-column gap-3">
                            @foreach ($expenseBreakdown as $row)
                                <div class="d-flex justify-content-between gap-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="rounded-circle d-inline-block" style="width: 10px; height: 10px; background: {{ ['#2f80ed', '#36c38f', '#f5a623', '#ef4444', '#8b5cf6', '#06b6d4'][$loop->index % 6] }}"></span>
                                        <span>{{ $row['label'] }}</span>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-semibold">{{ $fmtMoney($row['amount']) }}</div>
                                        <div class="small text-muted">{{ $expenseTotal > 0 ? number_format(($row['amount'] / $expenseTotal) * 100, 1, ',', '.') : '0,0' }}%</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @else
                <div class="text-muted py-5 text-center">Sin egresos reales para el mes seleccionado.</div>
            @endif
        </div>
    </div>
</div>

<div class="dashboard-grid mb-4">
    <div class="dashboard-third">
        <div class="app-panel section-card h-100">
            <div class="section-card-header">
                <h2 class="section-card-title">Alertas importantes</h2>
            </div>
            <x-alert-item
                title="{{ $kpis['overdue_invoices'] }} facturas vencidas"
                text="Requieren seguimiento de cobranza."
                status="{{ $kpis['overdue_invoices'] > 0 ? 'Crítico' : 'OK' }}"
                icon="bi bi-exclamation-diamond"
                tone="{{ $kpis['overdue_invoices'] > 0 ? 'danger' : 'success' }}"
            />
            <x-alert-item
                title="{{ $kpis['upcoming_obligations'] }} obligaciones próximas"
                text="Vencen dentro de los próximos 30 días."
                status="{{ $kpis['upcoming_obligations'] > 0 ? 'Atención' : 'OK' }}"
                icon="bi bi-calendar-event"
                tone="warning"
            />
            <x-alert-item
                title="{{ $kpis['low_margin_projects'] }} proyectos bajo margen"
                text="Conviene revisar rentabilidad y costos."
                status="{{ $kpis['low_margin_projects'] > 0 ? 'Atención' : 'OK' }}"
                icon="bi bi-bar-chart-steps"
                tone="warning"
            />
            <x-alert-item
                title="{{ $dashboardMeta['payments_without_date'] }} pagos sin fecha"
                text="Hay registros pendientes de programación."
                status="{{ $dashboardMeta['payments_without_date'] > 0 ? 'Informativo' : 'OK' }}"
                icon="bi bi-info-circle"
                tone="info"
            />
        </div>
    </div>

    <div class="dashboard-third">
        <div class="app-panel section-card h-100">
            <div class="section-card-header">
                <h2 class="section-card-title">Obligaciones próximos 30 días</h2>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                    <tr>
                        <th>Obligación</th>
                        <th>Período</th>
                        <th>Fecha</th>
                        <th class="text-end">Monto</th>
                        <th class="text-end">Días</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($upcomingObligations as $row)
                        <tr>
                            <td>{{ $row->obligation_type }}</td>
                            <td>{{ $row->period_date?->format('m-Y') }}</td>
                            <td>{{ $row->due_date?->format('d-m-Y') }}</td>
                            <td class="text-end">{{ $fmtMoney($row->pending_amount) }}</td>
                            <td class="text-end {{ floor(now()->diffInDays($row->due_date, false)) <= 7 ? 'text-danger fw-semibold' : 'text-muted' }}">
                                {{ floor(now()->diffInDays($row->due_date, false)) }} días
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">Sin obligaciones inmediatas.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between pt-3">
                <span class="fw-semibold">Total estimado</span>
                <span class="fw-bold text-primary">{{ $fmtMoney($upcomingObligations->sum('pending_amount')) }}</span>
            </div>
        </div>
    </div>

    <div class="dashboard-third">
        <div class="app-panel section-card h-100">
            <div class="section-card-header">
                <h2 class="section-card-title">Proyectos Bajo Margen</h2>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                    <tr>
                        <th>Proyecto</th>
                        <th class="text-end">Margen %</th>
                        <th class="text-end">Estado</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($priorityProjects as $row)
                        <tr>
                            <td>{{ $row['project_code'] }} {{ $row['project_name'] }}</td>
                            <td class="text-end">{{ $fmtPct($row['margin_pct']) }}</td>
                            <td class="text-end"><x-status-badge :status="$row['status']" /></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">Sin proyectos para mostrar.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="app-panel section-card">
    <div class="section-card-header">
        <h2 class="section-card-title">Acciones Rápidas</h2>
    </div>
    <div class="quick-actions">
        @foreach ($actions as $action)
            <a class="app-panel quick-action-card" href="{{ route($action['route'], $action['params']) }}">
                <span class="quick-action-icon kpi-icon {{ $action['tone'] }}">
                    <i class="{{ $action['icon'] }}"></i>
                </span>
                <span class="quick-action-title">{{ $action['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
    const flowLabels = @json($flows->map(fn ($row) => \Illuminate\Support\Carbon::parse($row['period'])->format('M Y'))->all());
    const flowReal = @json($flows->pluck('closing_real')->all());
    const flowProjected = @json($flows->pluck('closing_projected')->all());
    const expenseLabels = @json($expenseBreakdown->pluck('label')->all());
    const expenseValues = @json($expenseBreakdown->pluck('amount')->all());
    const expenseColors = ['#2f80ed', '#36c38f', '#f5a623', '#ef4444', '#8b5cf6', '#06b6d4'];
    let cashFlowChartInstance = window.cashFlowChartInstance ?? null;
    let expenseChartInstance = window.expenseChartInstance ?? null;

    const cashFlowCanvas = document.getElementById('cashFlowChart');
    if (cashFlowCanvas) {
        if (cashFlowChartInstance) {
            cashFlowChartInstance.destroy();
        }

        cashFlowChartInstance = new Chart(cashFlowCanvas, {
        type: 'line',
        data: {
            labels: flowLabels,
            datasets: [
                {
                    label: 'Proyectado',
                    data: flowProjected,
                    borderColor: '#2f80ed',
                    backgroundColor: 'rgba(47, 128, 237, 0.08)',
                    pointRadius: 2,
                    borderWidth: 2,
                    tension: 0.3,
                    borderDash: [6, 4]
                },
                {
                    label: 'Real',
                    data: flowReal,
                    borderColor: '#1d4ed8',
                    backgroundColor: 'rgba(29, 78, 216, 0.12)',
                    pointRadius: 2,
                    borderWidth: 2.5,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    align: 'start'
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: (value) => new Intl.NumberFormat('es-CL').format(value)
                    },
                    grid: {
                        color: 'rgba(148, 163, 184, 0.14)'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
        });

        window.cashFlowChartInstance = cashFlowChartInstance;
    }

    const expenseCanvas = document.getElementById('expenseChart');
    if (expenseCanvas && expenseLabels.length) {
        if (expenseChartInstance) {
            expenseChartInstance.destroy();
        }

        expenseChartInstance = new Chart(expenseCanvas, {
            type: 'doughnut',
            data: {
                labels: expenseLabels,
                datasets: [{
                    data: expenseValues,
                    backgroundColor: expenseColors.slice(0, expenseLabels.length),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '64%',
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        window.expenseChartInstance = expenseChartInstance;
    }
</script>
@endpush
