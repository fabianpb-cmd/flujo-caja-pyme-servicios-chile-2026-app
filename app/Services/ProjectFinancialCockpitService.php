<?php

namespace App\Services;

use App\Models\Project;
use App\Models\SalesDocument;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ProjectFinancialCockpitService
{
    public function __construct(
        private readonly ProfitabilityService $profitability,
        private readonly ReceivablesService $receivables,
        private readonly BillingStrategyService $billingStrategies,
        private readonly ProjectBillingMilestoneService $milestones,
    ) {
    }

    public function summarize(Project $project, CarbonInterface|string|null $asOf = null): array
    {
        $project->loadMissing(['salesCurrency', 'contractType', 'client']);
        $asOf = $asOf ? Carbon::parse($asOf)->endOfDay() : now()->endOfDay();
        $profitability = collect($this->profitability->byProject($project->company_id, ['project_id' => $project->id]))->first() ?? $this->emptyProfitability($project);
        $commitment = $this->commitmentFromProfitability($profitability);
        $strategy = $this->billingStrategies->forProject($project);

        $documents = SalesDocument::query()
            ->forCompany($project->company_id)
            ->where('project_id', $project->id)
            ->where('is_voided', false)
            ->whereNotIn('status', ['Borrador', 'Anulado'])
            ->whereDate('issue_date', '<=', $asOf->toDateString())
            ->orderBy('due_date')
            ->get();
        $balances = $this->receivables->balancesForDocuments($documents, $asOf);
        $receivableBalance = round((float) collect($balances)->sum(), 2);
        $documentGross = round((float) $documents->sum('gross_amount'), 2);
        $documentCollected = round(max(0, $documentGross - $receivableBalance), 2);

        $billing = $strategy === BillingStrategyService::CLOSED_PROJECT
            ? $this->milestoneSummary($project)
            : null;
        $invoiceAdvance = $this->invoiceAdvance($profitability, $commitment, $billing);
        $collectionAdvance = $documentGross > 0 ? round($documentCollected / $documentGross, 4) : null;
        $status = $this->financialStatus($profitability, $receivableBalance, $documentCollected, $documents->isNotEmpty(), $invoiceAdvance);
        $alerts = $this->alerts($profitability, $commitment, $documents, $balances, $billing, $asOf);

        return [
            'profitability' => $profitability,
            'commitment' => $commitment,
            'strategy' => $strategy,
            'receivable_balance' => $receivableBalance,
            'receivable_document_gross' => $documentGross,
            'receivable_collected' => $documentCollected,
            'invoice_advance' => $invoiceAdvance,
            'collection_advance' => $collectionAdvance,
            'financial_status' => $status,
            'billing' => $billing,
            'alerts' => $alerts,
            'events' => $this->events($documents, $balances, $billing),
        ];
    }

    private function milestoneSummary(Project $project): array
    {
        $plan = $this->milestones->plan($project);
        $rows = collect($plan['milestones']);
        $invoiced = $rows->filter(fn (array $row): bool => $row['invoiced']);
        $pending = $rows->reject(fn (array $row): bool => $row['invoiced'])->sortBy(fn (array $row) => $row['model']->planned_invoice_date?->toDateString() ?? '9999-12-31');

        return [
            'total' => $rows->count(),
            'invoiced' => $invoiced->count(),
            'pending' => $pending->count(),
            'scheduled_percentage' => (float) $plan['scheduled_percentage'],
            'invoiced_percentage' => round((float) $invoiced->sum(fn (array $row) => (float) $row['model']->percentage), 4),
            'next' => $pending->first(),
        ];
    }

    private function invoiceAdvance(array $profitability, array $commitment, ?array $billing): ?float
    {
        if ($billing !== null && $billing['scheduled_percentage'] > 0) {
            return round($billing['invoiced_percentage'] / $billing['scheduled_percentage'], 4);
        }

        if (($commitment['sale_net_clp'] ?? null) !== null && (float) $commitment['sale_net_clp'] > 0) {
            return round(min(1, (float) $profitability['facturado'] / (float) $commitment['sale_net_clp']), 4);
        }

        return null;
    }

    private function financialStatus(array $profitability, float $balance, float $documentCollected, bool $hasDocuments, ?float $invoiceAdvance): string
    {
        if ((float) $profitability['facturado'] <= 0 || ! $hasDocuments) {
            return 'SIN FACTURAR';
        }
        if ($balance <= 0.00001) {
            return 'COBRADO';
        }
        if ($documentCollected > 0) {
            return 'PARCIALMENTE COBRADO';
        }
        if ($invoiceAdvance !== null && $invoiceAdvance < 0.99999) {
            return 'PARCIALMENTE FACTURADO';
        }

        return 'FACTURADO';
    }

    private function alerts(array $profitability, array $commitment, $documents, array $balances, ?array $billing, Carbon $asOf): array
    {
        $alerts = array_merge($profitability['alerts'] ?? [], $commitment['warnings'] ?? []);
        if ($documents->contains(fn (SalesDocument $document): bool => ($balances[$document->id] ?? 0) > 0 && $document->due_date && $document->due_date->lt($asOf->copy()->startOfDay()))) {
            $alerts[] = 'Documentos vencidos por cobrar';
        }
        $nextMilestone = $billing['next']['model'] ?? null;
        if ($nextMilestone?->planned_invoice_date && $nextMilestone->planned_invoice_date->lt($asOf->copy()->startOfDay())) {
            $alerts[] = 'Hito de facturación vencido pendiente';
        }

        return array_values(array_unique($alerts));
    }

    private function events($documents, array $balances, ?array $billing): array
    {
        $events = collect();
        if ($billing && ($next = $billing['next'])) {
            $events->push([
                'date' => $next['model']->planned_invoice_date,
                'type' => 'Hito pendiente',
                'label' => $next['model']->sequence.'. '.$next['model']->name,
                'amount' => $next['amount'],
            ]);
        }
        foreach ($documents as $document) {
            if (($balances[$document->id] ?? 0) > 0) {
                $events->push([
                    'date' => $document->due_date,
                    'type' => 'Cobro pendiente',
                    'label' => $document->code.(filled($document->document_number) ? ' · N° '.$document->document_number : ''),
                    'amount' => $balances[$document->id],
                ]);
            }
        }

        return $events->sortBy(fn (array $event) => $event['date']?->toDateString() ?? '9999-12-31')->take(5)->values()->all();
    }

    private function emptyProfitability(Project $project): array
    {
        return [
            'project_id' => $project->id, 'sale' => 0.0, 'facturado' => 0.0, 'cobrado' => 0.0,
            'cost_personal' => 0.0, 'vacation_provision' => 0.0, 'other_costs' => 0.0, 'total_cost' => 0.0,
            'margin' => 0.0, 'margin_pct' => 0.0, 'hours_worked' => 0.0, 'hours' => 0.0,
            'hours_billed' => 0.0, 'hours_pending' => 0.0, 'contracted_rate' => null,
            'effective_rate' => null, 'hour_cost' => null, 'hour_margin' => null, 'alerts' => [],
        ];
    }

    private function commitmentFromProfitability(array $profitability): array
    {
        return [
            'sale_net_clp' => $profitability['projected_personnel_sale'] ?? null,
            'sale_net_contractual' => $profitability['projected_personnel_sale_contractual'] ?? null,
            'sale_net_currency_code' => $profitability['projected_personnel_sale_currency_code'] ?? 'CLP',
            'sale_net_currency_symbol' => $profitability['projected_personnel_sale_currency_symbol'] ?? '$',
            'sale_net_currency_minor_units' => $profitability['projected_personnel_sale_currency_minor_units'] ?? 0,
            'personnel_committed_cost' => $profitability['personnel_committed_cost'] ?? null,
            'projected_personnel_margin' => $profitability['projected_personnel_margin'] ?? null,
            'committed_percentage' => $profitability['committed_percentage'] ?? null,
            'calculation_complete' => $profitability['commitment_calculation_complete'] ?? false,
            'warnings' => $profitability['commitment_warnings'] ?? [],
            'exchange_rate_note' => $profitability['commitment_exchange_rate_note'] ?? null,
            'negative_margin' => $profitability['commitment_negative_margin'] ?? false,
        ];
    }
}
