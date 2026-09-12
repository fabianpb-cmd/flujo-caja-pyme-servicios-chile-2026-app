@extends('layouts.app')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Agenda financiera</h1>
        <div class="page-subtitle">Qué cobrar, qué pagar y qué vence próximamente.</div>
    </div>
</div>

<div class="row g-3 mb-4">
    @foreach ([
        ['Por cobrar vencido', $summary['receivable_overdue']],
        ['Por pagar vencido', $summary['payable_overdue']],
        ['Por cobrar próximos 7 días', $summary['receivable_next_7']],
        ['Por pagar próximos 7 días', $summary['payable_next_7']],
        ['Neto próximos 30 días', $summary['net_next_30']],
    ] as [$label, $amount])
        <div class="col-md-6 col-xl-{{ $loop->last ? '4' : '3' }}">
            <div class="card kpi-card h-100"><div class="card-body">
                <div class="text-muted small">{{ $label }}</div>
                <div class="h4 mb-0">{{ \App\Support\UiFormatter::formatMoney($amount) }}</div>
            </div></div>
        </div>
    @endforeach
</div>

<div class="table-responsive app-panel">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
            <th>Prioridad</th><th>Vencimiento</th><th>Tipo</th><th>Documento</th>
            <th>Contraparte</th><th>Proyecto</th><th>Por cobrar</th><th>Por pagar</th><th>Saldo</th><th>Acción</th>
        </tr></thead>
        <tbody>
        @forelse ($items as $item)
            <tr>
                <td>{{ $item['priority'] }}</td>
                <td>{{ \App\Support\UiFormatter::formatDate($item['due_date']) }}</td>
                <td>{{ $item['type'] }}</td>
                <td>{{ $item['code'] }}</td>
                <td>{{ $item['counterparty'] }}</td>
                <td>{{ $item['project'] ?: '—' }}</td>
                <td class="text-end">{{ $item['direction'] === 'receivable' ? \App\Support\UiFormatter::formatMoney($item['balance']) : '—' }}</td>
                <td class="text-end">{{ $item['direction'] === 'payable' ? \App\Support\UiFormatter::formatMoney($item['balance']) : '—' }}</td>
                <td class="text-end">{{ \App\Support\UiFormatter::formatMoney($item['balance']) }}</td>
                <td><a class="btn btn-sm btn-outline-primary" href="{{ $item['action_url'] }}">Ver</a></td>
            </tr>
        @empty
            <tr><td colspan="10" class="text-center text-muted py-5">No hay vencimientos pendientes en los próximos 30 días.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
