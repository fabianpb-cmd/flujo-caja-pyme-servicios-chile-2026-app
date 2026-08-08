<?php

namespace App\Services;

use App\Models\Budget;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class BudgetService
{
    public function __construct(
        private readonly CashFlowService $cashFlow,
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

        $actual = $this->cashFlow->actualsForBudget($companyId, $period, $projectId);

        $revenueBudget = (float) ($budget?->revenue_budget ?? 0);
        $personnelBudget = (float) ($budget?->personnel_budget ?? 0);
        $otherDirectBudget = (float) ($budget?->other_direct_budget ?? 0);
        $legalBudget = (float) ($budget?->legal_budget ?? 0);
        $totalBudget = $personnelBudget + $otherDirectBudget + $legalBudget;
        $totalReal = $actual['personnel_real'] + $actual['other_real'] + $actual['legal_real'];

        return [
            'period' => $period->toDateString(),
            'project_id' => $projectId,
            'revenue_budget' => $revenueBudget,
            'personnel_budget' => $personnelBudget,
            'other_direct_budget' => $otherDirectBudget,
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
}
