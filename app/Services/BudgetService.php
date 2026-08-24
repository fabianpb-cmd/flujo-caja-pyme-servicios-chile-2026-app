<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\SalesDocument;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class BudgetService
{
    public function __construct(
        private readonly HourlyCostService $hourlyCosts,
    ) {
    }

    public function variance(int $companyId, CarbonInterface|string $period, ?int $projectId = null): array
    {
        $period = Carbon::parse($period)->startOfMonth();
        $budget = Budget::query()
            ->forCompany($companyId)
            ->whereDate('period_date', $period->toDateString())
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId), fn ($query) => $query->whereNull('project_id'))
            ->first();

        return $this->buildVariance($companyId, $period, $projectId, $budget);
    }

    public function varianceForBudget(Budget $budget): array
    {
        $period = Carbon::parse($budget->period_date)->startOfMonth();

        return $this->buildVariance($budget->company_id, $period, $budget->project_id, $budget);
    }

    private function buildVariance(int $companyId, CarbonInterface|string $period, ?int $projectId, ?Budget $budget): array
    {
        $period = Carbon::parse($period)->startOfMonth();
        $actual = $this->recognizedActuals($companyId, $period, $projectId);

        $revenueBudget = (float) ($budget?->revenue_budget ?? 0);
        $personnelBudget = (float) ($budget?->personnel_budget ?? 0);
        $otherDirectBudget = (float) ($budget?->other_direct_budget ?? 0);
        $otherIndirectBudget = (float) ($budget?->other_indirect_budget ?? 0);
        $legalBudget = (float) ($budget?->legal_budget ?? 0);
        $otherBudgetTotal = $otherDirectBudget + $otherIndirectBudget;
        $totalBudget = $personnelBudget + $otherBudgetTotal + $legalBudget;
        $totalReal = $actual['personnel_real'] + $actual['other_real'] + $actual['legal_real'];

        return [
            'period' => $period->toDateString(),
            'project_id' => $projectId,
            'revenue_budget' => $revenueBudget,
            'personnel_budget' => $personnelBudget,
            'other_direct_budget' => $otherDirectBudget,
            'other_indirect_budget' => $otherIndirectBudget,
            'other_budget_total' => round($otherBudgetTotal, 2),
            'legal_budget' => $legalBudget,
            'revenue_real' => $actual['revenue_real'],
            'personnel_real' => $actual['personnel_real'],
            'other_real' => $actual['other_real'],
            'legal_real' => $actual['legal_real'],
            'total_budget' => round($totalBudget, 2),
            'total_real' => round($totalReal, 2),
            'revenue_difference' => round($actual['revenue_real'] - $revenueBudget, 2),
            'expense_difference' => round($totalReal - $totalBudget, 2),
            'revenue_difference_pct' => $revenueBudget > 0 ? round(($actual['revenue_real'] - $revenueBudget) / $revenueBudget, 4) : 0.0,
            'expense_difference_pct' => $totalBudget > 0 ? round(($totalReal - $totalBudget) / $totalBudget, 4) : 0.0,
        ];
    }

    private function recognizedActuals(int $companyId, Carbon $period, ?int $projectId): array
    {
        $periodEnd = $period->copy()->endOfMonth();
        $allocation = $this->hourlyCosts->companyProjectAllocation($companyId, $period);

        $revenueReal = (float) SalesDocument::query()
            ->forCompany($companyId)
            ->where('is_voided', false)
            ->whereNotIn('status', ['Borrador', 'Anulado'])
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->whereBetween('issue_date', [$period->toDateString(), $periodEnd->toDateString()])
            ->sum('net_amount');

        $personnelReal = $projectId
            ? (float) data_get($allocation, "projects.{$projectId}.cost", 0.0)
            : (float) collect($allocation['projects'] ?? [])->sum('cost') + (float) ($allocation['unassigned_cost'] ?? 0.0);

        $otherReal = (float) ExpenseDocument::query()
            ->forCompany($companyId)
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->whereBetween('issue_date', [$period->toDateString(), $periodEnd->toDateString()])
            ->get()
            ->sum(fn (ExpenseDocument $expense): float => (float) ($expense->deductible_vat ? $expense->net_amount : $expense->gross_amount));

        $legalReal = $projectId
            ? 0.0
            : (float) LegalObligation::query()
                ->forCompany($companyId)
                ->whereBetween('period_date', [$period->toDateString(), $periodEnd->toDateString()])
                ->sum('estimated_amount');

        return [
            'revenue_real' => round($revenueReal, 2),
            'personnel_real' => round($personnelReal, 2),
            'other_real' => round($otherReal, 2),
            'legal_real' => round($legalReal, 2),
        ];
    }
}
