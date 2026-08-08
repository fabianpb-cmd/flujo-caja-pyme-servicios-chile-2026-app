<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\ExpenseDocument;
use App\Models\PayrollRecord;
use App\Models\Project;
use App\Models\SalesDocument;

class ProfitabilityService
{
    public function __construct(private readonly CompanySettingsService $settings)
    {
    }

    public function byProject(int $companyId): array
    {
        $minimumMargin = (float) $this->settings->get($companyId, 'margin_minimum', '0.15');

        return Project::query()
            ->forCompany($companyId)
            ->with('client')
            ->orderBy('code')
            ->get()
            ->map(function (Project $project) use ($minimumMargin): array {
                $facturado = (float) SalesDocument::query()
                    ->forCompany($project->company_id)
                    ->where('project_id', $project->id)
                    ->where('is_voided', false)
                    ->sum('net_amount');

                $cobrado = (float) CashMovement::query()
                    ->forCompany($project->company_id)
                    ->where('project_id', $project->id)
                    ->where('source_document_type', 'sales_document')
                    ->where('status', 'posted')
                    ->sum('income');

                $personalTotal = (float) PayrollRecord::query()
                    ->forCompany($project->company_id)
                    ->where('project_id', $project->id)
                    ->sum('employer_cost');

                $vacationProvision = (float) PayrollRecord::query()
                    ->forCompany($project->company_id)
                    ->where('project_id', $project->id)
                    ->sum('vacation_provision');

                $costPersonal = round($personalTotal - $vacationProvision, 2);
                $otherCosts = (float) ExpenseDocument::query()
                    ->forCompany($project->company_id)
                    ->where('project_id', $project->id)
                    ->sum('net_amount');

                $hours = (float) \App\Models\TimeEntry::query()
                    ->forCompany($project->company_id)
                    ->where('project_id', $project->id)
                    ->sum('hours_approved');

                $totalCost = round($costPersonal + $vacationProvision + $otherCosts, 2);
                $margin = round((float) $project->sale_net - $totalCost, 2);
                $marginPct = (float) $project->sale_net > 0 ? round($margin / (float) $project->sale_net, 4) : 0.0;

                return [
                    'project_id' => $project->id,
                    'project_code' => $project->code,
                    'project_name' => $project->name,
                    'client_name' => $project->client?->legal_name,
                    'sale' => (float) $project->sale_net,
                    'facturado' => round($facturado, 2),
                    'cobrado' => round($cobrado, 2),
                    'cost_personal' => $costPersonal,
                    'vacation_provision' => round($vacationProvision, 2),
                    'other_costs' => round($otherCosts, 2),
                    'total_cost' => $totalCost,
                    'margin' => $margin,
                    'margin_pct' => $marginPct,
                    'hours' => round($hours, 2),
                    'contracted_rate' => $project->contracted_hourly_rate ? (float) $project->contracted_hourly_rate : null,
                    'effective_rate' => $hours > 0 ? round($facturado / $hours, 2) : null,
                    'hour_cost' => $hours > 0 ? round($totalCost / $hours, 2) : null,
                    'status' => $marginPct < 0 ? 'Pérdida' : ($marginPct < $minimumMargin ? 'Bajo mínimo' : 'OK'),
                ];
            })
            ->all();
    }
}
