<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\PayrollRecord;
use App\Models\SalesDocument;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class CashFlowService
{
    public function __construct(
        private readonly ReceivablesService $receivables,
        private readonly PayablesService $payables,
        private readonly ScenarioService $scenarios,
    ) {
    }

    public function monthly(int $companyId, CarbonInterface|string $start, int $months = 12, ?string $scenarioCode = null): array
    {
        $scenario = $this->scenarios->activeForCompany($companyId, $scenarioCode);
        $cursor = Carbon::parse($start)->startOfMonth();
        $openingReal = $this->openingBalance($companyId, $cursor);
        $openingProjected = $openingReal;
        $rows = [];

        for ($i = 0; $i < $months; $i++) {
            $periodStart = $cursor->copy()->addMonths($i);
            $periodEnd = $periodStart->copy()->endOfMonth();

            $reals = $this->realBuckets($companyId, $periodStart, $periodEnd);
            $projected = $this->projectedBuckets($companyId, $periodStart, $periodEnd, $scenario->code);

            $netReal = round($reals['income_real'] - $reals['other_real'] - $reals['personnel_real'] - $reals['legal_real'], 2);
            $netProjected = round($projected['income_projected'] - $projected['other_projected'] - $projected['personnel_projected'] - $projected['legal_projected'], 2);
            $closingReal = round($openingReal + $netReal, 2);
            $closingProjected = round($openingProjected + $netProjected, 2);

            $rows[] = [
                'period' => $periodStart->toDateString(),
                'opening_real' => $openingReal,
                'opening_projected' => $openingProjected,
                'income_real' => $reals['income_real'],
                'income_projected' => $projected['income_projected'],
                'other_real' => $reals['other_real'],
                'other_projected' => $projected['other_projected'],
                'personnel_real' => $reals['personnel_real'],
                'personnel_projected' => $projected['personnel_projected'],
                'legal_real' => $reals['legal_real'],
                'legal_projected' => $projected['legal_projected'],
                'net_real' => $netReal,
                'net_projected' => $netProjected,
                'closing_real' => $closingReal,
                'closing_projected' => $closingProjected,
                'accounts_receivable' => $this->receivables->accountsReceivable($companyId, $periodEnd),
                'accounts_payable' => $this->payables->accountsPayable($companyId, $periodEnd),
                'variation' => round($netReal - $netProjected, 2),
            ];

            $openingReal = $closingReal;
            $openingProjected = $closingProjected;
        }

        return $rows;
    }

    public function weekly(int $companyId, CarbonInterface|string $start, int $weeks = 12, ?string $scenarioCode = null): array
    {
        $scenario = $this->scenarios->activeForCompany($companyId, $scenarioCode);
        $cursor = Carbon::parse($start)->startOfWeek(Carbon::MONDAY);
        $openingReal = $this->openingBalance($companyId, $cursor);
        $openingProjected = $openingReal;
        $rows = [];

        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $cursor->copy()->addWeeks($i);
            $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
            $reals = $this->realBuckets($companyId, $weekStart, $weekEnd);
            $projected = $this->projectedBuckets($companyId, $weekStart, $weekEnd, $scenario->code);
            $netReal = round($reals['income_real'] - $reals['other_real'] - $reals['personnel_real'] - $reals['legal_real'], 2);
            $netProjected = round($projected['income_projected'] - $projected['other_projected'] - $projected['personnel_projected'] - $projected['legal_projected'], 2);
            $closingReal = round($openingReal + $netReal, 2);
            $closingProjected = round($openingProjected + $netProjected, 2);

            $rows[] = [
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
                'income_real' => $reals['income_real'],
                'income_projected' => $projected['income_projected'],
                'other_real' => $reals['other_real'],
                'other_projected' => $projected['other_projected'],
                'personnel_real' => $reals['personnel_real'],
                'personnel_projected' => $projected['personnel_projected'],
                'legal_real' => $reals['legal_real'],
                'legal_projected' => $projected['legal_projected'],
                'net_real' => $netReal,
                'net_projected' => $netProjected,
                'closing_real' => $closingReal,
                'closing_projected' => $closingProjected,
            ];

            $openingReal = $closingReal;
            $openingProjected = $closingProjected;
        }

        return $rows;
    }

    public function actualsForBudget(int $companyId, CarbonInterface|string $period, ?int $projectId = null): array
    {
        $start = Carbon::parse($period)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $movementQuery = CashMovement::query()
            ->forCompany($companyId)
            ->where('status', 'posted')
            ->whereBetween('movement_date', [$start, $end]);

        if ($projectId) {
            $movementQuery->where('project_id', $projectId);
        }

        return [
            'revenue_real' => (float) (clone $movementQuery)->sum('income'),
            'personnel_real' => (float) (clone $movementQuery)->where('source_document_type', 'payroll_record')->sum('expense'),
            'other_real' => (float) (clone $movementQuery)->where('source_document_type', 'expense_document')->sum('expense'),
            'legal_real' => (float) (clone $movementQuery)->where('source_document_type', 'legal_obligation')->sum('expense'),
        ];
    }

    public function openingBalance(int $companyId, CarbonInterface|string $asOf): float
    {
        $asOf = Carbon::parse($asOf)->startOfDay();
        $opening = (float) CashAccount::query()->forCompany($companyId)->sum('opening_balance');
        $net = (float) CashMovement::query()
            ->forCompany($companyId)
            ->where('status', 'posted')
            ->whereDate('movement_date', '<', $asOf->toDateString())
            ->selectRaw('COALESCE(SUM(income - expense), 0) as balance')
            ->value('balance');

        return round($opening + $net, 2);
    }

    private function realBuckets(int $companyId, Carbon $start, Carbon $end): array
    {
        $base = CashMovement::query()
            ->forCompany($companyId)
            ->where('status', 'posted')
            ->whereBetween('movement_date', [$start, $end]);

        return [
            'income_real' => (float) (clone $base)->sum('income'),
            'other_real' => (float) (clone $base)
                ->where(function ($query): void {
                    $query->whereNull('source_document_type')
                        ->orWhereNotIn('source_document_type', ['payroll_record', 'legal_obligation']);
                })
                ->sum('expense'),
            'personnel_real' => (float) (clone $base)->where('source_document_type', 'payroll_record')->sum('expense'),
            'legal_real' => (float) (clone $base)->where('source_document_type', 'legal_obligation')->sum('expense'),
        ];
    }

    private function projectedBuckets(int $companyId, Carbon $start, Carbon $end, string $scenarioCode): array
    {
        $scenario = $this->scenarios->activeForCompany($companyId, $scenarioCode);

        $incomeProjected = SalesDocument::query()
            ->forCompany($companyId)
            ->where('is_voided', false)
            ->get()
            ->sum(function (SalesDocument $document) use ($start, $end, $scenario): float {
                $date = Carbon::parse($document->projected_collection_date ?? $document->due_date ?? $document->issue_date)
                    ->addDays((int) $scenario->collection_delay_days);

                if (! $date->betweenIncluded($start, $end)) {
                    return 0.0;
                }

                return round($this->receivables->forecastAmount($document) * (float) $scenario->sales_factor, 2);
            });

        $otherProjected = (float) ExpenseDocument::query()
            ->forCompany($companyId)
            ->whereBetween('due_date', [$start, $end])
            ->get()
            ->sum(fn (ExpenseDocument $document): float => round($this->payables->balance($document) * (float) $scenario->cost_factor, 2));

        $personnelProjected = (float) PayrollRecord::query()
            ->forCompany($companyId)
            ->whereBetween('period_date', [$start, $end])
            ->sum('net_pay');

        $legalProjected = (float) LegalObligation::query()
            ->forCompany($companyId)
            ->whereBetween('due_date', [$start, $end])
            ->sum('pending_amount');

        return [
            'income_projected' => round($incomeProjected, 2),
            'other_projected' => round($otherProjected, 2),
            'personnel_projected' => round($personnelProjected + (float) $scenario->new_hires_monthly, 2),
            'legal_projected' => round($legalProjected, 2),
        ];
    }
}
