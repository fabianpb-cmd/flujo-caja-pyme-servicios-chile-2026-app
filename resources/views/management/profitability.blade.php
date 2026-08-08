@extends('layouts.app')

@php
    $fmtMoney = fn ($value) => '$'.number_format((float) $value, 0, ',', '.');
    $fmtPct = fn ($value) => number_format(((float) $value) * 100, 1, ',', '.').'%';
@endphp

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Rentabilidad por proyecto</h1>
        <div class="page-subtitle">Venta, facturación, cobro, costo personal, vacaciones provisionadas y margen.</div>
    </div>
    <form method="GET" class="d-flex gap-2">
        <input class="form-control" type="search" name="q" value="{{ $query }}" placeholder="Proyecto o cliente">
        <select class="form-select" name="status">
            <option value="">Todos</option>
            @foreach (['OK', 'Bajo mínimo', 'Pérdida'] as $option)
                <option value="{{ $option }}" @selected($status === $option)>{{ $option }}</option>
            @endforeach
        </select>
        <button class="btn btn-outline-secondary" type="submit">Filtrar</button>
    </form>
</div>

<div class="table-responsive app-panel">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
        <tr>
            <th>Proyecto</th>
            <th>Cliente</th>
            <th class="text-end">Venta</th>
            <th class="text-end">Facturado</th>
            <th class="text-end">Cobrado</th>
            <th class="text-end">Costo personal</th>
            <th class="text-end">Vacaciones</th>
            <th class="text-end">Otros costos</th>
            <th class="text-end">Costo total</th>
            <th class="text-end">Margen</th>
            <th class="text-end">Margen %</th>
            <th class="text-end">Horas</th>
            <th class="text-end">Tarifa pactada</th>
            <th class="text-end">Tarifa efectiva</th>
            <th class="text-end">Costo hora</th>
            <th>Estado</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row['project_code'] }} · {{ $row['project_name'] }}</td>
                <td>{{ $row['client_name'] }}</td>
                <td class="text-end">{{ $fmtMoney($row['sale']) }}</td>
                <td class="text-end">{{ $fmtMoney($row['facturado']) }}</td>
                <td class="text-end">{{ $fmtMoney($row['cobrado']) }}</td>
                <td class="text-end">{{ $fmtMoney($row['cost_personal']) }}</td>
                <td class="text-end">{{ $fmtMoney($row['vacation_provision']) }}</td>
                <td class="text-end">{{ $fmtMoney($row['other_costs']) }}</td>
                <td class="text-end">{{ $fmtMoney($row['total_cost']) }}</td>
                <td class="text-end {{ $row['margin'] >= 0 ? 'text-success' : 'text-danger' }}">{{ $fmtMoney($row['margin']) }}</td>
                <td class="text-end">{{ $fmtPct($row['margin_pct']) }}</td>
                <td class="text-end">{{ number_format($row['hours'], 2, ',', '.') }}</td>
                <td class="text-end">{{ $row['contracted_rate'] ? $fmtMoney($row['contracted_rate']) : '—' }}</td>
                <td class="text-end">{{ $row['effective_rate'] ? $fmtMoney($row['effective_rate']) : '—' }}</td>
                <td class="text-end">{{ $row['hour_cost'] ? $fmtMoney($row['hour_cost']) : '—' }}</td>
                <td><x-status-badge :status="$row['status']" /></td>
            </tr>
        @empty
            <tr><td colspan="16" class="text-center text-muted py-5">Sin proyectos para mostrar.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
