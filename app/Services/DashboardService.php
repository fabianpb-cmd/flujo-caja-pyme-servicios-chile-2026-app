<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Client;
use App\Models\LegalObligation;
use App\Models\PayrollRecord;
use App\Models\SalesDocument;
use Illuminate\Support\Carbon;

class DashboardService
{
    public function __construct(
        private readonly CashFlowService $cashFlow,
        private readonly ReceivablesService $receivables,
        private readonly PayablesService $payables,
        private readonly ProfitabilityService $profitability,
        private readonly ScenarioService $scenarios,
    ) {
    }

    public function data(int $companyId, ?string $scenarioCode = null): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $flows = $this->cashFlow->monthly($companyId, $monthStart, 12, $scenarioCode);
        $profitability = collect($this->profitability->byProject($companyId));
        $kpiMonth = $flows[0];

        $concentration = Client::query()
            ->forCompany($companyId)
            ->get()
            ->map(function (Client $client) use ($companyId): array {
                $amount = (float) SalesDocument::query()
                    ->forCompany($companyId)
                    ->where('client_id', $client->id)
                    ->where('is_voided', false)
                    ->sum('net_amount');

                return ['client' => $client->legal_name, 'amount' => round($amount, 2)];
            })
            ->filter(fn (array $row): bool => $row['amount'] > 0)
            ->sortByDesc('amount')
            ->values();

        $totalFacturado = $concentration->sum('amount');
        $topClientShare = $totalFacturado > 0 && $concentration->isNotEmpty()
            ? round($concentration->first()['amount'] / $totalFacturado, 4)
            : 0.0;

        return [
            'scenario' => $this->scenarios->activeForCompany($companyId, $scenarioCode),
            'kpis' => [
                'cash_available' => $kpiMonth['closing_real'],
                'income_month' => $kpiMonth['income_real'],
                'expense_month' => round($kpiMonth['other_real'] + $kpiMonth['personnel_real'] + $kpiMonth['legal_real'], 2),
                'net_flow' => $kpiMonth['net_real'],
                'receivables' => $this->receivables->accountsReceivable($companyId, $monthEnd),
                'payables' => $this->payables->accountsPayable($companyId, $monthEnd),
                'overdue_invoices' => SalesDocument::query()->forCompany($companyId)->where('status', 'Vencido')->count(),
                'upcoming_obligations' => LegalObligation::query()->forCompany($companyId)->whereIn('status', ['Pendiente', 'Parcial'])->whereBetween('due_date', [now()->toDateString(), now()->addDays(30)->toDateString()])->count(),
                'personnel_cost' => (float) PayrollRecord::query()->forCompany($companyId)->whereBetween('period_date', [$monthStart, $monthEnd])->sum('employer_cost'),
                'margin' => round((float) $profitability->sum('margin'), 2),
                'projection_3m' => $flows[2]['closing_projected'] ?? null,
                'projection_6m' => $flows[5]['closing_projected'] ?? null,
                'projection_12m' => $flows[11]['closing_projected'] ?? null,
                'low_margin_projects' => $profitability->where('status', 'Bajo mínimo')->count() + $profitability->where('status', 'Pérdida')->count(),
                'client_concentration' => $topClientShare,
            ],
            'flows' => $flows,
            'profitability' => $profitability->all(),
            'concentration' => $concentration->all(),
            'movement_alerts' => CashMovement::query()->forCompany($companyId)->where('status', 'voided')->count(),
        ];
    }
}
