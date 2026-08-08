@extends('layouts.app')

@php
    $fmtMoney = fn ($value) => '$'.number_format((float) $value, 0, ',', '.');
    $fmtPct = fn ($value) => is_null($value) ? '—' : number_format(((float) $value) * 100, 1, ',', '.').'%';
@endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Presupuesto</h1>
        <div class="text-muted small">Comparación presupuesto vs real usando caja real y documentos.</div>
    </div>
    <form method="GET" class="d-flex gap-2">
        <input class="form-control" type="month" name="period" value="{{ $period->format('Y-m') }}">
        <input class="form-control" type="number" name="project_id" min="1" value="{{ $projectId }}" placeholder="Proyecto ID">
        <button class="btn btn-outline-secondary" type="submit">Filtrar</button>
    </form>
</div>

<div class="row g-3 mb-4">
    @foreach ([
        ['Ingresos', $companyVariance['revenue_budget'], $companyVariance['revenue_real'], $companyVariance['revenue_difference'], $companyVariance['revenue_difference_pct']],
        ['Personal', $companyVariance['personnel_budget'], $companyVariance['personnel_real'], $companyVariance['personnel_real'] - $companyVariance['personnel_budget'], $companyVariance['personnel_budget'] > 0 ? (($companyVariance['personnel_real'] - $companyVariance['personnel_budget']) / $companyVariance['personnel_budget']) : null],
        ['Otros', $companyVariance['other_direct_budget'], $companyVariance['other_real'], $companyVariance['other_real'] - $companyVariance['other_direct_budget'], $companyVariance['other_direct_budget'] > 0 ? (($companyVariance['other_real'] - $companyVariance['other_direct_budget']) / $companyVariance['other_direct_budget']) : null],
        ['Obligaciones', $companyVariance['legal_budget'], $companyVariance['legal_real'], $companyVariance['legal_real'] - $companyVariance['legal_budget'], $companyVariance['legal_budget'] > 0 ? (($companyVariance['legal_real'] - $companyVariance['legal_budget']) / $companyVariance['legal_budget']) : null],
    ] as [$label, $budget, $real, $diff, $pct])
        <div class="col-md-6 col-xl-3">
            <div class="card kpi-card h-100">
                <div class="card-body">
                    <div class="text-muted small">{{ $label }}</div>
                    <div class="small">Presupuesto {{ $fmtMoney($budget) }}</div>
                    <div class="small">Real {{ $fmtMoney($real) }}</div>
                    <div class="fw-semibold {{ $diff >= 0 ? 'text-success' : 'text-danger' }}">Dif. {{ $fmtMoney($diff) }} · {{ $fmtPct($pct) }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="table-responsive bg-white border rounded shadow-sm">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
        <tr>
            <th>Período</th>
            <th>Proyecto</th>
            <th class="text-end">Ingreso Ppto</th>
            <th class="text-end">Ingreso Real</th>
            <th class="text-end">Dif.</th>
            <th class="text-end">Personal Dif.</th>
            <th class="text-end">Otros Dif.</th>
            <th class="text-end">Legal Dif.</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($budgetRows as $row)
            <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($row['period_date'])->format('m-Y') }}</td>
                <td>{{ data_get($row, 'project.code') ?: 'Empresa' }}</td>
                <td class="text-end">{{ $fmtMoney($row['revenue_budget']) }}</td>
                <td class="text-end">{{ $fmtMoney($row['revenue_real']) }}</td>
                <td class="text-end {{ $row['revenue_difference'] >= 0 ? 'text-success' : 'text-danger' }}">{{ $fmtMoney($row['revenue_difference']) }}</td>
                <td class="text-end {{ ($row['personnel_real'] - $row['personnel_budget']) <= 0 ? 'text-success' : 'text-danger' }}">{{ $fmtMoney($row['personnel_real'] - $row['personnel_budget']) }}</td>
                <td class="text-end {{ ($row['other_real'] - $row['other_direct_budget']) <= 0 ? 'text-success' : 'text-danger' }}">{{ $fmtMoney($row['other_real'] - $row['other_direct_budget']) }}</td>
                <td class="text-end {{ ($row['legal_real'] - $row['legal_budget']) <= 0 ? 'text-success' : 'text-danger' }}">{{ $fmtMoney($row['legal_real'] - $row['legal_budget']) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted py-5">Sin presupuestos cargados.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $budgetRows->links() }}</div>
@endsection
