@extends('layouts.app')

@section('content')
<div class="page-header"><div><h1 class="page-title">Regularización bancaria</h1><p class="page-subtitle">Clasifica movimientos contabilizados históricos sin alterar su registro financiero original.</p></div></div>

<div class="alert alert-warning app-panel">Los movimientos pre-cutover se clasifican como históricos incluidos en el saldo inicial y no afectan el saldo conciliable. Los post-cutover solo se pueden asignar mientras su período bancario permanezca abierto.</div>

<div class="app-panel p-3 mb-4">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-2"><label class="form-label">Desde</label><input type="date" class="form-control" name="date_from" value="{{ request('date_from') }}"></div>
        <div class="col-md-2"><label class="form-label">Hasta</label><input type="date" class="form-control" name="date_to" value="{{ request('date_to') }}"></div>
        <div class="col-md-2"><label class="form-label">Código</label><input class="form-control" name="code" value="{{ request('code') }}"></div>
        <div class="col-md-2"><label class="form-label">Documento / referencia</label><input class="form-control" name="document" value="{{ request('document') }}"></div>
        <div class="col-md-2"><label class="form-label">Contraparte</label><input class="form-control" name="counterparty" value="{{ request('counterparty') }}"></div>
        <div class="col-md-2"><label class="form-label">Tipo</label><select class="form-select" name="direction"><option value="">Todos</option><option value="income" @selected(request('direction') === 'income')>Ingreso</option><option value="expense" @selected(request('direction') === 'expense')>Egreso</option></select></div>
        <div class="col-md-3"><label class="form-label">Estado</label><select class="form-select" name="regularization_status"><option value="">Todos</option><option value="unregularized" @selected(request('regularization_status') === 'unregularized')>Sin regularizar</option><option value="pre_cutover" @selected(request('regularization_status') === 'pre_cutover')>Pre-cutover</option><option value="post_cutover" @selected(request('regularization_status') === 'post_cutover')>Post-cutover</option><option value="reversed" @selected(request('regularization_status') === 'reversed')>Reversados</option></select></div>
        <div class="col-md-2"><button class="btn btn-outline-primary" type="submit">Filtrar</button></div>
    </form>
</div>

<div class="app-panel section-card mb-4"><div class="section-card-header"><h2 class="section-card-title">Movimientos sin cuenta directa</h2></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Fecha</th><th>Código</th><th>Referencia / documento</th><th>Contraparte</th><th>Proyecto</th><th class="text-end">Ingreso</th><th class="text-end">Egreso</th><th>Estado</th><th>Acción</th></tr></thead><tbody>@forelse ($movements as $movement)<tr><td>{{ \App\Support\UiFormatter::formatDate($movement->movement_date) }}</td><td>{{ $movement->code }}</td><td>{{ $movement->source_document_code ?: ($movement->reference ?: '—') }}</td><td>{{ $movement->counterparty_name ?: '—' }}</td><td>{{ $movement->project?->name ?: '—' }}</td><td class="text-end">{{ \App\Support\UiFormatter::formatMoney($movement->income) }}</td><td class="text-end">{{ \App\Support\UiFormatter::formatMoney($movement->expense) }}</td><td>@if ($movement->activeBankAssignment)<span class="badge text-bg-{{ $movement->activeBankAssignment->classification === 'post_cutover' ? 'success' : 'secondary' }}">{{ $movement->activeBankAssignment->classification === 'post_cutover' ? 'Post-cutover' : 'Pre-cutover / histórico' }}</span><div class="small text-muted">{{ $movement->activeBankAssignment->cashAccount?->name }}</div>@else<span class="badge text-bg-warning">Sin regularizar</span>@endif</td><td>@if (! $movement->activeBankAssignment)<form method="POST" action="{{ route('bank-regularization.assign') }}" class="d-grid gap-1">@csrf<input type="hidden" name="cash_movement_id" value="{{ $movement->id }}"><select class="form-select form-select-sm" name="cash_account_id" required data-regularization-account data-movement-date="{{ $movement->movement_date->toDateString() }}"><option value="">Cuenta CLP...</option>@foreach ($accounts as $account)<option value="{{ $account->id }}" data-cutover="{{ $account->opening_balance_date->toDateString() }}">{{ $account->name }} · {{ $account->code }}</option>@endforeach</select><span class="small text-muted" data-regularization-classification>Seleccione una cuenta.</span><input class="form-control form-control-sm" name="reason" maxlength="2000" placeholder="Motivo obligatorio" required><button class="btn btn-sm btn-primary" type="submit">Asignar</button></form>@else<span class="text-muted small">Historial disponible abajo</span>@endif</td></tr>@empty<tr><td colspan="9" class="text-center text-muted">Sin movimientos para los filtros seleccionados.</td></tr>@endforelse</tbody></table></div>{{ $movements->links() }}</div>

<div class="app-panel section-card"><div class="section-card-header"><h2 class="section-card-title">Historial de regularizaciones</h2></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Movimiento</th><th>Cuenta</th><th>Clasificación</th><th>Asignado</th><th>Motivo</th><th>Estado</th><th>Reversión</th></tr></thead><tbody>@forelse ($history as $assignment)<tr><td>{{ $assignment->cashMovement?->code }}</td><td>{{ $assignment->cashAccount?->name }}</td><td>{{ $assignment->classification === 'post_cutover' ? 'Post-cutover' : 'Pre-cutover / histórico' }}</td><td>{{ $assignment->assignedBy?->name ?: 'Sistema' }}<div class="small text-muted">{{ optional($assignment->assigned_at)->format('d/m/Y H:i') }}</div></td><td>{{ $assignment->reason }}</td><td>{{ $assignment->status }}</td><td>@if ($assignment->status === 'active')<form method="POST" action="{{ route('bank-regularization.reverse', $assignment->id) }}" class="d-flex gap-1">@csrf<input class="form-control form-control-sm" name="reversal_reason" maxlength="2000" placeholder="Motivo reversión" required><button class="btn btn-sm btn-outline-danger" type="submit">Revertir</button></form>@else{{ $assignment->reversal_reason }}<div class="small text-muted">{{ $assignment->reversedBy?->name ?: 'Sistema' }} · {{ optional($assignment->reversed_at)->format('d/m/Y H:i') }}</div>@endif</td></tr>@empty<tr><td colspan="7" class="text-center text-muted">Sin historial de regularizaciones.</td></tr>@endforelse</tbody></table></div></div>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
    document.querySelectorAll('[data-regularization-account]').forEach((select) => {
        const feedback = select.parentElement?.querySelector('[data-regularization-classification]');
        const sync = () => {
            const option = select.options[select.selectedIndex];
            if (!feedback || !option?.dataset.cutover) return;
            feedback.textContent = select.dataset.movementDate <= option.dataset.cutover
                ? 'Pre-cutover / incluido en saldo inicial / no afecta saldo conciliable.'
                : 'Post-cutover / se incorporará al saldo conciliable si el período está abierto.';
        };
        select.addEventListener('change', sync);
    });
</script>
@endpush
