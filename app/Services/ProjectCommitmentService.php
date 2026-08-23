<?php

namespace App\Services;

use App\Models\Person;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\RecordStatus;
use App\Models\UfValue;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProjectCommitmentService
{
    public function __construct(
        private readonly HourlyRateService $hourlyRates,
        private readonly LegalParameterService $legalParameters,
        private readonly CurrencyConversionService $conversions,
    ) {
    }

    public function summarizeProject(Project|int $project, array $options = []): array
    {
        $project = $project instanceof Project
            ? $project->loadMissing(['salesCurrency', 'client'])
            : Project::query()->with(['salesCurrency', 'client'])->findOrFail($project);

        $excludeAssignmentId = isset($options['exclude_assignment_id']) ? (int) $options['exclude_assignment_id'] : null;
        $includeAssignment = $options['include_assignment'] ?? null;

        $assignments = $this->projectAssignments($project, $excludeAssignmentId);

        if ($includeAssignment instanceof ProjectAssignment && (int) $includeAssignment->project_id === (int) $project->id && $this->assignmentIsActive($includeAssignment)) {
            $assignments->push($this->hydrateAssignment($includeAssignment));
        }

        $warnings = [];
        $projectExchangeInfo = null;
        $commitmentReferenceDate = $this->commitmentReferenceDate($project, $assignments);
        $saleNetClp = $this->projectSaleNetToClp($project, $warnings, $commitmentReferenceDate, $projectExchangeInfo);
        $saleNetOriginal = $project->sale_net !== null && $project->sale_net !== ''
            ? round((float) $project->sale_net, 2)
            : null;
        $saleNetCurrency = $project->salesCurrency;
        $assignmentCount = $assignments->count();
        $assignmentBreakdown = [];
        $totalCommittedCost = 0.0;
        $assignmentsComplete = true;
        $projectedExchangeInfo = $projectExchangeInfo && ! empty($projectExchangeInfo['projected']) ? $projectExchangeInfo : null;

        foreach ($assignments as $assignment) {
            $estimate = $this->estimateAssignment($assignment, $project);
            $assignmentBreakdown[] = $estimate;

            if (! $estimate['calculation_complete']) {
                $assignmentsComplete = false;
            }

            $warnings = array_merge($warnings, $estimate['warnings']);
            if ($estimate['committed_cost'] !== null) {
                $totalCommittedCost += (float) $estimate['committed_cost'];
            }

            if (! empty($estimate['uses_projected_exchange_rate']) && ! $projectedExchangeInfo) {
                $projectedExchangeInfo = $estimate['exchange_rate_info'] ?? null;
            }
        }

        $warnings = array_values(array_unique(array_filter($warnings)));
        $calculationComplete = $saleNetClp !== null && $assignmentsComplete;
        $personnelCommittedCost = $calculationComplete ? round($totalCommittedCost, 2) : null;
        $projectedPersonnelMargin = $calculationComplete ? round((float) $saleNetClp - $personnelCommittedCost, 2) : null;
        $committedPercentage = $calculationComplete
            ? $this->committedPercentage($personnelCommittedCost, $saleNetClp, $warnings)
            : null;

        if ($calculationComplete && $projectedPersonnelMargin < 0) {
            $warnings[] = 'El costo de personal comprometido supera la venta neta del proyecto.';
        }

        $exchangeRateNote = $this->exchangeRateNote($projectedExchangeInfo);

        return [
            'project_id' => $project->id,
            'sale_net_contractual' => $saleNetOriginal,
            'sale_net_currency_code' => $saleNetCurrency?->code ?: 'CLP',
            'sale_net_currency_symbol' => $saleNetCurrency?->symbol ?: '$',
            'sale_net_currency_minor_units' => (int) ($saleNetCurrency?->minor_units ?? 0),
            'sale_net_clp' => $saleNetClp !== null ? round((float) $saleNetClp, 2) : null,
            'personnel_committed_cost' => $personnelCommittedCost,
            'projected_personnel_margin' => $projectedPersonnelMargin,
            'committed_percentage' => $committedPercentage,
            'assignment_count' => $assignmentCount,
            'calculation_complete' => $calculationComplete,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'negative_margin' => $calculationComplete && $projectedPersonnelMargin < 0,
            'negative_margin_amount' => $calculationComplete && $projectedPersonnelMargin < 0 ? abs($projectedPersonnelMargin) : null,
            'uses_projected_exchange_rate' => (bool) $projectedExchangeInfo,
            'exchange_rate_info' => $projectedExchangeInfo,
            'exchange_rate_note' => $exchangeRateNote,
            'assignments' => $assignmentBreakdown,
        ];
    }

    public function previewAssignment(ProjectAssignment $assignment, ?int $excludeAssignmentId = null): array
    {
        $assignment = $this->hydrateAssignment($assignment);
        $project = $assignment->project;

        if (! $project || ! $assignment->person_id) {
            return [
                'sale_net_clp' => null,
                'sale_net_contractual' => null,
                'sale_net_currency_code' => null,
                'sale_net_currency_symbol' => null,
                'sale_net_currency_minor_units' => null,
                'current_personnel_committed_cost' => null,
                'assignment_estimated_cost' => null,
                'after_save_personnel_committed_cost' => null,
                'projected_personnel_margin' => null,
                'committed_percentage' => null,
                'calculation_complete' => false,
                'warnings' => ['Seleccione una persona y un proyecto para estimar el compromiso.'],
                'negative_margin' => false,
                'negative_margin_amount' => null,
                'uses_projected_exchange_rate' => false,
                'exchange_rate_info' => null,
                'exchange_rate_note' => null,
            ];
        }

        $current = $this->summarizeProject($project, [
            'exclude_assignment_id' => $excludeAssignmentId,
        ]);
        $assignmentEstimate = $this->estimateAssignment($assignment, $project);
        $after = $this->summarizeProject($project, [
            'exclude_assignment_id' => $excludeAssignmentId,
            'include_assignment' => $assignment,
        ]);

        return [
            'sale_net_clp' => $after['sale_net_clp'],
            'sale_net_contractual' => $after['sale_net_contractual'],
            'sale_net_currency_code' => $after['sale_net_currency_code'],
            'sale_net_currency_symbol' => $after['sale_net_currency_symbol'],
            'sale_net_currency_minor_units' => $after['sale_net_currency_minor_units'],
            'current_personnel_committed_cost' => $current['personnel_committed_cost'],
            'assignment_estimated_cost' => $assignmentEstimate['committed_cost'],
            'after_save_personnel_committed_cost' => $after['personnel_committed_cost'],
            'projected_personnel_margin' => $after['projected_personnel_margin'],
            'committed_percentage' => $after['committed_percentage'],
            'calculation_complete' => $after['calculation_complete'] && $assignmentEstimate['calculation_complete'],
            'warnings' => array_values(array_unique(array_filter(array_merge(
                $current['warnings'] ?? [],
                $assignmentEstimate['warnings'] ?? [],
                $after['warnings'] ?? [],
            )))),
            'negative_margin' => (bool) ($after['negative_margin'] ?? false),
            'negative_margin_amount' => $after['negative_margin_amount'] ?? null,
            'uses_projected_exchange_rate' => (bool) ($current['uses_projected_exchange_rate'] ?? false) || (bool) ($assignmentEstimate['uses_projected_exchange_rate'] ?? false) || (bool) ($after['uses_projected_exchange_rate'] ?? false),
            'exchange_rate_info' => $after['exchange_rate_info'] ?? ($assignmentEstimate['exchange_rate_info'] ?? ($current['exchange_rate_info'] ?? null)),
            'exchange_rate_note' => $this->exchangeRateNote($after['exchange_rate_info'] ?? ($assignmentEstimate['exchange_rate_info'] ?? ($current['exchange_rate_info'] ?? null))),
        ];
    }

    private function estimateAssignment(ProjectAssignment $assignment, Project $project): array
    {
        $assignment = $this->hydrateAssignment($assignment);
        $person = $assignment->person;

        if (! $person instanceof Person) {
            return $this->incompleteEstimate($assignment, 'La asignación no tiene una persona vinculada.');
        }

        $range = $this->commitmentRange($assignment, $project, $person);
        if (! $range['complete']) {
            return $this->incompleteEstimate($assignment, $range['warning']);
        }

        if ($assignment->monthly_hours === null || $assignment->monthly_hours === '') {
            return $this->incompleteEstimate($assignment, "No se puede calcular el compromiso de {$assignment->code}: faltan las horas mensuales comprometidas.");
        }

        $monthlyHours = (float) $assignment->monthly_hours;
        if ($monthlyHours <= 0) {
            return $this->completeEstimate($assignment, 0.0);
        }

        $total = 0.0;
        $usesProjectedExchangeRate = false;
        $exchangeRateInfo = null;
        $exchangeRateNotes = [];
        $exactMonthlyPeriods = $this->exactMonthlyPeriods($range['start'], $range['end']);
        $periods = $exactMonthlyPeriods ?? $this->periodsBetween($range['start'], $range['end']);

        foreach ($periods as $periodStart) {
            $hours = $exactMonthlyPeriods
                ? $monthlyHours
                : round($monthlyHours * ($this->monthOverlap($range['start'], $range['end'], $periodStart)['days'] / $periodStart->daysInMonth), 4);

            try {
                $hourlyWarnings = [];
                $hourlyValue = $this->effectiveHourlyRateClp($assignment, $periodStart, $hourlyWarnings, $exchangeRateInfo, $usesProjectedExchangeRate);
                if ($hourlyValue === null) {
                    return $this->incompleteEstimate($assignment, $hourlyWarnings[0] ?? "No se puede calcular el compromiso de {$assignment->code}: falta el Valor HH de costeo de la Asignación y de la Persona.");
                }
            } catch (DomainException $exception) {
                return $this->incompleteEstimate($assignment, $exception->getMessage());
            }

            if (! empty($exchangeRateInfo['projected']) && ! empty($exchangeRateInfo['note'])) {
                $exchangeRateNotes[] = $exchangeRateInfo['note'];
            }

            $total += round($hours * $hourlyValue, 2);
        }

        return array_merge($this->completeEstimate($assignment, $total), [
            'uses_projected_exchange_rate' => $usesProjectedExchangeRate,
            'exchange_rate_info' => $exchangeRateInfo,
            'exchange_rate_note' => $exchangeRateNotes[0] ?? null,
        ]);
    }

    private function effectiveHourlyRateClp(ProjectAssignment $assignment, Carbon $periodStart, array &$warnings = [], ?array &$exchangeRateInfo = null, bool &$usesProjectedExchangeRate = false): ?float
    {
        $costing = $this->hourlyRates->resolveCostingForAssignment($assignment, $periodStart);

        if (($costing['amount'] ?? null) === null) {
            return null;
        }

        $exchangeRateInfo = null;
        $converted = $this->convertToClp(
            companyId: (int) $assignment->company_id,
            amount: (float) $costing['amount'],
            unitType: (string) ($costing['unit_type'] ?? 'CURRENCY'),
            currency: $costing['currency'] ?? null,
            date: $periodStart,
            warnings: $warnings,
            exchangeRateInfo: $exchangeRateInfo,
            allowProjectedUfFallback: true,
            contextLabel: "No se puede calcular el compromiso de {$assignment->code}:",
        );

        $usesProjectedExchangeRate = (bool) ($exchangeRateInfo['projected'] ?? false);

        if ($converted === null) {
            return null;
        }

        if (strtoupper((string) ($costing['unit_type'] ?? 'CURRENCY')) === 'UF') {
            return \App\Support\UiFormatter::roundAmount($converted, 'CLP');
        }

        return $converted;
    }

    private function projectSaleNetToClp(Project $project, array &$warnings, CarbonInterface|string $referenceDate, ?array &$exchangeRateInfo = null): ?float
    {
        if ($project->sale_net === null || $project->sale_net === '') {
            $warnings[] = 'El proyecto no tiene venta neta configurada.';

            return null;
        }

        $saleNet = (float) $project->sale_net;
        $currencyCode = strtoupper((string) ($project->salesCurrency?->code ?? 'CLP'));
        return $this->convertToClp(
            companyId: $project->company_id,
            amount: $saleNet,
            unitType: $currencyCode === 'UF' ? 'UF' : 'CURRENCY',
            currency: $project->salesCurrency ?: null,
            date: $referenceDate,
            warnings: $warnings,
            exchangeRateInfo: $exchangeRateInfo,
            allowProjectedUfFallback: true,
            contextLabel: 'Falta UF oficial para convertir la venta neta del proyecto.',
        );
    }

    private function projectAssignments(Project $project, ?int $excludeAssignmentId = null): Collection
    {
        return ProjectAssignment::query()
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->when($excludeAssignmentId, fn ($query) => $query->whereKeyNot($excludeAssignmentId))
            ->with([
                'person.employmentMode',
                'person.employmentContractType',
                'person.afp',
                'person.healthSystemCatalog',
                'person.hourlyRateCurrency',
                'project.salesCurrency',
                'hourlyRateCurrency',
                'assignmentStatus',
            ])
            ->get()
            ->filter(fn (ProjectAssignment $assignment): bool => $this->assignmentIsActive($assignment))
            ->values();
    }

    private function commitmentRange(ProjectAssignment $assignment, Project $project, Person $person): array
    {
        $start = collect([
            optional($assignment->start_date)?->toDateString(),
            optional($project->start_date)?->toDateString(),
            optional($person->start_date)?->toDateString(),
        ])->filter()->map(fn ($value) => Carbon::parse($value))->max();

        $end = collect([
            optional($assignment->end_date)?->toDateString(),
            optional($project->end_date)?->toDateString(),
            optional($person->end_date)?->toDateString(),
        ])->filter()->map(fn ($value) => Carbon::parse($value))->min();

        if (! $start || ! $end) {
            return [
                'complete' => false,
                'warning' => "La asignación {$assignment->code} no tiene una vigencia suficiente para proyectar el compromiso.",
            ];
        }

        if ($end->lt($start)) {
            return [
                'complete' => false,
                'warning' => "La asignación {$assignment->code} no tiene superposición vigente con el proyecto para proyectar el compromiso.",
            ];
        }

        return [
            'complete' => true,
            'start' => $start->copy()->startOfDay(),
            'end' => $end->copy()->startOfDay(),
            'warning' => null,
        ];
    }

    private function periodsBetween(Carbon $start, Carbon $end): Collection
    {
        $periods = collect();
        $cursor = $start->copy()->startOfMonth();
        $limit = $end->copy()->startOfMonth();

        while ($cursor->lte($limit)) {
            $periods->push($cursor->copy());
            $cursor->addMonth();
        }

        return $periods;
    }

    private function exactMonthlyPeriods(Carbon $start, Carbon $end): ?Collection
    {
        $periods = collect();
        $cursor = $start->copy()->startOfDay();

        while ($cursor->lt($end)) {
            $next = $cursor->copy()->addMonthNoOverflow()->startOfDay();

            if ($next->gt($end)) {
                return null;
            }

            $periods->push($cursor->copy());
            $cursor = $next;
        }

        return $cursor->equalTo($end) ? $periods : null;
    }

    private function monthOverlap(Carbon $rangeStart, Carbon $rangeEnd, Carbon $periodStart): array
    {
        $monthStart = $periodStart->copy()->startOfMonth();
        $monthEnd = $periodStart->copy()->endOfMonth();
        $start = $rangeStart->copy()->max($monthStart);
        $end = $rangeEnd->copy()->min($monthEnd);

        return [
            'start' => $start,
            'end' => $end,
            'days' => $start->diffInDays($end) + 1,
        ];
    }

    private function committedPercentage(float $committedCost, float $saleNetClp, array &$warnings): ?float
    {
        if ($saleNetClp < 0.00001) {
            if ($committedCost > 0.00001) {
                $warnings[] = 'El porcentaje comprometido no está disponible porque la venta neta del proyecto es 0.';

                return null;
            }

            return 0.0;
        }

        return round(($committedCost / $saleNetClp) * 100, 1);
    }

    private function assignmentIsActive(ProjectAssignment $assignment): bool
    {
        $code = strtolower((string) ($assignment->assignmentStatus?->code ?: $assignment->status));

        if ($code !== '') {
            return $code === 'active';
        }

        if ($assignment->assignment_status_id) {
            return strtolower((string) RecordStatus::query()->whereKey($assignment->assignment_status_id)->value('code')) === 'active';
        }

        return true;
    }

    private function hydrateAssignment(ProjectAssignment $assignment): ProjectAssignment
    {
        $assignment->loadMissing([
            'person.employmentMode',
            'person.employmentContractType',
            'person.afp',
            'person.healthSystemCatalog',
            'person.hourlyRateCurrency',
            'project.salesCurrency',
            'hourlyRateCurrency',
            'assignmentStatus',
        ]);

        return $assignment;
    }

    private function completeEstimate(ProjectAssignment $assignment, float $cost): array
    {
        return [
            'assignment_id' => $assignment->id,
            'assignment_code' => $assignment->code,
            'committed_cost' => round($cost, 2),
            'calculation_complete' => true,
            'warnings' => [],
            'uses_projected_exchange_rate' => false,
            'exchange_rate_info' => null,
            'exchange_rate_note' => null,
        ];
    }

    private function incompleteEstimate(ProjectAssignment $assignment, string $warning): array
    {
        return [
            'assignment_id' => $assignment->id,
            'assignment_code' => $assignment->code,
            'committed_cost' => null,
            'calculation_complete' => false,
            'warnings' => [$warning],
            'uses_projected_exchange_rate' => false,
            'exchange_rate_info' => null,
            'exchange_rate_note' => null,
        ];
    }

    private function commitmentReferenceDate(Project $project, Collection $assignments): Carbon
    {
        $dates = collect([
            $project->start_date,
            $project->end_date,
        ])->filter()->map(fn ($date) => Carbon::parse($date)->startOfDay());

        foreach ($assignments as $assignment) {
            if (filled($assignment->start_date)) {
                $dates->push(Carbon::parse($assignment->start_date)->startOfDay());
            }

            if (filled($assignment->end_date)) {
                $dates->push(Carbon::parse($assignment->end_date)->startOfDay());
            }
        }

        $dates->push(now()->startOfDay());

        return $dates->filter()->max() ?? now()->startOfDay();
    }

    private function convertToClp(
        int $companyId,
        float $amount,
        string $unitType,
        Currency|array|string|null $currency,
        CarbonInterface|string $date,
        array &$warnings,
        ?array &$exchangeRateInfo = null,
        bool $allowProjectedUfFallback = false,
        string $contextLabel = '',
    ): ?float {
        $amount = round($amount, 2);
        $requestedDate = Carbon::parse($date)->startOfDay();
        $exchangeRateInfo = null;

        if ($amount <= 0) {
            return round($amount, 2);
        }

        if (strtoupper($unitType) === 'UF') {
            $exact = UfValue::query()
                ->where('company_id', $companyId)
                ->whereDate('value_date', $requestedDate->toDateString())
                ->first();

            if ($exact) {
                $exchangeRateInfo = [
                    'currency_code' => 'UF',
                    'reference_date' => $requestedDate->toDateString(),
                    'value_date' => Carbon::parse($exact->value_date)->toDateString(),
                    'value' => (float) $exact->value,
                    'projected' => false,
                    'note' => null,
                ];

                return round($amount * (float) $exact->value, 2);
            }

            if ($allowProjectedUfFallback && $requestedDate->gt(now()->startOfDay())) {
                $latest = $this->legalParameters->latestOfficialUfOnOrBefore($companyId, $requestedDate);
                if ($latest) {
                    $exchangeRateInfo = [
                        'currency_code' => 'UF',
                        'reference_date' => $requestedDate->toDateString(),
                        'value_date' => Carbon::parse($latest['value_date'])->toDateString(),
                        'value' => (float) $latest['value'],
                        'projected' => true,
                        'note' => 'Proyección calculada con UF de referencia de '.\App\Support\UiFormatter::formatMoney($latest['value'], 'CLP').' correspondiente al '.Carbon::parse($latest['value_date'])->format('d/m/Y').', última UF oficial disponible.',
                    ];

                    return round($amount * (float) $latest['value'], 2);
                }
            }

            $warnings[] = $contextLabel !== ''
                ? $contextLabel.' Falta UF oficial para '.$requestedDate->toDateString().'.'
                : 'Falta UF oficial para '.$requestedDate->toDateString().'.';

            return null;
        }

        $currencyCode = strtoupper((string) ($currency instanceof Currency ? $currency->code : ($currency ?: 'CLP')));
        if ($currencyCode === 'CLP') {
            return round($amount, 2);
        }

        if (! $currency instanceof Currency) {
            $warnings[] = $contextLabel !== ''
                ? $contextLabel.' Falta configuración de moneda para convertir '.$currencyCode.' a CLP.'
                : 'Falta configuración de moneda para convertir '.$currencyCode.' a CLP.';

            return null;
        }

        try {
            $rate = (float) $this->legalParameters->exchangeRate($companyId, $currency->id, $requestedDate);
        } catch (DomainException $exception) {
            $warnings[] = $exception->getMessage();

            return null;
        }

        $exchangeRateInfo = [
            'currency_code' => $currencyCode,
            'reference_date' => $requestedDate->toDateString(),
            'value_date' => $requestedDate->toDateString(),
            'value' => $rate,
            'projected' => false,
            'note' => null,
        ];

        return (float) $this->conversions->convert(
            amount: $amount,
            fromCurrency: $currency,
            toCurrency: 'CLP',
            exchangeRate: $rate,
            date: $requestedDate,
        )['converted_amount'];
    }

    private function exchangeRateNote(?array $exchangeRateInfo): ?string
    {
        if (! is_array($exchangeRateInfo) || empty($exchangeRateInfo['projected'])) {
            return null;
        }

        return $exchangeRateInfo['note'] ?? null;
    }
}
