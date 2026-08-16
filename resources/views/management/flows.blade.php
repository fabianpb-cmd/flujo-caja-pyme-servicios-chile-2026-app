@extends('layouts.app')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Flujo mensual y semanal</h1>
        <div class="page-subtitle">Caja real desde movimientos y forecast por escenario.</div>
    </div>
    <form method="GET" class="d-flex gap-2">
        <input class="form-control" type="month" name="period" value="{{ $period->format('Y-m') }}">
        <select class="form-select" name="scenario">
            @foreach (['CONSERVADOR', 'BASE', 'OPTIMISTA'] as $option)
                <option value="{{ $option }}" @selected(($scenario ?: 'BASE') === $option)>{{ $option }}</option>
            @endforeach
        </select>
        <button class="btn btn-outline-secondary" type="submit">Actualizar</button>
    </form>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Flujo mensual</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                <tr>
                    <th>Período</th>
                    <th class="text-end">Saldo inicial</th>
                    <th class="text-end">Ing. real</th>
                    <th class="text-end">Ing. proj.</th>
                    <th class="text-end">Egr. real</th>
                    <th class="text-end">Egr. proj.</th>
                    <th class="text-end">Flujo real</th>
                    <th class="text-end">Flujo proj.</th>
                    <th class="text-end">Saldo real</th>
                    <th class="text-end">Saldo proj.</th>
                    <th class="text-end">CxC</th>
                    <th class="text-end">CxP</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($monthly as $row)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($row['period'])->format('M Y') }}</td>
                        <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['opening_real']) }}</td>
                        <td class="text-end text-success amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['income_real']) }}</td>
                        <td class="text-end text-primary amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['income_projected']) }}</td>
                        <td class="text-end text-danger amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['other_real'] + $row['personnel_real'] + $row['legal_real']) }}</td>
                        <td class="text-end text-secondary amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['other_projected'] + $row['personnel_projected'] + $row['legal_projected']) }}</td>
                        <td class="text-end amount-cell {{ $row['net_real'] >= 0 ? 'text-success' : 'text-danger' }}">{{ \App\Support\UiFormatter::formatMoney($row['net_real']) }}</td>
                        <td class="text-end amount-cell {{ $row['net_projected'] >= 0 ? 'text-success' : 'text-danger' }}">{{ \App\Support\UiFormatter::formatMoney($row['net_projected']) }}</td>
                        <td class="text-end fw-semibold amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['closing_real']) }}</td>
                        <td class="text-end fw-semibold amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['closing_projected']) }}</td>
                        <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['accounts_receivable']) }}</td>
                        <td class="text-end amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['accounts_payable']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5 mb-3">Flujo semanal</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Semana</th>
                    <th class="text-end">Ing. real</th>
                    <th class="text-end">Ing. proj.</th>
                    <th class="text-end">Egr. real</th>
                    <th class="text-end">Egr. proj.</th>
                    <th class="text-end">Flujo real</th>
                    <th class="text-end">Flujo proj.</th>
                    <th class="text-end">Saldo real</th>
                    <th class="text-end">Saldo proj.</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($weekly as $row)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($row['start'])->format('d-m') }} → {{ \Illuminate\Support\Carbon::parse($row['end'])->format('d-m') }}</td>
                        <td class="text-end text-success amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['income_real']) }}</td>
                        <td class="text-end text-primary amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['income_projected']) }}</td>
                        <td class="text-end text-danger amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['other_real'] + $row['personnel_real'] + $row['legal_real']) }}</td>
                        <td class="text-end text-secondary amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['other_projected'] + $row['personnel_projected'] + $row['legal_projected']) }}</td>
                        <td class="text-end amount-cell {{ $row['net_real'] >= 0 ? 'text-success' : 'text-danger' }}">{{ \App\Support\UiFormatter::formatMoney($row['net_real']) }}</td>
                        <td class="text-end amount-cell {{ $row['net_projected'] >= 0 ? 'text-success' : 'text-danger' }}">{{ \App\Support\UiFormatter::formatMoney($row['net_projected']) }}</td>
                        <td class="text-end fw-semibold amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['closing_real']) }}</td>
                        <td class="text-end fw-semibold amount-cell">{{ \App\Support\UiFormatter::formatMoney($row['closing_projected']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
