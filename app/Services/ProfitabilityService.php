<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Currency;
use App\Models\ExpenseDocument;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\TimeEntry;
use App\Support\UiFormatter;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProfitabilityService
{
    public function __construct(
        private readonly CompanySettingsService $settings,
        private readonly HourlyCostService $hourlyCosts,
        private readonly ProjectCommitmentService $commitments,
        private readonly LegalParameterService $legalParameters,
        private readonly CurrencyConversionService $conversions,
    )
    {
    }

    public function byProject(int $companyId, array $filters = []): array
    {
        $minimumMargin = (float) $this->settings->get($companyId, 'margin_minimum', '0.15');
        $period = $this->resolvePeriod($filters['period'] ?? null);
        $allocation = $this->hourlyCosts->companyProjectAllocation($companyId, $period);
        $salesByProject = $this->salesByProject($companyId, $period);
        $billedHoursByProject = $this->billedHoursByProject($companyId, $period);
        $hoursByProject = $this->hoursByProject($companyId, $period);
        $expensesByProject = $this->expensesByProject($companyId, $period);
        $collectedByProject = $this->collectedByProject($companyId, $period);

        return Project::query()
            ->forCompany($companyId)
            ->with(['client', 'projectStatus', 'salesCurrency'])
            ->when(! empty($filters['client_id']), fn ($query) => $query->where('client_id', $filters['client_id']))
            ->when(! empty($filters['project_id']), fn ($query) => $query->whereKey($filters['project_id']))
            ->when(! empty($filters['project_status']), function ($query) use ($filters) {
                $query->where(function ($inner) use ($filters) {
                    $inner->where('project_status', $filters['project_status'])
                        ->orWhereHas('projectStatus', fn ($statusQuery) => $statusQuery->where('code', $filters['project_status']));
                });
            })
            ->orderBy('code')
            ->get()
            ->map(function (Project $project) use (
                $minimumMargin,
                $period,
                $allocation,
                $salesByProject,
                $billedHoursByProject,
                $hoursByProject,
                $expensesByProject,
                $collectedByProject,
            ): array {
                $commitment = $this->commitments->summarizeProject($project);
                $allocationRow = $allocation['projects'][$project->id] ?? ['cost' => 0.0, 'hours' => 0.0, 'vacation_provision' => 0.0];
                $salesRow = $salesByProject[$project->id] ?? ['generated' => 0.0, 'invoiced' => 0.0];
                $hoursRow = $hoursByProject[$project->id] ?? ['worked' => 0.0, 'approved' => 0.0];
                $approvedHours = (float) $hoursRow['approved'];
                $workedHours = (float) $hoursRow['worked'];
                $billedHours = (float) ($billedHoursByProject[$project->id] ?? 0.0);
                $pendingHours = round(max(0, $approvedHours - $billedHours), 4);

                $laborCost = round((float) ($allocationRow['cost'] ?? 0.0), 2);
                $vacationProvision = round((float) ($allocationRow['vacation_provision'] ?? 0.0), 2);
                $directExpenses = round((float) ($expensesByProject[$project->id] ?? 0.0), 2);
                $generatedSales = $this->projectSaleNetToClp($project, (float) ($project->sale_net ?? $salesRow['generated']), $period);
                $invoicedSales = round((float) $salesRow['invoiced'], 2);
                $collectedSales = round((float) ($collectedByProject[$project->id] ?? 0.0), 2);
                $totalDirectCost = round($laborCost + $directExpenses, 2);
                $margin = round($invoicedSales - $totalDirectCost, 2);
                $marginPct = $invoicedSales > 0 ? round($margin / $invoicedSales, 4) : 0.0;
                $averageSaleHour = $billedHours > 0 ? round($invoicedSales / $billedHours, 2) : null;
                $averageCostHour = $approvedHours > 0 ? round($laborCost / $approvedHours, 2) : null;
                $hourMargin = ($averageSaleHour !== null && $averageCostHour !== null)
                    ? round($averageSaleHour - $averageCostHour, 2)
                    : null;

                $alerts = [];
                if ($pendingHours > 0) {
                    $alerts[] = 'HH aprobadas no facturadas';
                }
                if ($approvedHours > 0 && $laborCost <= 0) {
                    $alerts[] = 'Costo HH no disponible';
                }
                if ($margin < 0) {
                    $alerts[] = 'Proyecto con margen negativo';
                }

                $projectedAlerts = $commitment['warnings'] ?? [];

                return [
                    'project_id' => $project->id,
                    'project_code' => $project->code,
                    'project_name' => $project->name,
                    'client_name' => $project->client?->legal_name,
                    'project_status' => $project->projectStatus?->name ?: $project->project_status,
                    'sale' => $generatedSales,
                    'facturado' => $invoicedSales,
                    'cobrado' => $collectedSales,
                    'cost_personal' => $laborCost,
                    'vacation_provision' => round($vacationProvision, 2),
                    'other_costs' => $directExpenses,
                    'total_cost' => $totalDirectCost,
                    'margin' => $margin,
                    'margin_pct' => $marginPct,
                    'hours_worked' => round($workedHours, 2),
                    'hours' => round($approvedHours, 2),
                    'hours_billed' => round($billedHours, 2),
                    'hours_pending' => round($pendingHours, 2),
                    'contracted_rate' => $project->contracted_hourly_rate ? (float) $project->contracted_hourly_rate : null,
                    'effective_rate' => $averageSaleHour,
                    'hour_cost' => $averageCostHour,
                    'hour_margin' => $hourMargin,
                    'alerts' => $alerts,
                    'projected_personnel_sale' => $commitment['sale_net_clp'],
                    'personnel_committed_cost' => $commitment['personnel_committed_cost'],
                    'projected_personnel_margin' => $commitment['projected_personnel_margin'],
                    'committed_percentage' => $commitment['committed_percentage'],
                    'commitment_calculation_complete' => $commitment['calculation_complete'],
                    'commitment_warnings' => $projectedAlerts,
                    'status' => $marginPct < 0 ? 'Pérdida' : ($marginPct < $minimumMargin ? 'Bajo mínimo' : 'OK'),
                    'calculation_breakdown' => [
                        'result' => [
                            'label' => 'Margen',
                            'value' => UiFormatter::formatMoney($margin),
                            'note' => $invoicedSales > 0 ? UiFormatter::formatPercent($marginPct, 2) : 'Sin ventas facturadas en el período.',
                        ],
                        'warnings' => $alerts,
                        'sections' => [
                            [
                                'title' => 'Rentabilidad',
                                'rows' => [
                                    ['label' => 'Venta generada', 'value' => UiFormatter::formatMoney($generatedSales)],
                                    ['label' => 'Venta facturada', 'value' => UiFormatter::formatMoney($invoicedSales)],
                                    ['label' => 'Venta cobrada', 'value' => UiFormatter::formatMoney($collectedSales)],
                                    ['label' => 'Costo laboral', 'value' => UiFormatter::formatMoney($laborCost)],
                                    ['label' => 'Otros costos directos', 'value' => UiFormatter::formatMoney($directExpenses)],
                                    ['label' => 'Costo total directo', 'value' => UiFormatter::formatMoney($totalDirectCost), 'strong' => true],
                                    ['label' => 'Margen', 'value' => UiFormatter::formatMoney($margin), 'strong' => true],
                                    ['label' => 'Margen %', 'value' => UiFormatter::formatPercent($marginPct)],
                                ],
                            ],
                            [
                                'title' => 'Compromiso de personal proyectado',
                                'rows' => [
                                    ['label' => 'Venta contractual', 'value' => $commitment['sale_net_clp'] !== null ? UiFormatter::formatMoney($commitment['sale_net_clp']) : 'No disponible'],
                                    ['label' => 'Personal comprometido', 'value' => $commitment['personnel_committed_cost'] !== null ? UiFormatter::formatMoney($commitment['personnel_committed_cost']) : 'No disponible'],
                                    ['label' => 'Margen proyectado personal', 'value' => $commitment['projected_personnel_margin'] !== null ? UiFormatter::formatMoney($commitment['projected_personnel_margin']) : 'No disponible', 'strong' => true],
                                    ['label' => '% comprometido', 'value' => $commitment['committed_percentage'] !== null ? UiFormatter::formatPercent($commitment['committed_percentage'] / 100, 1) : 'No disponible'],
                                    ['label' => 'Cálculo completo', 'value' => $commitment['calculation_complete'] ? 'Sí' : 'No'],
                                ],
                            ],
                            [
                                'title' => 'Horas',
                                'rows' => [
                                    ['label' => 'HH trabajadas', 'value' => UiFormatter::formatHours($workedHours)],
                                    ['label' => 'HH aprobadas', 'value' => UiFormatter::formatHours($approvedHours)],
                                    ['label' => 'HH facturadas', 'value' => UiFormatter::formatHours($billedHours)],
                                    ['label' => 'HH pendientes', 'value' => UiFormatter::formatHours($pendingHours)],
                                    ['label' => 'Venta promedio HH', 'value' => $averageSaleHour !== null ? UiFormatter::formatMoney($averageSaleHour) : '—'],
                                    ['label' => 'Costo HH promedio', 'value' => $averageCostHour !== null ? UiFormatter::formatMoney($averageCostHour) : '—'],
                                    ['label' => 'Margen HH', 'value' => $hourMargin !== null ? UiFormatter::formatMoney($hourMargin) : '—'],
                                ],
                            ],
                        ],
                        'parameters' => array_values(array_filter([
                            ['label' => 'Período', 'value' => $period ? UiFormatter::formatDate($period) : '—', 'validity' => $period ? UiFormatter::formatDate($period) : '—', 'source' => 'Filtro de período'],
                        ])),
                    ],
                ];
            })
            ->all();
    }

    public function costSummary(int $companyId, array $filters = []): array
    {
        $allocation = $this->hourlyCosts->companyProjectAllocation($companyId, $this->resolvePeriod($filters['period'] ?? null));

        return [
            'assigned_cost' => round((float) collect($allocation['projects'])->sum('cost'), 2),
            'unassigned_cost' => round((float) ($allocation['unassigned_cost'] ?? 0), 2),
        ];
    }

    private function resolvePeriod(CarbonInterface|string|null $period): ?Carbon
    {
        if (blank($period)) {
            return null;
        }

        return Carbon::parse($period)->startOfMonth();
    }

    private function salesByProject(int $companyId, ?Carbon $period): array
    {
        return SalesDocument::query()
            ->forCompany($companyId)
            ->selectRaw('project_id, SUM(net_amount) as invoiced_net')
            ->whereNotNull('project_id')
            ->where('is_voided', false)
            ->whereNotIn('status', ['Borrador', 'Anulado'])
            ->when($period, fn ($query) => $query->whereBetween('issue_date', [$period->toDateString(), $period->copy()->endOfMonth()->toDateString()]))
            ->groupBy('project_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->project_id => [
                    'generated' => 0.0,
                    'invoiced' => round((float) $row->invoiced_net, 2),
                ],
            ])
            ->all();
    }

    private function projectSaleNetToClp(Project $project, float $saleNet, CarbonInterface|string|null $date): float
    {
        $currency = $project->salesCurrency ?: null;
        $currencyCode = strtoupper((string) ($currency?->code ?? 'CLP'));

        if ($currencyCode === 'CLP' || $saleNet <= 0) {
            return round($saleNet, 2);
        }

        $referenceDate = $date ?? now();
        if ($currencyCode === 'UF') {
            $rate = $date === null
                ? (float) ($this->legalParameters->latestOfficialUfOnOrBefore($project->company_id, $referenceDate)['value'] ?? 0)
                : (float) $this->legalParameters->ufValue($project->company_id, $referenceDate);
        } else {
            $rate = (float) $this->legalParameters->exchangeRate($project->company_id, (int) $currency->id, $referenceDate);
        }

        if ($rate <= 0) {
            return 0.0;
        }

        return (float) $this->conversions->convert(
            amount: $saleNet,
            fromCurrency: $currency,
            toCurrency: 'CLP',
            exchangeRate: $rate,
            date: $referenceDate,
        )['converted_amount'];
    }

    private function billedHoursByProject(int $companyId, ?Carbon $period): array
    {
        return TimeEntry::query()
            ->selectRaw('time_entries.project_id, SUM(sales_document_time_entries.hours_approved) as billed_hours')
            ->join('sales_document_time_entries', 'sales_document_time_entries.time_entry_id', '=', 'time_entries.id')
            ->join('sales_documents', 'sales_documents.id', '=', 'sales_document_time_entries.sales_document_id')
            ->where('time_entries.company_id', $companyId)
            ->whereNotNull('time_entries.project_id')
            ->where('sales_documents.is_voided', false)
            ->whereNotIn('sales_documents.status', ['Borrador', 'Anulado'])
            ->when($period, fn ($query) => $query->whereBetween('time_entries.entry_date', [$period->toDateString(), $period->copy()->endOfMonth()->toDateString()]))
            ->groupBy('time_entries.project_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->project_id => round((float) $row->billed_hours, 4)])
            ->all();
    }

    private function hoursByProject(int $companyId, ?Carbon $period): array
    {
        return TimeEntry::query()
            ->forCompany($companyId)
            ->with('approvalStatus')
            ->when($period, fn ($query) => $query->whereBetween('entry_date', [$period->toDateString(), $period->copy()->endOfMonth()->toDateString()]))
            ->get()
            ->groupBy('project_id')
            ->reject(fn (Collection $rows, $projectId) => empty($projectId))
            ->map(function (Collection $rows): array {
                $approvedRows = $rows->filter(fn (TimeEntry $entry): bool => $this->isApproved($entry));

                return [
                    'worked' => round((float) $rows->sum('hours_worked'), 4),
                    'approved' => round((float) $approvedRows->sum('hours_approved'), 4),
                ];
            })
            ->all();
    }

    private function expensesByProject(int $companyId, ?Carbon $period): array
    {
        return ExpenseDocument::query()
            ->forCompany($companyId)
            ->whereNotNull('project_id')
            ->when($period, fn ($query) => $query->whereBetween('issue_date', [$period->toDateString(), $period->copy()->endOfMonth()->toDateString()]))
            ->get()
            ->groupBy('project_id')
            ->map(function (Collection $rows): float {
                return round((float) $rows->sum(function (ExpenseDocument $expense): float {
                    return (float) ($expense->deductible_vat ? $expense->net_amount : $expense->gross_amount);
                }), 2);
            })
            ->all();
    }

    private function collectedByProject(int $companyId, ?Carbon $period): array
    {
        return CashMovement::query()
            ->forCompany($companyId)
            ->selectRaw('project_id, SUM(income) as collected_amount')
            ->whereNotNull('project_id')
            ->where('source_document_type', 'sales_document')
            ->where('status', 'posted')
            ->when($period, fn ($query) => $query->whereBetween('movement_date', [$period->toDateString(), $period->copy()->endOfMonth()->toDateString()]))
            ->groupBy('project_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->project_id => round((float) $row->collected_amount, 2)])
            ->all();
    }

    private function isApproved(TimeEntry $entry): bool
    {
        $code = strtolower((string) ($entry->approvalStatus?->code ?: $entry->approval_status));

        return in_array($code, ['approved', 'aprobado'], true);
    }
}
