@extends('layouts.app')

@section('content')
<div class="page-header"><div><h1 class="page-title">Conciliación bancaria</h1><p class="page-subtitle">Comparación manual entre saldo bancario y saldo del sistema.</p></div></div>

@if ($unassigned['count'] > 0)
    <div class="alert alert-warning app-panel">Movimientos contabilizados sin cuenta: {{ $unassigned['count'] }} · Ingresos {{ \App\Support\UiFormatter::formatMoney($unassigned['income']) }} · Egresos {{ \App\Support\UiFormatter::formatMoney($unassigned['expense']) }} · Neto {{ \App\Support\UiFormatter::formatMoney($unassigned['net']) }}</div>
@endif

<div class="app-panel p-3 mb-4">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-4"><label class="form-label" for="cash-account">Cuenta CLP</label><select id="cash-account" name="cash_account_id" class="form-select"><option value="">Seleccione</option>@foreach ($accounts as $account)<option value="{{ $account->id }}" @selected($selected?->id === $account->id)>{{ $account->name }} · {{ $account->code }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label" for="reconciliation-date">Fecha conciliación</label><input id="reconciliation-date" type="date" name="reconciliation_date" class="form-control" value="{{ $date }}"></div>
        <div class="col-md-2"><button class="btn btn-outline-primary" type="submit">Calcular</button></div>
    </form>
    <hr>
    <form method="POST" action="{{ route('bank-reconciliation.store') }}" class="row g-3 align-items-end">
        @csrf
        <input type="hidden" name="cash_account_id" value="{{ $selected?->id }}"><input type="hidden" name="reconciliation_date" value="{{ $date }}">
        <div class="col-md-3"><label class="form-label" for="bank-balance">Saldo banco</label><input id="bank-balance" name="bank_balance" class="form-control" inputmode="decimal" required></div>
        <div class="col-md-3"><div class="small text-muted">Saldo sistema</div><div class="fw-semibold amount-cell">{{ $systemBalance === null ? 'No disponible' : \App\Support\UiFormatter::formatMoney($systemBalance) }}</div></div>
        <div class="col-md-3"><label class="form-label" for="reconciliation-notes">Notas</label><input id="reconciliation-notes" name="notes" class="form-control"></div>
        <div class="col-md-3"><button class="btn btn-primary" type="submit" @disabled(! $selected)>Guardar borrador</button></div>
    </form>
</div>

<div class="app-panel section-card mb-4"><div class="section-card-header"><h2 class="section-card-title">Conciliaciones anteriores</h2></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Fecha</th><th>Cuenta</th><th class="text-end">Banco</th><th class="text-end">Sistema</th><th class="text-end">Diferencia</th><th>Estado</th><th>Acción</th></tr></thead><tbody>@forelse ($reconciliations as $item)<tr><td>{{ \App\Support\UiFormatter::formatDate($item->reconciliation_date) }}</td><td>{{ $item->cashAccount?->name }}</td><td class="text-end">{{ \App\Support\UiFormatter::formatMoney($item->bank_balance) }}</td><td class="text-end">{{ \App\Support\UiFormatter::formatMoney($item->system_balance_snapshot) }}</td><td class="text-end">{{ \App\Support\UiFormatter::formatMoney($item->difference) }}</td><td>{{ $item->status }}</td><td>@if ($item->status === 'draft')<form method="POST" action="{{ route('bank-reconciliation.reconcile', $item->id) }}">@csrf<button class="btn btn-sm btn-outline-primary" type="submit">Conciliar</button></form>@else<span class="text-muted">Solo lectura</span>@endif</td></tr>@empty<tr><td colspan="7" class="text-center text-muted">Sin conciliaciones.</td></tr>@endforelse</tbody></table></div></div>

<div class="app-panel section-card"><div class="section-card-header"><h2 class="section-card-title">Movimientos posted de la cuenta</h2></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Fecha</th><th>Código</th><th>Referencia</th><th>Contraparte</th><th class="text-end">Ingreso</th><th class="text-end">Egreso</th></tr></thead><tbody>@forelse ($movements as $movement)<tr><td>{{ \App\Support\UiFormatter::formatDate($movement->movement_date) }}</td><td>{{ $movement->code }}</td><td>{{ $movement->reference ?: '—' }}</td><td>{{ $movement->counterparty_name ?: '—' }}</td><td class="text-end">{{ \App\Support\UiFormatter::formatMoney($movement->income) }}</td><td class="text-end">{{ \App\Support\UiFormatter::formatMoney($movement->expense) }}</td></tr>@empty<tr><td colspan="6" class="text-center text-muted">Sin movimientos posted para la cuenta.</td></tr>@endforelse</tbody></table></div></div>
@endsection
