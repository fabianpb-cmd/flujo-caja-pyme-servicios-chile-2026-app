<?php

namespace App\Services;

use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\RecordStatus;
use App\Support\UiFormatter;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProjectCommitmentService
{
    public function __construct(
        private readonly PayrollService $payroll,
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

        $warnings = [];
        $saleNetClp = $this->projectSaleNetToClp($project, $warnings);
        $assignments = $this->projectAssignments($project, $excludeAssignmentId);

        if ($includeAssignment instanceof ProjectAssignment && (int) $includeAssignment->project_id === (int) $project->id && $this->assignmentIsActive($includeAssignment)) {
            $assignments->push($this->hydrateAssignment($includeAssignment));
        }

        $assignmentCount = $assignments->count();
        $assignmentBreakdown = [];
        $totalCommittedCost = 0.0;
        $assignmentsComplete = true;

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

        return [
            'project_id' => $project->id,
            'sale_net_clp' => $saleNetClp !== null ? round((float) $saleNetClp, 2) : null,
            'personnel_committed_cost' => $personnelCommittedCost,
            'projected_personnel_margin' => $projectedPersonnelMargin,
            'committed_percentage' => $committedPercentage,
            'assignment_count' => $assignmentCount,
            'calculation_complete' => $calculationComplete,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'negative_margin' => $calculationComplete && $projectedPersonnelMargin < 0,
            'negative_margin_amount' => $calculationComplete && $projectedPersonnelMargin < 0 ? abs($projectedPersonnelMargin) : null,
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
                'current_personnel_committed_cost' => null,
                'assignment_estimated_cost' => null,
                'after_save_personnel_committed_cost' => null,
                'projected_personnel_margin' => null,
                'committed_percentage' => null,
                'calculation_complete' => false,
                'warnings' => ['Seleccione una persona y un proyecto para estimar el compromiso.'],
                'negative_margin' => false,
                'negative_margin_amount' => null,
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
        ];
    }

    private function estimateAssignment(ProjectAssignment $assignment, Project $project): array
    {
        $assignment = $this->hydrateAssignment($assignment);
        $person = $assignment->person;

        if (! $person instanceof Person) {
            return $this->incompleteEstimate($assignment, 'La asignación no tiene una persona vinculada.');
        }

        $flags = $this->payroll->modalityFlags($person);

        return match (true) {
            $flags['is_hourly'] => $this->estimateHourlyAssignment($assignment, $project, $person),
            $flags['is_project'] => $this->estimateProjectAssignment($assignment, $project, $person),
            default => $this->estimateMonthlyAssignment($assignment, $project, $person),
        };
    }

    private function estimateHourlyAssignment(ProjectAssignment $assignment, Project $project, Person $person): array
    {
        $range = $this->commitmentRange($assignment, $project, $person);
        if (! $range['complete']) {
            return $this->incompleteEstimate($assignment, $range['warning']);
        }

        if ($assignment->monthly_hours === null || $assignment->monthly_hours === '') {
            return $this->incompleteEstimate($assignment, "La asignación {$assignment->code} no tiene horas mensuales comprometidas.");
        }

        $monthlyHours = (float) $assignment->monthly_hours;
        if ($monthlyHours <= 0) {
            return $this->completeEstimate($assignment, 0.0);
        }

        $warnings = [];
        $total = 0.0;
        foreach ($this->periodsBetween($range['start'], $range['end']) as $periodStart) {
            $overlap = $this->monthOverlap($range['start'], $range['end'], $periodStart);
            $hours = round($monthlyHours * ($overlap['days'] / $periodStart->daysInMonth), 4);

            try {
                $hourlyValue = $this->effectiveHourlyRateClp($assignment, $project, $periodStart);
                if ($hourlyValue === null) {
                    return $this->incompleteEstimate($assignment, "La asignación {$assignment->code} no tiene tarifa HH efectiva configurada.");
                }

                $calculation = $this->payroll->calculate($person, $periodStart, [
                    'project_id' => $project->id,
                    'hours_approved' => $hours,
                    'hourly_value' => $hourlyValue,
                ]);
            } catch (DomainException $exception) {
                return $this->incompleteEstimate($assignment, $exception->getMessage());
            }

            if (($calculation['calculation_status'] ?? 'OK') !== 'OK') {
                $warnings[] = trim((string) ($calculation['calculation_notes'] ?? "La asignación {$assignment->code} requiere revisión para proyectar su costo por hora."));
            }

            $total += (float) ($calculation['employer_cost'] ?? 0);
        }

        if (! empty($warnings)) {
            return $this->incompleteEstimate($assignment, implode(' ', array_unique(array_filter($warnings))));
        }

        return $this->completeEstimate($assignment, $total);
    }

    private function estimateProjectAssignment(ProjectAssignment $assignment, Project $project, Person $person): array
    {
        $rawProjectValue = $assignment->project_value;
        if ($rawProjectValue === null || $rawProjectValue === '') {
            return $this->incompleteEstimate($assignment, "La asignación {$assignment->code} no tiene monto proyecto/hito configurado.");
        }

        $projectValue = (float) $rawProjectValue;
        if ($projectValue <= 0) {
            return $this->completeEstimate($assignment, 0.0);
        }

        $referenceDate = $this->referenceDateForFixedAmount($assignment, $project, $person);
        if ($referenceDate === null && strtoupper((string) ($assignment->hourly_rate_unit_type ?: 'CURRENCY')) !== 'CURRENCY') {
            return $this->incompleteEstimate($assignment, "La asignación {$assignment->code} requiere una fecha de referencia para convertir el monto proyecto/hito.");
        }

        try {
            $projectValueClp = (float) $this->hourlyRates->resolveAssignmentProjectValue($assignment, $referenceDate ?? now()->toDateString());
            $calculation = $this->payroll->calculate($person, ($referenceDate ?? now())->toDateString(), [
                'project_id' => $project->id,
                'project_value' => $projectValueClp,
            ]);
        } catch (DomainException $exception) {
            return $this->incompleteEstimate($assignment, $exception->getMessage());
        }

        if (($calculation['calculation_status'] ?? 'OK') !== 'OK') {
            return $this->incompleteEstimate($assignment, trim((string) ($calculation['calculation_notes'] ?? "La asignación {$assignment->code} requiere revisión para proyectar su costo por proyecto.")));
        }

        return $this->completeEstimate($assignment, (float) ($calculation['employer_cost'] ?? 0));
    }

    private function estimateMonthlyAssignment(ProjectAssignment $assignment, Project $project, Person $person): array
    {
        $range = $this->commitmentRange($assignment, $project, $person);
        if (! $range['complete']) {
            return $this->incompleteEstimate($assignment, $range['warning']);
        }

        $warnings = [];
        $total = 0.0;
        foreach ($this->periodsBetween($range['start'], $range['end']) as $periodStart) {
            $activeAssignments = $this->personActiveAssignmentsForMonth($person, $periodStart);
            if ($activeAssignments->count() !== 1 || (int) $activeAssignments->first()->id !== (int) $assignment->id) {
                return $this->incompleteEstimate($assignment, "La asignación {$assignment->code} no puede distribuir el costo mensual de forma inequívoca en {$periodStart->isoFormat('MMMM YYYY')}.");
            }

            $overlap = $this->monthOverlap($range['start'], $range['end'], $periodStart);
            $simulatedPerson = clone $person;
            $simulatedPerson->start_date = $overlap['start']->toDateString();
            $simulatedPerson->end_date = $overlap['end']->toDateString();

            try {
                $calculation = $this->payroll->calculate($simulatedPerson, $periodStart, [
                    'project_id' => $project->id,
                ]);
            } catch (DomainException $exception) {
                return $this->incompleteEstimate($assignment, $exception->getMessage());
            }

            if (($calculation['calculation_status'] ?? 'OK') !== 'OK') {
                $warnings[] = trim((string) ($calculation['calculation_notes'] ?? "La asignación {$assignment->code} requiere revisión para proyectar el costo mensual."));
            }

            $total += (float) ($calculation['employer_cost'] ?? 0);
        }

        if (! empty($warnings)) {
            return $this->incompleteEstimate($assignment, implode(' ', array_unique(array_filter($warnings))));
        }

        return $this->completeEstimate($assignment, $total);
    }

    private function effectiveHourlyRateClp(ProjectAssignment $assignment, Project $project, Carbon $periodStart): ?float
    {
        if ((float) ($assignment->hourly_value ?? 0) > 0) {
            return (float) $this->hourlyRates->resolveAssignmentRate($assignment, $periodStart);
        }

        if ((float) ($project->contracted_hourly_rate ?? 0) > 0) {
            return (float) $this->hourlyRates->resolveProjectRate($project, $periodStart);
        }

        return null;
    }

    private function projectSaleNetToClp(Project $project, array &$warnings): ?float
    {
        if ($project->sale_net === null || $project->sale_net === '') {
            $warnings[] = 'El proyecto no tiene venta neta configurada.';

            return null;
        }

        $saleNet = (float) $project->sale_net;
        $currency = $project->salesCurrency ?: null;
        $currencyCode = strtoupper((string) ($currency?->code ?? 'CLP'));

        if ($currencyCode === 'CLP' || $saleNet <= 0) {
            return round($saleNet, 2);
        }

        if ($currencyCode === 'UF') {
            $latest = $this->legalParameters->latestOfficialUfOnOrBefore($project->company_id, now());
            if (! $latest) {
                $warnings[] = 'Falta UF oficial para convertir la venta neta del proyecto.';

                return null;
            }

            return round($saleNet * (float) $latest['value'], 2);
        }

        if (! $currency) {
            $warnings[] = 'Falta moneda de venta para convertir la venta neta del proyecto.';

            return null;
        }

        try {
            $rate = (float) $this->legalParameters->exchangeRate($project->company_id, (int) $currency->id, now());
        } catch (DomainException $exception) {
            $warnings[] = $exception->getMessage();

            return null;
        }

        return (float) $this->conversions->convert(
            amount: $saleNet,
            fromCurrency: $currency,
            toCurrency: 'CLP',
            exchangeRate: $rate,
            date: now(),
        )['converted_amount'];
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

    private function personActiveAssignmentsForMonth(Person $person, Carbon $periodStart): Collection
    {
        $periodEnd = $periodStart->copy()->endOfMonth();

        return ProjectAssignment::query()
            ->where('company_id', $person->company_id)
            ->where('person_id', $person->id)
            ->with('assignmentStatus')
            ->where(function ($query) use ($periodEnd) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $periodEnd);
            })
            ->where(function ($query) use ($periodStart) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $periodStart);
            })
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

    private function referenceDateForFixedAmount(ProjectAssignment $assignment, Project $project, Person $person): ?Carbon
    {
        $candidates = collect([
            optional($assignment->start_date)?->toDateString(),
            optional($project->start_date)?->toDateString(),
            optional($person->start_date)?->toDateString(),
        ])->filter()->values();

        return $candidates->isEmpty() ? null : Carbon::parse($candidates->first())->startOfDay();
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
        ];
    }
}
