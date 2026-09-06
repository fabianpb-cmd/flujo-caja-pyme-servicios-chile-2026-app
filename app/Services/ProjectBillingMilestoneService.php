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
        $percentage = (float) $data['percentage'];
        $other = ProjectBillingMilestone::query()->where('project_id', $project->id)->when($milestone, fn ($q) => $q->whereKeyNot($milestone->id))->sum('percentage');
        if ($percentage <= 0 || $percentage + (float) $other > 100.00001) throw new DomainException('La suma de porcentajes del plan no puede superar 100%.');
        $payload = ['sequence' => (int) $data['sequence'], 'name' => trim($data['name']), 'planned_invoice_date' => $data['planned_invoice_date'] ?: null, 'percentage' => $percentage, 'notes' => $data['notes'] ?: null];
        if (! $milestone) return MassAssignment::create(ProjectBillingMilestone::class, ['company_id' => $project->company_id, 'project_id' => $project->id] + $payload);
        MassAssignment::fillAndSave($milestone, $payload); return $milestone->refresh();
    }

    public function delete(ProjectBillingMilestone $milestone): void
    {
        if ($this->isInvoiced($milestone)) throw new DomainException('Un hito facturado no se puede eliminar.');
        $milestone->delete();
    }

    public function issue(ProjectBillingMilestone $milestone, string $issueDate, bool $taxable = true): SalesDocument
    {
        $milestone->loadMissing('project.salesCurrency', 'project.client'); $project = $milestone->project;
        $this->assertClosedContract($project);
        if ($this->isInvoiced($milestone)) throw new DomainException('Este hito ya posee una factura activa. Anule esa factura antes de reemitirlo.');
        $issue = Carbon::parse($issueDate); $contractual = $this->contractualAmount($project, $milestone);
        $conversion = $this->toClp($project, $contractual, $issue); $amounts = $this->receivables->amountsWithVat($project->company_id, $conversion['converted_amount'], $issue);
        $coverage = $this->coverage($milestone, $issue);
        return DB::transaction(function () use ($milestone, $project, $issue, $contractual, $conversion, $amounts, $coverage, $taxable): SalesDocument {
            return MassAssignment::create(SalesDocument::class, [
                'company_id' => $project->company_id, 'client_id' => $project->client_id, 'project_id' => $project->id, 'project_billing_milestone_id' => $milestone->id,
                'document_type' => 'Factura hito', 'issue_date' => $issue->toDateString(), 'net_amount' => $amounts['net_amount'], 'vat_rate' => $taxable ? $amounts['vat_rate'] : 0,
                'vat_amount' => $taxable ? $amounts['vat_amount'] : 0, 'gross_amount' => $taxable ? $amounts['gross_amount'] : $amounts['net_amount'], 'collected_amount' => 0,
                'status' => 'Borrador', 'is_voided' => false, 'billing_source' => 'PROJECT_MILESTONE', 'calculation_status' => 'OK',
                'billing_snapshot' => ['source' => 'PROJECT_MILESTONE', 'milestone_id' => $milestone->id, 'sequence' => $milestone->sequence, 'name' => $milestone->name, 'percentage' => (float) $milestone->percentage, 'contractual_amount' => $contractual, 'contractual_currency' => UiFormatter::currencyCode($project->salesCurrency ?: 'CLP'), 'conversion' => $conversion, 'issue_date' => $issue->toDateString(), 'coverage' => $coverage],
                'calculation_notes' => $coverage['warning'] ?? null,
            ]);
        });
    }

    public function coverage(ProjectBillingMilestone $milestone, Carbon $through): array
    {
        $milestone->loadMissing('project.salesCurrency', 'project.billingMilestones.salesDocuments'); $project = $milestone->project;
        $contractualClp = $project->billingMilestones->filter(fn ($m) => $m->sequence <= $milestone->sequence)->sum(fn ($m) => $this->toClp($project, $this->contractualAmount($project, $m), $through)['converted_amount']);
        $cost = TimeEntry::query()->forCompany($project->company_id)->with(['person.hourlyRateCurrency', 'project.salesCurrency', 'assignment.hourlyRateCurrency', 'assignment.assignmentStatus'])->where('project_id', $project->id)->whereDate('entry_date', '<=', $through->toDateString())->where('hours_approved', '>', 0)->get()->filter(fn ($e) => in_array(strtolower((string) ($e->approvalStatus?->code ?: $e->approval_status)), ['approved','aprobado'], true))->sum(fn ($e) => (float) $e->hours_approved * $this->hourlyRates->costingClpForEntry($e));
        $gap = round($contractualClp - $cost, 0); return ['contractual_clp' => round($contractualClp, 0), 'approved_cost_clp' => round($cost, 0), 'gap_clp' => $gap, 'warning' => $gap < 0 ? 'Cobertura temporal insuficiente: la facturación contractual acumulada no cubre todavía el costo acumulado de HH aprobadas. Puede requerir adelantar el pago del consultor hasta hitos futuros.' : null];
    }

    public function contractualAmount(Project $project, ProjectBillingMilestone $milestone): float { return UiFormatter::roundAmount((float) $project->sale_net * (float) $milestone->percentage / 100, $project->salesCurrency ?: 'CLP'); }
    public function isInvoiced(ProjectBillingMilestone $milestone): bool { return $milestone->salesDocuments()->where('is_voided', false)->where('status', '!=', 'Anulado')->exists(); }
    private function assertClosedContract(Project $project): void { if (! str_contains(strtolower((string) ($project->contractType?->name ?: '')), 'cerrado')) throw new DomainException('El plan de hitos solo aplica a contratos Proyecto cerrado.'); }
    private function toClp(Project $project, float $amount, Carbon $date): array { $currency = $project->salesCurrency ?: 'CLP'; $code = UiFormatter::currencyCode($currency); if ($code === 'CLP') return ['converted_amount' => round($amount, 0), 'exchange_rate' => 1, 'conversion_date' => $date->toDateString(), 'currency_code' => 'CLP']; $rate = $code === 'UF' ? $this->legalParameters->ufValue($project->company_id, $date) : $this->legalParameters->exchangeRate($project->company_id, $currency->id, $date); return $this->conversions->convert($amount, $currency, 'CLP', $rate, $date); }
}
