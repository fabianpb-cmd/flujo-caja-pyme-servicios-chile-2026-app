@extends('layouts.app')

@php
    $fmtMoney = fn ($value) => '$'.number_format((float) $value, 0, ',', '.');
@endphp

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Obligaciones</h1>
        <div class="page-subtitle">IVA, retenciones, PPM, cotizaciones y otras obligaciones derivadas.</div>
    </div>
    <form method="GET" class="d-flex gap-2">
        <input class="form-control" type="month" name="period" value="{{ $period->format('Y-m') }}">
        <button class="btn btn-outline-secondary" type="submit">Actualizar</button>
    </form>
</div>

<div class="table-responsive app-panel">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
        <tr>
            <th>Código</th>
            <th>Tipo</th>
            <th>Período</th>
            <th>Vencimiento</th>
            <th class="text-end">Estimado</th>
            <th class="text-end">Pagado</th>
            <th class="text-end">Pendiente</th>
            <th>Estado</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row->code }}</td>
                <td>{{ $row->obligation_type }}</td>
                <td>{{ $row->period_date?->format('m-Y') }}</td>
                <td>{{ $row->due_date?->format('d-m-Y') }}</td>
                <td class="text-end">{{ $fmtMoney($row->estimated_amount) }}</td>
                <td class="text-end">{{ $fmtMoney($row->paid_amount) }}</td>
                <td class="text-end">{{ $fmtMoney($row->pending_amount) }}</td>
                <td>
                    <x-status-badge :status="$row->status" />
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted py-5">Sin obligaciones generadas.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $rows->links() }}</div>
@endsection
