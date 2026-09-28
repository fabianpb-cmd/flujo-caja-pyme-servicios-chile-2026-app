@php
    $projectContractTypes = collect($options['contract_type_id'] ?? []);
    $projectSelectedContract = old('contract_type_id', $item->contract_type_id ?? null);
    $projectSelectedContractData = $projectSelectedContract ? ($projectContractTypes[$projectSelectedContract] ?? null) : null;
    $projectContractCode = strtoupper((string) data_get($projectSelectedContractData, 'code'));
    $projectIsClosed = $projectContractCode === 'PROYECTO_CERRADO' || mb_strtolower((string) data_get($projectSelectedContractData, 'label')) === 'proyecto cerrado';
    $projectIsHoursBank = $projectContractCode === 'BOLSA_HORAS';
    $projectIsMonthlyRecurring = $projectContractCode === 'MENSUAL_RECURRENTE';
    $projectBillingPlanReadOnly = $projectBillingPlanReadOnly ?? false;
    $projectOcMilestones = collect(data_get($state ?? [], 'extracted.billing_milestones', []))->map(fn ($m) => ['sequence' => $m['sequence'] ?? null, 'name' => $m['name'] ?? '', 'planned_invoice_date' => $m['planned_invoice_date'] ?? '', 'percentage' => $m['percentage'] ?? '', 'notes' => ($m['source'] ?? 'SUGGESTED') === 'EXPLICIT' ? 'Extraído de OC' : 'Propuesto durante análisis de OC', 'source' => $m['source'] ?? 'SUGGESTED', 'is_billed' => false])->all();
    $projectMilestoneRows = old('billing_milestones', $item->exists ? $item->billingMilestones->map(fn ($m) => ['id' => $m->id, 'sequence' => $m->sequence, 'name' => $m->name, 'planned_invoice_date' => optional($m->planned_invoice_date)->toDateString(), 'percentage' => $m->percentage, 'notes' => $m->notes, 'source' => null, 'is_billed' => $m->salesDocuments->contains(fn ($document) => ! $document->is_voided && $document->status !== 'Anulado')])->all() : ($projectOcMilestones !== [] ? $projectOcMilestones : [['sequence' => 1, 'name' => '', 'planned_invoice_date' => '', 'percentage' => '', 'notes' => '', 'source' => null, 'is_billed' => false]]));
    $projectMilestoneTotal = $projectBillingPlanReadOnly ? (float) $item->billingMilestones->sum('percentage') : collect($projectMilestoneRows)->sum(fn ($row) => is_numeric($row['percentage'] ?? null) ? (float) $row['percentage'] : 0);
@endphp
<div class="app-panel p-3 mb-4" data-project-billing-plan data-closed="{{ $projectIsClosed ? '1' : '0' }}">
    <div class="section-title mb-2">PLAN DE FACTURACIÓN</div>
    <div data-project-billing-hourly class="small text-muted {{ $projectIsClosed || $projectIsHoursBank || $projectIsMonthlyRecurring ? 'd-none' : '' }}">Modalidad: Facturación por HH aprobadas · Sin bolsa contractual.</div>
    <div data-project-billing-hours-bank class="small text-muted {{ $projectIsHoursBank ? '' : 'd-none' }}">Modalidad: Bolsa total consumible. Se factura por HH aprobadas y la bolsa no se reinicia mensualmente.</div>
    <div data-project-billing-monthly class="small text-muted {{ $projectIsMonthlyRecurring ? '' : 'd-none' }}">Modalidad: Bolsa mensual consumible. Se factura por HH aprobadas del período y no acumula saldo.</div>
    <div data-project-billing-unsupported class="alert alert-warning py-2 {{ $projectIsClosed || $projectIsHoursBank || $projectIsMonthlyRecurring || $projectSelectedContract ? 'd-none' : '' }}">Este tipo de contrato no tiene una estrategia de facturación configurada.</div>
    <div data-project-billing-milestones class="{{ $projectIsClosed ? '' : 'd-none' }}">
        @if ($projectBillingPlanReadOnly)
            <div class="small text-muted mb-2">Todos los hitos tienen factura activa. El plan es solo lectura.</div>
            @foreach ($item->billingMilestones->sortBy('sequence') as $milestone)
                <div class="row g-2 mb-2"><div class="col-1">{{ $milestone->sequence }}</div><div class="col-3">{{ $milestone->name }}</div><div class="col-3">{{ $milestone->planned_invoice_date ? \App\Support\UiFormatter::formatDate($milestone->planned_invoice_date) : 'Sin fecha prevista' }}</div><div class="col-2">{{ rtrim(rtrim(number_format((float) $milestone->percentage, 2, '.', ''), '0'), '.') }} %</div><div class="col-3">Facturado</div></div>
            @endforeach
        @else
            @foreach ($projectMilestoneRows as $index => $row)
                <div class="row g-2 mb-2" data-project-milestone-row>
                    @if (!empty($row['id']))<input type="hidden" name="billing_milestones[{{ $index }}][id]" value="{{ $row['id'] }}">@endif
                    <div class="col-1"><input required class="form-control" name="billing_milestones[{{ $index }}][sequence]" type="number" min="1" value="{{ $row['sequence'] ?? '' }}" placeholder="#"></div>
                    <div class="col-3"><input required class="form-control" name="billing_milestones[{{ $index }}][name]" value="{{ $row['name'] ?? '' }}" placeholder="Nombre del hito">@if (($row['source'] ?? null) === 'EXPLICIT')<span class="badge text-bg-secondary">En OC</span>@elseif (($row['source'] ?? null) === 'SUGGESTED')<span class="badge text-bg-info">Propuesto por IA</span>@endif</div>
                    <div class="col-3"><input class="form-control" name="billing_milestones[{{ $index }}][planned_invoice_date]" type="date" value="{{ $row['planned_invoice_date'] ?? '' }}"></div>
                    <div class="col-2"><input required class="form-control" name="billing_milestones[{{ $index }}][percentage]" type="number" min="0.01" max="100" step="0.01" value="{{ $row['percentage'] ?? '' }}" placeholder="%"></div>
                    <div class="col-3 d-flex gap-2"><input class="form-control" name="billing_milestones[{{ $index }}][notes]" value="{{ $row['notes'] ?? '' }}" placeholder="Notas"><button type="button" class="btn btn-outline-danger" data-project-billing-remove>Eliminar</button></div>
                </div>
            @endforeach
            <template data-project-billing-milestone-template><div class="row g-2 mb-2" data-project-milestone-row><div class="col-1"><input required class="form-control" name="billing_milestones[0][sequence]" type="number" min="1" placeholder="#"></div><div class="col-3"><input required class="form-control" name="billing_milestones[0][name]" placeholder="Nombre del hito"></div><div class="col-3"><input class="form-control" name="billing_milestones[0][planned_invoice_date]" type="date"></div><div class="col-2"><input required class="form-control" name="billing_milestones[0][percentage]" type="number" min="0.01" max="100" step="0.01" placeholder="%"></div><div class="col-3 d-flex gap-2"><input class="form-control" name="billing_milestones[0][notes]" placeholder="Notas"><button type="button" class="btn btn-outline-danger" data-project-billing-remove>Eliminar</button></div></div></template>
            <button type="button" class="btn btn-outline-secondary btn-sm mt-2" data-project-billing-add>Agregar hito</button>
        @endif
        <div class="small text-muted">Total programado: <span data-project-billing-total>{{ rtrim(rtrim(number_format($projectMilestoneTotal, 2, '.', ''), '0'), '.') }}</span>% · Pendiente por programar: <span data-project-billing-remaining>{{ rtrim(rtrim(number_format(max(0, 100 - $projectMilestoneTotal), 2, '.', ''), '0'), '.') }}</span>%</div>
        @if ($projectOcMilestones !== [])<div class="small text-muted mt-1">Los hitos propuestos son una sugerencia y deben ser revisados antes de crear el proyecto.</div>@endif
        <div class="alert alert-warning py-2 mt-2 d-none" data-project-billing-warning role="alert"></div>
        @error('project_billing_plan')<div class="alert alert-danger py-2 mt-2 mb-0">{{ $message }}</div>@enderror
    </div>
</div>
@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(()=>{const p=document.querySelector('[data-project-billing-plan]'),s=document.getElementById('contract_type_id');if(!p||!s)return;const m=p.querySelector('[data-project-billing-milestones]'),f=p.closest('form'),t=p.querySelector('[data-project-billing-total]'),r=p.querySelector('[data-project-billing-remaining]'),w=p.querySelector('[data-project-billing-warning]'),sync=()=>{const sum=[...(m?.querySelectorAll('input[name$="[percentage]"]')||[])].reduce((a,i)=>a+(parseFloat(i.value)||0),0),x=Math.round(sum*100)/100;t.textContent=x;r.textContent=Math.max(0,100-x);w.classList.toggle('d-none',x<=100);w.textContent=x>100?`La suma de hitos supera el 100% en ${x-100} %.`:'';const o=s.options[s.selectedIndex],c=(o?.dataset?.code||'').toUpperCase(),l=(o?.textContent||'').trim().toLowerCase(),closed=c==='PROYECTO_CERRADO'||l==='proyecto cerrado';p.dataset.closed=closed?'1':'0';m?.classList.toggle('d-none',!closed);m?.querySelectorAll('input').forEach(i=>i.disabled=!closed);};m?.addEventListener('input',sync);s.addEventListener('change',sync);sync();const a=p.querySelector('[data-project-billing-add]'),q=p.querySelector('template');if(a&&q){a.addEventListener('click',()=>{const row=q.content.firstElementChild.cloneNode(true),n=m.querySelectorAll('[data-project-milestone-row]').length;row.querySelectorAll('[name]').forEach(i=>i.name=i.name.replace('[0]',`[${n}]`));m.insertBefore(row,q);row.querySelector('[data-project-billing-remove]').onclick=()=>{if(m.querySelectorAll('[data-project-milestone-row]').length>1){row.remove();sync();}};});m.querySelectorAll('[data-project-billing-remove]').forEach(b=>b.onclick=()=>{const row=b.closest('[data-project-milestone-row]');if(m.querySelectorAll('[data-project-milestone-row]').length>1){row.remove();sync();}});}})();
</script>
@endpush
