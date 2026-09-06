<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\SalesDocument;
use App\Models\TimeEntry;
use App\Support\MassAssignment;
use App\Support\UiFormatter;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectBillingMilestoneService
{
    public function __construct(
        private readonly LegalParameterService $legalParameters,
        private readonly CurrencyConversionService $conversions,
        private readonly ReceivablesService $receivables,
        private readonly HourlyRateService $hourlyRates,
    ) {}

    public function plan(Project $project): array
    {
        $project->loadMissing(['salesCurrency', 'billingMilestones.salesDocuments']);
        $milestones = $project->billingMilestones->map(function (ProjectBillingMilestone $milestone) use ($project): array {
            $active = $milestone->salesDocuments->first(fn (SalesDocument $document) => ! $document->is_voided && $document->status !== 'Anulado');
            return [
                'model' => $milestone,
                'amount' => $this->contractualAmount($project, $milestone),
                'active_document' => $active,
                'invoiced' => $active !== null,
            ];
        });
        return ['milestones' => $milestones, 'scheduled_percentage' => round((float) $milestones->sum(fn ($row) => $row['model']->percentage), 4), 'remaining_percentage' => max(0, round(100 - (float) $milestones->sum(fn ($row) => $row['model']->percentage), 4))];
    }

    public function save(Project $project, array $data, ?ProjectBillingMilestone $milestone = null): ProjectBillingMilestone
    {
        $this->assertClosedContract($project);
        if ($milestone && $this->isInvoiced($milestone)) throw new DomainException('Un hito facturado no se puede editar.');
        $sequence = (int) $data['sequence'];
        if (ProjectBillingMilestone::query()->where('project_id', $project->id)->where('sequence', $sequence)->when($milestone, fn ($q) => $q->whereKeyNot($milestone->id))->exists()) {
            throw new DomainException('La secuencia del hito debe ser única dentro del proyecto.');
        }
        $percentage = (float) $data['percentage'];
        $other = ProjectBillingMilestone::query()->where('project_id', $project->id)->when($milestone, fn ($q) => $q->whereKeyNot($milestone->id))->sum('percentage');
        if ($percentage <= 0 || $percentage + (float) $other > 100.00001) throw new DomainException('La suma de porcentajes del plan no puede superar 100%.');
        $payload = ['sequence' => $sequence, 'name' => trim($data['name']), 'planned_invoice_date' => $data['planned_invoice_date'] ?? null, 'percentage' => $percentage, 'notes' => $data['notes'] ?? null];
        if (! $milestone) return MassAssignment::create(ProjectBillingMilestone::class, ['company_id' => $project->company_id, 'project_id' => $project->id] + $payload);
        MassAssignment::fillAndSave($milestone, $payload); return $milestone->refresh();
    }

    public function syncPlan(Project $project, array $rows): void
    {
        $existing = $project->billingMilestones()->get()->keyBy('id');
        $ids = collect($rows)->pluck('id')->filter()->map(fn ($id) => (int) $id)->toArray();
        foreach ($rows as $row) {
            $milestone = isset($row['id']) ? $existing[(int) $row['id']] ?? null : null;
            if (isset($row['id']) && ! $milestone) throw new DomainException('El hito indicado no pertenece al proyecto o empresa.');
            if ($milestone && $this->isInvoiced($milestone)) {
                $unchanged = collect(['sequence', 'name', 'planned_invoice_date', 'percentage', 'notes'])->every(fn ($field) => (string) ($milestone->{$field} ?? '') === (string) ($row[$field] ?? ''));
                if (! $unchanged) throw new DomainException('Un hito facturado no se puede editar.');
            }
        }
        foreach ($rows as $row) {
            $milestone = isset($row['id']) ? $existing[(int) $row['id']] ?? null : null;
            $payload = ['sequence' => (int) $row['sequence'], 'name' => trim($row['name']), 'planned_invoice_date' => $row['planned_invoice_date'] ?? null, 'percentage' => (float) $row['percentage'], 'notes' => $row['notes'] ?? null];
            if ($milestone) MassAssignment::fillAndSave($milestone, $payload); else MassAssignment::create(ProjectBillingMilestone::class, ['company_id' => $project->company_id, 'project_id' => $project->id] + $payload);
        }
        foreach ($existing as $milestone) if (! in_array($milestone->id, $ids, true)) { if ($this->isInvoiced($milestone)) throw new DomainException('Un hito facturado no se puede eliminar.'); $milestone->delete(); }
    }

    public function delete(ProjectBillingMilestone $milestone): void
    {
        if ($this->isInvoiced($milestone)) throw new DomainException('Un hito facturado no se puede eliminar.');
        $milestone->delete();
    }

    public function issue(ProjectBillingMilestone $milestone, string $issueDate, bool $taxable = true): SalesDocument
    {
        return DB::transaction(function () use ($milestone, $issueDate, $taxable): SalesDocument {
            $locked = ProjectBillingMilestone::query()->whereKey($milestone->getKey())->where('company_id', $milestone->company_id)->lockForUpdate()->firstOrFail();
            $locked->load(['project.salesCurrency', 'project.client', 'project.contractType']);
            $project = $locked->project;
            $this->assertClosedContract($project);
            if ($this->isInvoiced($locked)) throw new DomainException('Este hito ya posee una factura activa. Anule esa factura antes de reemitirlo.');
            $issue = Carbon::parse($issueDate)->startOfDay();
            if ($issue->gt(Carbon::today())) throw new DomainException('La fecha de emisión no puede ser futura.');
            $contractual = $this->contractualAmount($project, $locked);
            $conversion = $this->toClp($project, $contractual, $issue); $amounts = $this->receivables->amountsWithVat($project->company_id, $conversion['converted_amount'], $issue);
            $coverage = $this->coverage($locked, $issue);
            $dueDate = $this->dueDate($project, $issue);
            return MassAssignment::create(SalesDocument::class, [
                'company_id' => $project->company_id, 'client_id' => $project->client_id, 'project_id' => $project->id, 'project_billing_milestone_id' => $locked->id,
                'document_type' => 'Factura hito', 'issue_date' => $issue->toDateString(), 'net_amount' => $amounts['net_amount'], 'vat_rate' => $taxable ? $amounts['vat_rate'] : 0,
                'due_date' => $dueDate?->toDateString(), 'projected_collection_date' => $dueDate?->toDateString(),
                'vat_amount' => $taxable ? $amounts['vat_amount'] : 0, 'gross_amount' => $taxable ? $amounts['gross_amount'] : $amounts['net_amount'], 'collected_amount' => 0,
                'status' => 'Borrador', 'is_voided' => false, 'billing_source' => 'PROJECT_MILESTONE', 'calculation_status' => 'OK',
                'billing_snapshot' => ['source' => 'PROJECT_MILESTONE', 'milestone_id' => $locked->id, 'sequence' => $locked->sequence, 'name' => $locked->name, 'percentage' => (float) $locked->percentage, 'contractual_amount' => $contractual, 'contractual_currency' => UiFormatter::currencyCode($project->salesCurrency ?: 'CLP'), 'conversion' => $conversion, 'issue_date' => $issue->toDateString(), 'coverage' => $coverage],
                'calculation_notes' => $coverage['warning'] ?? null,
            ]);
        });
    }

    public function coverage(ProjectBillingMilestone $milestone, Carbon $through): array
    {
        $milestone->loadMissing('project.salesCurrency'); $project = $milestone->project;
        $contractualClp = (float) $this->toClp($project, $this->contractualAmount($project, $milestone), $through)['converted_amount'];
        $contractualClp += SalesDocument::query()
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->whereNotNull('project_billing_milestone_id')
            ->where('project_billing_milestone_id', '!=', $milestone->id)
            ->where('is_voided', false)
            ->where('status', '!=', 'Anulado')
            ->whereDate('issue_date', '<=', $through->toDateString())
            ->whereHas('billingMilestone', fn ($query) => $query->where('project_id', $project->id))
            ->sum('net_amount');
        $cost = TimeEntry::query()->forCompany($project->company_id)->with(['person.hourlyRateCurrency', 'project.salesCurrency', 'assignment.hourlyRateCurrency', 'assignment.assignmentStatus'])->where('project_id', $project->id)->whereDate('entry_date', '<=', $through->toDateString())->where('hours_approved', '>', 0)->get()->filter(fn ($e) => in_array(strtolower((string) ($e->approvalStatus?->code ?: $e->approval_status)), ['approved','aprobado'], true))->sum(fn ($e) => (float) $e->hours_approved * $this->hourlyRates->costingClpForEntry($e));
        $gap = round($contractualClp - $cost, 0); return ['contractual_clp' => round($contractualClp, 0), 'approved_cost_clp' => round($cost, 0), 'gap_clp' => $gap, 'warning' => $gap < 0 ? 'Cobertura temporal insuficiente: facturación acumulada '.UiFormatter::formatMoney($contractualClp).' frente a costo HH aprobado acumulado '.UiFormatter::formatMoney($cost).', brecha '.UiFormatter::formatMoney(abs($gap)).'. Puede requerir financiar temporalmente al consultor hasta hitos futuros; este warning no bloquea.' : null];
    }

    public function contractualAmount(Project $project, ProjectBillingMilestone $milestone): float { return UiFormatter::roundAmount((float) $project->sale_net * (float) $milestone->percentage / 100, $project->salesCurrency ?: 'CLP'); }
    public function isInvoiced(ProjectBillingMilestone $milestone): bool { return $milestone->salesDocuments()->where('is_voided', false)->where('status', '!=', 'Anulado')->exists(); }
    private function dueDate(Project $project, Carbon $issue): ?Carbon
    {
        $term = $project->paymentTerm ?: $project->client?->paymentTerm;
        return $term && $term->days !== null ? $issue->copy()->addDays((int) $term->days) : null;
    }
    public function isClosedContract(Project $project): bool { return strcasecmp(trim((string) ($project->contractType?->name ?: '')), 'Proyecto cerrado') === 0; }
    private function assertClosedContract(Project $project): void { if (! $this->isClosedContract($project)) throw new DomainException('El plan de hitos solo aplica a contratos Proyecto cerrado.'); }
    private function toClp(Project $project, float $amount, Carbon $date): array { $currency = $project->salesCurrency ?: 'CLP'; $code = UiFormatter::currencyCode($currency); if ($code === 'CLP') return ['converted_amount' => round($amount, 0), 'exchange_rate' => 1, 'conversion_date' => $date->toDateString(), 'currency_code' => 'CLP']; $rate = $code === 'UF' ? $this->legalParameters->ufValue($project->company_id, $date) : $this->legalParameters->exchangeRate($project->company_id, $currency->id, $date); return $this->conversions->convert($amount, $currency, 'CLP', $rate, $date); }
}
