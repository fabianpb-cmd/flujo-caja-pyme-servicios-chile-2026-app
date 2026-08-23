@extends('layouts.app')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Rentabilidad por proyecto</h1>
        <div class="page-subtitle">Separa el margen real del período del compromiso proyectado de personal, sin usar caja para el margen.</div>
    </div>
    <form method="GET" class="d-flex flex-wrap gap-2">
        <input class="form-control" type="search" name="q" value="{{ $query }}" placeholder="Proyecto o cliente">
        <input class="form-control" type="month" name="period" value="{{ $period }}">
        <select class="form-select" name="client_id">
            <option value="">Todos los clientes</option>
            @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected((int) $clientId === (int) $client->id)>{{ $client->legal_name }}</option>
            @endforeach
        </select>
        <select class="form-select" name="project_id">
            <option value="">Todos los proyectos</option>
            @foreach ($projects as $project)
                <option value="{{ $project->id }}" @selected((int) $projectId === (int) $project->id)>{{ $project->code }} · {{ $project->name }}</option>
            @endforeach
        </select>
        <select class="form-select" name="project_status">
            <option value="">Todos los estados proyecto</option>
            @foreach ($projectStatuses as $option)
                <option value="{{ $option['code'] }}" @selected($projectStatus === $option['code'])>{{ $option['name'] }}</option>
            @endforeach
        </select>
        <select class="form-select" name="status">
            <option value="">Todos los márgenes</option>
            @foreach (['OK', 'Bajo mínimo', 'Pérdida'] as $option)
                <option value="{{ $option }}" @selected($status === $option)>{{ $option }}</option>
            @endforeach
        </select>
        <button class="btn btn-outline-secondary" type="submit">Filtrar</button>
    </form>
</div>

<div class="app-panel p-3 mb-3 d-flex flex-wrap gap-3">
    <div><strong>Costo asignado:</strong> {{ \App\Support\UiFormatter::formatMoney($summary['assigned_cost'] ?? 0) }}</div>
    <div><strong>Costo no asignado:</strong> {{ \App\Support\UiFormatter::formatMoney($summary['unassigned_cost'] ?? 0) }}</div>
</div>

<div class="table-responsive app-panel">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
        <tr>
            <th>Proyecto</th>
            <th>Cliente</th>
            <th>Estado proyecto</th>
            <th class="text-end">Venta generada</th>
            <th class="text-end">Venta facturada</th>
            <th class="text-end">Venta cobrada</th>
            <th class="text-end">Costo laboral</th>
            <th class="text-end">Vacaciones</th>
            <th class="text-end">Otros costos directos</th>
            <th class="text-end">Costo total directo</th>
            <th class="text-end">Margen</th>
            <th class="text-end">Margen %</th>
            <th class="text-end">HH trabajadas</th>
            <th class="text-end">HH aprobadas</th>
            <th class="text-end">HH facturadas</th>
            <th class="text-end">HH pendientes</th>
            <th class="text-end">Venta prom. HH</th>
            <th class="text-end">Costo HH prom.</th>
            <th class="text-end">Margen HH</th>
            <th class="text-end">Venta contractual</th>
            <th class="text-end">Personal comprometido</th>
            <th class="text-end">Margen proyectado personal</th>
            <th class="text-end">% comprometido</th>
            <th>Alertas proyección</th>
            <th>Alertas</th>
            <th>Estado</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>
                    <div class="fw-semibold">{{ $row['project_code'] }}</div>
                    <div>{{ $row['project_name'] }}</div>
                    <div class="mt-2">
                        @if (! empty($row['calculation_breakdown']))
                            <x-calculation-breakdown
                                id="profitability-breakdown-{{ $row['project_id'] }}"
                                title="Cálculo de rentabilidad"
                                subtitle="{{ $row['project_code'] }} · {{ $row['project_name'] }}"
                                :breakdown="$row['calculation_breakdown']"
                                trigger-class="btn btn-sm btn-outline-secondary"
                            />
                        @endif
                    </div>
                </td>
                <td>{{ $row['client_name'] }}</td>
                <td>{{ $row['project_status'] ?: '—' }}</td>
                <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['sale']) }}</td>
                <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['facturado']) }}</td>
                <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['cobrado']) }}</td>
                <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['cost_personal']) }}</td>
                <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['vacation_provision']) }}</td>
                <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['other_costs']) }}</td>
                <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['total_cost']) }}</td>
                <td class="text-end amount-cell {{ $row['margin'] >= 0 ? 'text-success' : 'text-danger' }}">{{ \App\Support\UiFormatter::formatMoney($row['margin']) }}</td>
                <td class="text-end amount-cell">{{ $row['facturado'] > 0 ? \App\Support\UiFormatter::formatPercent($row['margin_pct']) : '—' }}</td>
                <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatHours($row['hours_worked']) }}</td>
                <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatHours($row['hours']) }}</td>
                <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatHours($row['hours_billed']) }}</td>
                <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatHours($row['hours_pending']) }}</td>
                <td class="text-end amount-cell">{{ $row['effective_rate'] !== null ? \App\Support\UiFormatter::formatMoney($row['effective_rate']) : '—' }}</td>
                <td class="text-end amount-cell">{{ $row['hour_cost'] !== null ? \App\Support\UiFormatter::formatMoney($row['hour_cost']) : '—' }}</td>
                <td class="text-end amount-cell {{ ($row['hour_margin'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">{{ $row['hour_margin'] !== null ? \App\Support\UiFormatter::formatMoney($row['hour_margin']) : '—' }}</td>
                <td class="text-end amount-cell">
                    <div>{{ $row['projected_personnel_sale_contractual'] !== null ? \App\Support\UiFormatter::formatMoney($row['projected_personnel_sale_contractual'], $row['projected_personnel_sale_currency_code'] ?? 'CLP') : '—' }}</div>
                    @if (($row['projected_personnel_sale_currency_code'] ?? 'CLP') !== 'CLP' && $row['projected_personnel_sale'] !== null)
                        <div class="small text-muted">{{ \App\Support\UiFormatter::formatMoney($row['projected_personnel_sale']) }} equiv.</div>
                    @endif
                </td>
                <td class="text-end amount-cell">{{ $row['personnel_committed_cost'] !== null ? \App\Support\UiFormatter::formatMoney($row['personnel_committed_cost']) : 'No disponible' }}</td>
                <td class="text-end amount-cell {{ ($row['projected_personnel_margin'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">{{ $row['projected_personnel_margin'] !== null ? \App\Support\UiFormatter::formatMoney($row['projected_personnel_margin']) : 'No disponible' }}</td>
                <td class="text-end amount-cell">{{ $row['committed_percentage'] !== null ? \App\Support\UiFormatter::formatPercent($row['committed_percentage'] / 100, 1) : 'No disponible' }}</td>
                <td>
                    @if (! empty($row['commitment_warnings']))
                        <ul class="mb-0 ps-3 small">
                            @foreach ($row['commitment_warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td>
                    @if (! empty($row['alerts']))
                        <ul class="mb-0 ps-3 small">
                            @foreach ($row['alerts'] as $alert)
                                <li>{{ $alert }}</li>
                            @endforeach
                        </ul>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td><x-status-badge :status="$row['status']" /></td>
            </tr>
        @empty
            <tr><td colspan="26" class="text-center text-muted py-5">Sin proyectos para mostrar.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
