<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\PayrollAdjustment;
use App\Models\Person;
use App\Models\PayrollRecord;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\TimeEntry;
use App\Support\UiFormatter;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public const STATUS_PENDING_PAYMENT_DATE = 'PEND_FECHA_PAGO';

    public function __construct(
        private readonly LegalParameterService $legalParameters,
        private readonly IncomeTaxService $incomeTax,
        private readonly HourlyRateService $hourlyRates,
        private readonly CompanySettingsService $settings,
    )
    {
    }

    public function payrollDefaultValues(Person $person, CarbonInterface|string $periodDate, ?int $projectId = null): array
    {
        $period = Carbon::parse($periodDate)->startOfMonth();
        $context = $this->payrollContext($person, $period, $projectId);

        return [
            'project_id' => $context['project_id'],
            'hours_approved' => $context['hours_approved_auto'],
            'monthly_value' => $context['monthly_value_auto'],
            'hourly_value' => $context['hourly_value_auto'],
            'project_value' => $context['project_value_auto'],
            'health_additional' => $context['health_additional_auto'],
        ];
    }

    public function hourlyPayrollTimeEntries(Person $person, CarbonInterface|string $periodDate, ?int $projectId = null): Collection
    {
        return $this->payrollApprovedTimeEntries(
            $person->company_id,
            $person->id,
            Carbon::parse($periodDate)->startOfMonth(),
            $projectId
        );
    }

    public function modalityFlags(Person $person): array
    {
        $person->loadMissing(['employmentMode', 'employmentContractType']);

        $modeCode = strtoupper((string) $person->employmentMode?->code);
        $contractCode = strtoupper((string) $person->employmentContractType?->code);
        $modality = mb_strtolower((string) ($modeCode ?: $person->modality));

        return [
            'mode_code' => $modeCode,
            'contract_code' => $contractCode,
            'modality' => $modality,
            'is_dependent' => $this->isDependent($modeCode, $contractCode, $modality),
            'is_hourly' => $this->isHourly($modeCode, $modality),
            'is_project' => $this->isProject($modeCode, $modality),
        ];
    }

    private function payrollContext(Person $person, Carbon $period, ?int $projectId = null): array
    {
        $person->loadMissing(['employmentMode', 'employmentContractType', 'afp', 'healthSystemCatalog']);
        $flags = $this->modalityFlags($person);
        $isHourly = $flags['is_hourly'];

        $companyId = $person->company_id;
        $hourlyConsumption = $isHourly ? $this->hourlyPayrollConsumption($person, $period, $projectId) : null;
        $assignmentContext = $isHourly
            ? [
                'assignment' => $hourlyConsumption['assignment'],
                'assignments' => $hourlyConsumption['assignments'],
                'ambiguous' => false,
                'multiple_assignments_used' => $hourlyConsumption['multiple_assignments_used'],
                'assignment_display' => $hourlyConsumption['assignment_display'],
                'assignment_range_display' => $hourlyConsumption['assignment_range_display'],
            ]
            : $this->payrollAssignmentContext($companyId, $person->id, $period, $projectId);
        $assignment = $assignmentContext['assignment'];
        $project = $isHourly
            ? $hourlyConsumption['project']
            : ($assignment?->project ?: ($projectId ? Project::query()->forCompany($companyId)->with('salesCurrency')->find($projectId) : null));
        $timeEntries = $isHourly
            ? $hourlyConsumption['time_entries']
            : $this->payrollApprovedTimeEntries($companyId, $person->id, $period, $project?->id);

        $hoursApprovedAuto = round((float) $timeEntries->sum('hours_approved'), 2);
        $notes = [];
        $requiresReview = false;

        if (! $isHourly && $assignmentContext['ambiguous']) {
            $notes[] = 'Existe más de una asignación vigente para la persona y el proyecto en el período.';
            $requiresReview = true;
        }

        $monthlyValueAuto = $person->monthly_value !== null ? (float) $person->monthly_value : null;
        $healthAdditionalAuto = $person->additional_health_plan !== null ? (float) $person->additional_health_plan : null;
        $hourlyValueAuto = null;
        $hourlyValueSource = null;
        $hourlyValueLegacyAuto = null;
        $projectValueAuto = null;
        $projectValueSource = null;

        if ($isHourly) {
            if ((float) $person->hourly_value > 0) {
                $hourlyValueAuto = (float) $this->hourlyRates->resolvePersonRate($person, $period);
                $hourlyValueSource = [
                    'type' => 'person',
                    'person_name' => $person->full_name,
                    'rate_value' => $person->hourly_value,
                    'rate_unit_type' => strtoupper((string) ($person->hourly_rate_unit_type ?: 'CURRENCY')),
                    'currency' => $person->hourlyRateDisplayCurrency ?? 'CLP',
                ];
            }
            $hourlyValueLegacyAuto = $hourlyValueAuto;
        } elseif (! $assignmentContext['ambiguous']) {
            if ((float) $person->hourly_value > 0) {
                $hourlyValueAuto = (float) $this->hourlyRates->resolvePersonRate($person, $period);
                $hourlyValueSource = [
                    'type' => 'person',
                    'person_name' => $person->full_name,
                    'rate_value' => $person->hourly_value,
                    'rate_unit_type' => strtoupper((string) ($person->hourly_rate_unit_type ?: 'CURRENCY')),
                    'currency' => $person->hourlyRateDisplayCurrency ?? 'CLP',
                ];
            }

            if ($assignment) {
                if ((float) $assignment->hourly_value > 0) {
                    $hourlyValueLegacyAuto = (float) $this->hourlyRates->resolveAssignmentRate($assignment, $period);
                } elseif ((float) $person->hourly_value > 0) {
                    $hourlyValueLegacyAuto = (float) $this->hourlyRates->resolvePersonRate($person, $period);
                }
            }

            if ($assignment && (float) $assignment->project_value > 0) {
                $projectValueAuto = (float) $this->hourlyRates->resolveAssignmentProjectValue($assignment, $period);
                $projectValueSource = [
                    'type' => 'assignment',
                    'code' => $assignment->code,
                    'project_name' => $assignment->project?->name ?: $project?->name,
                    'rate_value' => $assignment->project_value,
                    'rate_unit_type' => strtoupper((string) ($assignment->hourly_rate_unit_type ?: 'CURRENCY')),
                    'currency' => $assignment->hourlyRateDisplayCurrency ?? 'CLP',
                ];
            }
        }

        return [
            'assignment' => $assignment,
            'assignment_context' => $assignmentContext,
            'project' => $project,
            'project_id' => $project?->id,
            'time_entries' => $timeEntries,
            'hours_approved_auto' => $hoursApprovedAuto,
            'monthly_value_auto' => $monthlyValueAuto,
            'hourly_value_auto' => $hourlyValueAuto,
            'hourly_value_source' => $hourlyValueSource,
            'hourly_value_legacy_auto' => $hourlyValueLegacyAuto,
            'project_value_auto' => $projectValueAuto,
            'project_value_source' => $projectValueSource,
            'health_additional_auto' => $healthAdditionalAuto,
            'notes' => $notes,
            'requires_review' => $requiresReview,
            'project_display' => $isHourly
                ? $hourlyConsumption['project_display']
                : ($project ? trim((string) ($project->code ?: $project->name)) : '—'),
            'client_display' => $isHourly
                ? $hourlyConsumption['client_display']
                : ($project?->client?->legal_name ?? '—'),
        ];
    }

    public function hourlyPayrollConsumption(Person $person, CarbonInterface|string $periodDate, ?int $projectId = null): array
    {
        $period = Carbon::parse($periodDate)->startOfMonth();
        $timeEntries = $this->payrollApprovedTimeEntries($person->company_id, $person->id, $period, $projectId);
        $projects = $timeEntries
            ->filter(fn (TimeEntry $entry): bool => $entry->project !== null)
            ->unique(fn (TimeEntry $entry): string => (string) $entry->project_id)
            ->map(fn (TimeEntry $entry) => $entry->project)
            ->values();
        $assignments = $timeEntries
            ->filter(fn (TimeEntry $entry): bool => $entry->assignment !== null)
            ->unique(fn (TimeEntry $entry): string => (string) $entry->assignment_id)
            ->map(fn (TimeEntry $entry) => $entry->assignment)
            ->values();
        $project = $projects->count() === 1 ? $projects->first() : null;
        $assignment = $assignments->count() === 1 ? $assignments->first() : null;
        $clients = $projects
            ->filter(fn ($entryProject) => $entryProject?->client !== null)
            ->unique(fn ($entryProject): string => (string) $entryProject->client_id)
            ->map(fn ($entryProject) => $entryProject->client)
            ->values();

        return [
            'time_entries' => $timeEntries,
            'project' => $project,
            'project_id' => $project?->id,
            'projects' => $projects,
            'project_ids' => $projects->pluck('id')->map(fn ($id): int => (int) $id)->values(),
            'multiple_projects_used' => $projects->count() > 1,
            'project_display' => match (true) {
                $projects->count() === 1 => trim((string) ($project?->code ?: $project?->name ?: '—')),
                $projects->count() > 1 => 'Varios proyectos',
                default => '—',
            },
            'client_display' => match (true) {
                $clients->count() === 1 => (string) ($clients->first()?->legal_name ?: '—'),
                $clients->count() > 1 => 'Varios clientes',
                default => '—',
            },
            'assignment' => $assignment,
            'assignments' => $assignments,
            'assignment_ids' => $assignments->pluck('id')->map(fn ($id): int => (int) $id)->values(),
            'multiple_assignments_used' => $assignments->count() > 1,
            'assignment_display' => match (true) {
                $assignments->count() === 1 => trim((string) (($assignment?->code ?: 'ASI').' · '.($assignment?->project?->name ?: $project?->name ?: 'No informado'))),
                $assignments->count() > 1 => 'Múltiples asignaciones',
                default => 'No configurada',
            },
            'assignment_range_display' => match (true) {
                $assignments->count() === 1 => $this->payrollAssignmentRangeLabel($assignment),
                $assignments->count() > 1 => 'Según las horas remuneradas del período',
                default => 'No configurada',
            },
        ];
    }

    private function payrollAssignmentContext(int $companyId, int $personId, Carbon $period, ?int $projectId = null): array
    {
        $periodEnd = $period->copy()->endOfMonth();

        $query = ProjectAssignment::query()
            ->where('company_id', $companyId)
            ->where('person_id', $personId)
            ->whereHas('assignmentStatus', fn ($builder) => $builder->where('code', 'active'))
            ->where(function ($builder) use ($periodEnd) {
                $builder->whereNull('start_date')->orWhereDate('start_date', '<=', $periodEnd);
            })
            ->where(function ($builder) use ($period) {
                $builder->whereNull('end_date')->orWhereDate('end_date', '>=', $period->toDateString());
            })
            ->with(['project.client', 'assignmentStatus:id,code', 'hourlyRateCurrency:id,code,symbol,minor_units', 'costCenter:id,name'])
            ->orderBy('start_date')
            ->orderBy('id');

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        $assignments = $query->get();

        return [
            'assignment' => $assignments->count() === 1 ? $assignments->first() : null,
            'assignments' => $assignments,
            'ambiguous' => $assignments->count() > 1,
        ];
    }

    private function payrollApprovedTimeEntries(int $companyId, int $personId, Carbon $period, ?int $projectId = null): Collection
    {
        $query = TimeEntry::query()
            ->forCompany($companyId)
            ->with(['approvalStatus', 'project.client', 'assignment.project', 'assignment.assignmentStatus'])
            ->where('person_id', $personId)
            ->whereBetween('entry_date', [$period->toDateString(), $period->copy()->endOfMonth()->toDateString()])
            ->where('hours_approved', '>', 0);

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        return $query->get()->filter(fn (TimeEntry $entry): bool => $this->isApproved($entry))->values();
    }

    public function syncHourlyTimeEntryTrace(PayrollRecord $record, ?Person $person = null): void
    {
        $person ??= $record->relationLoaded('person')
            ? $record->person
            : Person::query()->forCompany($record->company_id)->findOrFail($record->person_id);

        if (! $record->period_date) {
            $record->timeEntries()->sync([]);

            return;
        }

        if (! $this->modalityFlags($person)['is_hourly']) {
            $record->timeEntries()->sync([]);

            return;
        }

        $timeEntryIds = $this->hourlyPayrollTimeEntries($person, $record->period_date, $record->project_id)
            ->modelKeys();

        $this->guardHourlyTimeEntryTraceConflicts($record, $timeEntryIds);
        $record->timeEntries()->sync($timeEntryIds);
    }

    private function guardHourlyTimeEntryTraceConflicts(PayrollRecord $record, array $timeEntryIds): void
    {
        if ($timeEntryIds === []) {
            return;
        }

        $conflicts = DB::table('payroll_record_time_entries')
            ->join('payroll_records', 'payroll_records.id', '=', 'payroll_record_time_entries.payroll_record_id')
            ->whereIn('payroll_record_time_entries.time_entry_id', $timeEntryIds)
            ->where('payroll_record_time_entries.payroll_record_id', '!=', $record->id)
            ->where('payroll_records.company_id', $record->company_id)
            ->exists();

        if ($conflicts) {
            throw new DomainException('Una o más horas aprobadas ya están asociadas a otra remuneración.');
        }
    }

    private function isApproved(TimeEntry $entry): bool
    {
        $code = strtolower((string) ($entry->approvalStatus?->code ?: $entry->approval_status));

        return in_array($code, ['approved', 'aprobado'], true);
    }

    private function payrollNumericValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', preg_replace('/\s+/', '', (string) $value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function payrollEffectiveNumeric(mixed $override, ?float $automatic): ?float
    {
        $parsed = $this->payrollNumericValue($override);
        if ($parsed !== null) {
            return $parsed;
        }

        return $automatic;
    }

    public function calculate(Person $person, CarbonInterface|string $periodDate, array $data = []): array
    {
        $period = Carbon::parse($periodDate)->startOfMonth();
        $person->loadMissing(['employmentMode', 'employmentContractType', 'afp', 'healthSystemCatalog']);
        $modeCode = strtoupper((string) $person->employmentMode?->code);
        $contractCode = strtoupper((string) $person->employmentContractType?->code);
        $modality = mb_strtolower((string) ($modeCode ?: $person->modality));
        $monthDays = $period->daysInMonth;
        $workedDays = null;
        $amountBasis = strtoupper((string) ($data['amount_basis'] ?? 'GROSS')) === 'NET' ? 'NET' : 'GROSS';
        $projectId = isset($data['project_id']) && $data['project_id'] !== '' ? (int) $data['project_id'] : null;
        $context = $this->payrollContext($person, $period, $projectId);
        $hoursApprovedAuto = $context['hours_approved_auto'];
        $hoursApproved = $this->payrollEffectiveNumeric($data['hours_approved'] ?? null, $hoursApprovedAuto) ?? 0.0;
        $monthlyValue = $this->payrollEffectiveNumeric($data['monthly_value'] ?? null, $context['monthly_value_auto']);
        $hourlyValue = $this->payrollEffectiveNumeric($data['hourly_value'] ?? null, $context['hourly_value_auto']);
        $projectValue = $this->payrollEffectiveNumeric($data['project_value'] ?? null, $context['project_value_auto']);
        $healthAdditional = $this->payrollEffectiveNumeric($data['health_additional'] ?? null, $context['health_additional_auto']);
        $notes = array_values(array_filter($context['notes']));
        $requiresReview = (bool) $context['requires_review'];

        $isDependent = $this->isDependent($modeCode, $contractCode, $modality);
        $isHourly = $this->isHourly($modeCode, $modality);
        $isProject = $this->isProject($modeCode, $modality);

        if ($isHourly && $hoursApproved <= 0) {
            $notes[] = 'Sin horas aprobadas en el período.';
        }

        if ($isHourly && ($hourlyValue === null || $hourlyValue <= 0)) {
            $notes[] = 'Tarifa de remuneración por hora no configurada en la ficha de Personal.';
            $requiresReview = true;
        }

        if ($isProject && ($projectValue === null || $projectValue <= 0)) {
            $notes[] = 'Valor proyecto/hito no configurado para la asignación vigente.';
            $requiresReview = true;
        }

        if ($isDependent && ! $isHourly && ($monthlyValue === null || $monthlyValue <= 0)) {
            $notes[] = 'Base mensual no configurada para el período.';
            $requiresReview = true;
        }

        if ($context['assignment_context']['ambiguous'] && ! $isHourly) {
            $projectValue = 0.0;
            if ($isProject) {
                $hourlyValue = 0.0;
            }
        }

        $data['hours_approved'] = $hoursApproved;
        $data['monthly_value'] = $monthlyValue;
        $data['hourly_value'] = $hourlyValue;
        $data['project_value'] = $projectValue;
        $data['health_additional'] = $healthAdditional;
        $calculationNotes = array_values(array_unique(array_filter($notes)));
        $calculationStatus = $requiresReview ? 'REQUIERE_REVISION' : 'OK';

        if ($isDependent) {
            $workedDays = $this->workedDaysInMonth($person, $period);
            $base = $isHourly
                ? round($hoursApproved * (float) ($hourlyValue ?? 0), 2)
                : $this->monthlySalaryForPeriod((float) ($monthlyValue ?? 0), $workedDays, $monthDays);

            return $this->dependentCalculation($person, $period, $monthDays, $workedDays, $base, $data, $calculationStatus, $calculationNotes);
        }

        $gross = $this->honorariosGross($person, $period, $data, $hoursApproved, $isHourly, $isProject);
        $retentionRate = $gross > 0 ? (float) $this->legalParameters->value($person->company_id, 'RETENCION_HONORARIOS', $period) : 0.0;

        if ($amountBasis === 'NET' && $retentionRate < 1 && $gross > 0) {
            $gross = round($gross / (1 - $retentionRate), 2);
        }

        $retention = round($gross * $retentionRate, 2);

        return [
            'worked_days' => $workedDays,
            'month_days' => $monthDays,
            'hours_approved' => $hoursApproved,
            'amount_basis' => $amountBasis,
            'base_salary' => $gross,
            'gross_amount' => $gross,
            'taxable_amount' => 0.0,
            'taxable_gross' => 0.0,
            'employee_retention' => $retention,
            'retention_rate' => $retentionRate,
            'vacation_provision' => 0.0,
            'vacation_days_accrued_period' => 0.0,
            'vacation_daily_value' => 0.0,
            'vacation_provision_amount' => 0.0,
            'employer_cost' => round($gross, 2),
            'net_pay' => round($gross - $retention, 2),
            'legal_snapshot' => [
                'period' => $period->toDateString(),
                'mode' => $modeCode ?: $person->modality,
                'honorarios_retention_rate' => $retentionRate,
            ],
            'calculation_status' => $calculationStatus,
            'calculation_notes' => ! empty($calculationNotes) ? implode(' ', $calculationNotes) : null,
        ] + $this->zeroDependentFields();
    }

    private function dependentCalculation(Person $person, Carbon $period, int $monthDays, int $workedDays, float $base, array $data, string $calculationStatus, array $calculationNotes): array
    {
        $companyId = $person->company_id;
        $bonuses = round((float) ($data['bonuses'] ?? 0), 2);
        $nonTaxable = round((float) ($data['non_taxable_allowances'] ?? 0), 2);
        $advances = round((float) ($data['advances'] ?? 0), 2);
        $otherDeductions = round((float) ($data['other_deductions'] ?? 0), 2);
        $taxableGross = round($base + $bonuses, 2);

        if ($taxableGross <= 0) {
            return [
                'worked_days' => $workedDays,
                'month_days' => $monthDays,
                'hours_approved' => (float) ($data['hours_approved'] ?? 0),
                'hourly_value' => isset($data['hourly_value']) ? (float) $data['hourly_value'] : null,
                'project_value' => isset($data['project_value']) ? (float) $data['project_value'] : null,
                'monthly_value' => isset($data['monthly_value']) ? (float) $data['monthly_value'] : null,
                'health_additional' => (float) ($data['health_additional'] ?? $person->additional_health_plan ?? 0),
                'base_salary' => $base,
                'gross_amount' => $taxableGross,
                'bonuses' => $bonuses,
                'non_taxable_allowances' => $nonTaxable,
                'taxable_amount' => 0.0,
                'taxable_gross' => 0.0,
                'employer_cost' => 0.0,
                'net_pay' => 0.0,
                'legal_snapshot' => ['period' => $period->toDateString()],
                'calculation_status' => $calculationStatus,
                'calculation_notes' => ! empty($calculationNotes) ? implode(' ', array_values(array_unique(array_filter($calculationNotes)))) : null,
            ] + $this->zeroDependentFields();
        }

        $uf = (float) $this->legalParameters->ufValue($companyId, $period);
        $pensionCapUf = (float) $this->legalParameters->value($companyId, 'TOPE_IMPONIBLE_UF', $period);
        $afcCapUf = (float) $this->legalParameters->value($companyId, 'TOPE_AFC_UF', $period);
        $pensionHealthBase = round(min($taxableGross, $pensionCapUf * $uf), 2);
        $afcBase = round(min($taxableGross, $afcCapUf * $uf), 2);

        $afpMandatoryRate = (float) $this->legalParameters->value($companyId, 'AFP_TRABAJADOR', $period);
        $afpRate = $person->afp ? $this->legalParameters->afpRate($person->afp, $period) : [
            'employee_commission_rate' => 0,
            'employer_commission_rate' => 0,
            'insurance_rate' => 0,
        ];
        $afpCommissionRate = (float) $afpRate['employee_commission_rate'];
        $afpMandatory = round($pensionHealthBase * $afpMandatoryRate, 2);
        $afpCommission = round($pensionHealthBase * $afpCommissionRate, 2);

        $healthLegalRate = (float) $this->legalParameters->value($companyId, 'SALUD_MINIMA', $period);
        $healthLegal = round($pensionHealthBase * $healthLegalRate, 2);
        $healthAdditional = round((float) ($data['health_additional'] ?? $person->additional_health_plan ?? 0), 2);
        $healthEmployee = round($healthLegal + $healthAdditional, 2);

        [$afcEmployeeRate, $afcEmployerRate] = $this->afcRates($companyId, $period, $person);
        $afcEmployee = round($afcBase * $afcEmployeeRate, 2);
        $afcEmployer = round($afcBase * $afcEmployerRate, 2);

        $sisRate = (float) $this->legalParameters->value($companyId, 'SIS_RATE', $period);
        $employerPensionRate = (float) $this->legalParameters->value($companyId, 'COTIZACION_EMPLEADOR', $period) + $sisRate;
        $employerPension = round($pensionHealthBase * $employerPensionRate, 2);

        $accidentRate = (float) $this->legalParameters->value($companyId, 'LEY_16744_BASICA', $period)
            + (float) ($this->settings->get($companyId, 'additional_accident_rate', null)
                ?? $this->legalParameters->value($companyId, 'LEY_16744_ADICIONAL', $period));
        $accidentInsurance = round($pensionHealthBase * $accidentRate, 2);

        $sannaRate = (float) $this->legalParameters->value($companyId, 'SANNA_RATE', $period);
        $sanna = round($pensionHealthBase * $sannaRate, 2);

        $iuscBase = round(max(0, $taxableGross - $afpMandatory - $afpCommission - $healthEmployee - $afcEmployee), 2);
        $iusc = $this->incomeTax->calculate($iuscBase, $period);

        $vacationDailyExact = (float) $person->monthly_value / 30;
        $vacationDailyValue = round($vacationDailyExact, 2);
        $vacationDays = $workedDays >= $monthDays ? 1.25 : round($workedDays * (1.25 / 30), 4);
        $vacationProvision = round($vacationDays * $vacationDailyExact, 2);

        $netPay = round(
            $taxableGross + $nonTaxable
            - $afpMandatory - $afpCommission - $healthEmployee - $afcEmployee
            - $iusc['iusc_amount'] - $advances - $otherDeductions,
            2
        );

        $employerCost = round(
            $taxableGross + $nonTaxable
            + $afcEmployer + $employerPension + $accidentInsurance + $sanna + $vacationProvision,
            2
        );

        return [
            'worked_days' => $workedDays,
            'month_days' => $monthDays,
            'hours_approved' => (float) ($data['hours_approved'] ?? 0),
            'hourly_value' => isset($data['hourly_value']) ? (float) $data['hourly_value'] : null,
            'project_value' => isset($data['project_value']) ? (float) $data['project_value'] : null,
            'monthly_value' => isset($data['monthly_value']) ? (float) $data['monthly_value'] : null,
            'health_additional' => (float) ($data['health_additional'] ?? $person->additional_health_plan ?? 0),
            'amount_basis' => 'GROSS',
            'base_salary' => $base,
            'gross_amount' => $taxableGross,
            'bonuses' => $bonuses,
            'non_taxable_allowances' => $nonTaxable,
            'taxable_amount' => $taxableGross,
            'taxable_gross' => $taxableGross,
            'pension_health_base' => $pensionHealthBase,
            'afc_base' => $afcBase,
            'uf_value' => $uf,
            'pension_cap_uf' => $pensionCapUf,
            'afc_cap_uf' => $afcCapUf,
            'employee_retention' => 0.0,
            'retention_rate' => 0.0,
            'afp_mandatory' => $afpMandatory,
            'afp_commission_rate' => $afpCommissionRate,
            'afp_commission' => $afpCommission,
            'health_legal' => $healthLegal,
            'health_additional' => $healthAdditional,
            'health_employee' => $healthEmployee,
            'afc_employee_rate' => $afcEmployeeRate,
            'afc_employee' => $afcEmployee,
            'afc_employer_rate' => $afcEmployerRate,
            'afc_employer' => $afcEmployer,
            'employer_pension_rate' => $employerPensionRate,
            'employer_pension' => $employerPension,
            'accident_insurance_rate' => $accidentRate,
            'accident_insurance' => $accidentInsurance,
            'sanna_rate' => $sannaRate,
            'sanna' => $sanna,
            'advances' => $advances,
            'other_deductions' => $otherDeductions,
            ...$iusc,
            'vacation_provision' => $vacationProvision,
            'vacation_days_accrued_period' => $vacationDays,
            'vacation_daily_value' => $vacationDailyValue,
            'vacation_provision_amount' => $vacationProvision,
            'employer_cost' => $employerCost,
            'net_pay' => $netPay,
            'legal_snapshot' => [
                'period' => $period->toDateString(),
                'uf_value' => $uf,
                'pension_cap_uf' => $pensionCapUf,
                'afc_cap_uf' => $afcCapUf,
                'afp_mandatory_rate' => $afpMandatoryRate,
                'afp_commission_rate' => $afpCommissionRate,
                'health_legal_rate' => $healthLegalRate,
                'afc_employee_rate' => $afcEmployeeRate,
                'afc_employer_rate' => $afcEmployerRate,
                'employer_pension_rate' => $employerPensionRate,
                'sis_rate' => $sisRate,
                'accident_insurance_rate' => $accidentRate,
                'sanna_rate' => $sannaRate,
                'iusc_bracket' => $iusc['iusc_bracket'],
                'iusc_factor' => $iusc['iusc_factor'],
                'iusc_rebate' => $iusc['iusc_rebate'],
            ],
            'calculation_status' => $calculationStatus,
            'calculation_notes' => ! empty($calculationNotes)
                ? implode(' ', array_values(array_unique(array_filter(array_merge(
                    $calculationNotes,
                    $person->afp ? [] : ['AFP no configurada; comisión AFP calculada en 0.'],
                )))))
                : ($person->afp ? null : 'AFP no configurada; comisión AFP calculada en 0.'),
        ];
    }

    private function monthlySalaryForPeriod(float $monthlySalary, int $workedDays, int $monthDays): float
    {
        if ($workedDays >= $monthDays) {
            return round($monthlySalary, 2);
        }

        return round(($monthlySalary / 30) * $workedDays, 2);
    }

    private function honorariosGross(Person $person, Carbon $period, array $data, ?float $hoursApproved, bool $isHourly, bool $isProject): float
    {
        if ($isHourly) {
            return round((float) ($hoursApproved ?? 0) * (float) ($data['hourly_value'] ?? $this->hourlyRates->resolvePersonRate($person, $period)), 2);
        }

        if ($isProject) {
            return round((float) ($data['project_value'] ?? $person->project_value ?? 0), 2);
        }

        return round((float) ($data['monthly_value'] ?? $person->monthly_value ?? 0), 2);
    }

    private function isDependent(string $modeCode, string $contractCode, string $modality): bool
    {
        if ($modeCode === 'DEPENDIENTE_MENSUAL') {
            return true;
        }

        if ($modeCode === 'PAGO_POR_HORA' && in_array($contractCode, ['INDEFINIDO', 'PLAZO_FIJO', 'OBRA_O_FAENA'], true)) {
            return true;
        }

        return str_contains($modality, 'dependiente');
    }

    private function isHourly(string $modeCode, string $modality): bool
    {
        return $modeCode === 'PAGO_POR_HORA' || str_contains($modality, 'hora');
    }

    private function isProject(string $modeCode, string $modality): bool
    {
        return $modeCode === 'POR_PROYECTO' || str_contains($modality, 'proyecto');
    }

    private function afcRates(int $companyId, Carbon $period, Person $person): array
    {
        $contract = strtoupper((string) ($person->employmentContractType?->code ?: $person->contract_type));

        if (in_array($contract, ['PLAZO_FIJO', 'OBRA_O_FAENA'], true)) {
            return [0.0, (float) $this->legalParameters->value($companyId, 'AFC_EMPLEADOR_PLAZO_FIJO', $period)];
        }

        return [
            (float) $this->legalParameters->value($companyId, 'AFC_TRABAJADOR_INDEFINIDO', $period),
            (float) $this->legalParameters->value($companyId, 'AFC_EMPLEADOR_INDEFINIDO', $period),
        ];
    }

    private function zeroDependentFields(): array
    {
        return [
            'pension_health_base' => 0.0,
            'afc_base' => 0.0,
            'uf_value' => null,
            'pension_cap_uf' => null,
            'afc_cap_uf' => null,
            'afp_mandatory' => 0.0,
            'afp_commission_rate' => 0.0,
            'afp_commission' => 0.0,
            'health_legal' => 0.0,
            'health_additional' => 0.0,
            'health_employee' => 0.0,
            'afc_employee_rate' => 0.0,
            'afc_employee' => 0.0,
            'afc_employer_rate' => 0.0,
            'afc_employer' => 0.0,
            'employer_pension_rate' => 0.0,
            'employer_pension' => 0.0,
            'accident_insurance_rate' => 0.0,
            'accident_insurance' => 0.0,
            'sanna_rate' => 0.0,
            'sanna' => 0.0,
            'iusc_taxable_base' => 0.0,
            'iusc_bracket' => null,
            'iusc_factor' => 0.0,
            'iusc_rebate' => 0.0,
            'iusc_amount' => 0.0,
            'advances' => 0.0,
            'other_deductions' => 0.0,
        ];
    }

    public function workedDaysInMonth(Person $person, CarbonInterface|string $periodDate): int
    {
        $period = Carbon::parse($periodDate)->startOfMonth();
        $start = $person->start_date ? Carbon::parse($person->start_date)->max($period) : $period->copy();
        $endOfMonth = $period->copy()->endOfMonth();
        $end = $person->end_date ? Carbon::parse($person->end_date)->min($endOfMonth) : $endOfMonth;

        if ($end->lt($start)) {
            return 0;
        }

        return $start->diffInDays($end) + 1;
    }

    public function paidAmount(PayrollRecord $record, CarbonInterface|string|null $asOf = null): float
    {
        $query = CashMovement::query()
            ->forCompany($record->company_id)
            ->where('source_document_type', 'payroll_record')
            ->where('source_document_code', $record->code)
            ->where('status', 'posted');

        if ($asOf) {
            $query->whereDate('movement_date', '<=', Carbon::parse($asOf)->toDateString());
        }

        return (float) $query->sum('expense');
    }

    public function balance(PayrollRecord $record, CarbonInterface|string|null $asOf = null): float
    {
        return max(0, round((float) $record->net_pay - $this->paidAmount($record, $asOf), 2));
    }

    public function explain(PayrollRecord $record): array
    {
        $record->loadMissing(['person', 'project.client']);
        $snapshot = is_array($record->legal_snapshot ?? null) ? $record->legal_snapshot : [];
        $period = $record->period_date ? UiFormatter::formatDate($record->period_date) : '—';
        $warnings = array_values(array_filter([
            $record->calculation_notes ?? null,
            $record->calculation_status && $record->calculation_status !== 'OK' ? $record->calculation_status : null,
        ]));
        $formState = $this->formState($record);
        $adjustments = $formState['adjustments'];
        $context = $formState['context'];
        $assignment = $context['assignment'];
        $hoursApprovedState = $formState['fields']['hours_approved'];
        $monthlyValueState = $formState['fields']['monthly_value'];
        $hourlyValueState = $formState['fields']['hourly_value'];
        $projectValueState = $formState['fields']['project_value'];

        $formatPayrollSource = function (?array $source, bool $hours = false): string {
            if (! $source) {
                return '—';
            }

            $amount = $source['rate_value'] ?? null;
            if ($amount === null || $amount === '') {
                return '—';
            }

            $currency = $source['currency'] ?? (($source['rate_unit_type'] ?? null) === 'UF' ? 'UF' : 'CLP');
            $formatted = UiFormatter::formatMoney($amount, $currency);

            $label = match ($source['type'] ?? null) {
                'assignment' => trim((string) (($source['code'] ?? 'ASI').' · '.($source['project_name'] ?? 'No informado'))),
                'project' => 'Proyecto · '.trim((string) ($source['project_name'] ?? 'No informado')),
                'person' => 'Ficha de Personal · Valor HH base',
                default => 'No configurado',
            };

            return $hours ? ($formatted.' / HH · '.$label) : ($formatted.' · '.$label);
        };

        $isHonorarios = str_contains(mb_strtolower((string) $record->person?->modality), 'honorarios')
            || (($record->employee_retention ?? 0) > 0 && (float) $record->afp_mandatory === 0.0 && (float) $record->health_employee === 0.0);

        $sourceRows = [
            ['label' => 'Persona', 'value' => $record->person?->full_name ?? '—'],
            ['label' => 'Proyecto', 'value' => $context['project_display'] ?? ($record->project ? trim((string) ($record->project->code ?: $record->project->name)) : '—')],
            ['label' => 'Cliente', 'value' => $context['client_display'] ?? ($record->project?->client?->legal_name ?? '—')],
            ['label' => 'Asignación', 'value' => $context['assignment_context']['assignment_display'] ?? ($assignment?->code ? trim((string) ($assignment->code.' · '.($assignment->project?->name ?: $record->project?->name ?: 'No informado'))) : 'No configurada')],
            ['label' => 'Vigencia asignación', 'value' => $context['assignment_context']['assignment_range_display'] ?? $this->payrollAssignmentRangeLabel($assignment)],
            ['label' => 'Horas aprobadas del período', 'value' => UiFormatter::formatHours($hoursApprovedState['automatic'] ?? 0)],
            ['label' => 'Origen horas', 'value' => 'Módulo Horas'],
            ['label' => 'Override horas', 'value' => $this->payrollOverrideDisplay($hoursApprovedState, true)],
            ['label' => 'Horas aprobadas efectivas', 'value' => UiFormatter::formatHours($hoursApprovedState['effective'] ?? 0)],
            ['label' => 'Tarifa pactada', 'value' => $formatPayrollSource($context['hourly_value_source'], true)],
            ['label' => 'Tarifa convertida', 'value' => $this->payrollEffectiveDisplay($hourlyValueState, false, true)],
            ['label' => 'Tarifa override', 'value' => $this->payrollOverrideDisplay($hourlyValueState, false, true)],
            ['label' => 'Tarifa efectiva', 'value' => $this->payrollEffectiveDisplay($hourlyValueState, false, true)],
            ['label' => 'Valor proyecto/hito pactado', 'value' => $formatPayrollSource($context['project_value_source'])],
            ['label' => 'Valor proyecto/hito convertido', 'value' => $this->payrollEffectiveDisplay($projectValueState, false)],
            ['label' => 'Valor proyecto/hito override', 'value' => $this->payrollOverrideDisplay($projectValueState, false)],
            ['label' => 'Valor proyecto/hito efectivo', 'value' => $this->payrollEffectiveDisplay($projectValueState, false)],
            ['label' => 'Base mensual automática', 'value' => $record->person?->monthly_value !== null ? UiFormatter::formatMoney($record->person?->monthly_value, 'CLP').' · Ficha de Personal' : '—'],
            ['label' => 'Base mensual override', 'value' => $this->payrollOverrideDisplay($monthlyValueState, false)],
            ['label' => 'Base mensual efectiva', 'value' => $this->payrollEffectiveDisplay($monthlyValueState, false)],
            ['label' => 'Salud adicional automática', 'value' => $record->person?->additional_health_plan !== null ? UiFormatter::formatMoney($record->person?->additional_health_plan, 'CLP').' · Ficha de Personal' : '—'],
            ['label' => 'Salud adicional override', 'value' => $adjustments['health_additional'] !== null ? UiFormatter::formatMoney($adjustments['health_additional']).' · Novedades remuneración' : '—'],
            ['label' => 'Salud adicional efectiva', 'value' => UiFormatter::formatMoney($record->health_additional, 'CLP')],
            ['label' => 'Bonos automáticos', 'value' => $adjustments['bonuses'] !== null ? UiFormatter::formatMoney($adjustments['bonuses']).' · Novedades remuneración' : '—'],
            ['label' => 'Bonos aplicados', 'value' => UiFormatter::formatMoney($record->bonuses, 'CLP')],
            ['label' => 'Asignaciones no imponibles automáticas', 'value' => $adjustments['non_taxable_allowances'] !== null ? UiFormatter::formatMoney($adjustments['non_taxable_allowances']).' · Novedades remuneración' : '—'],
            ['label' => 'Asignaciones no imponibles aplicadas', 'value' => UiFormatter::formatMoney($record->non_taxable_allowances, 'CLP')],
            ['label' => 'Anticipos automáticos', 'value' => $adjustments['advances'] !== null ? UiFormatter::formatMoney($adjustments['advances']).' · Novedades remuneración' : '—'],
            ['label' => 'Anticipos aplicados', 'value' => UiFormatter::formatMoney($record->advances, 'CLP')],
            ['label' => 'Otros descuentos automáticos', 'value' => $adjustments['other_deductions'] !== null ? UiFormatter::formatMoney($adjustments['other_deductions']).' · Novedades remuneración' : '—'],
            ['label' => 'Otros descuentos aplicados', 'value' => UiFormatter::formatMoney($record->other_deductions, 'CLP')],
        ];

        if ($isHonorarios) {
            $parameters = [];
            if (isset($snapshot['honorarios_retention_rate'])) {
                $parameters[] = [
                    'label' => 'Retención honorarios',
                    'value' => UiFormatter::formatPercent($snapshot['honorarios_retention_rate']),
                    'validity' => $period,
                    'source' => 'Parámetro legal vigente',
                ];
            }

            return [
                'result' => [
                    'label' => 'Líquido honorarios',
                    'value' => UiFormatter::formatMoney($record->net_pay),
                    'note' => ($snapshot['period'] ?? null)
                        ? 'Cálculo confirmado con parámetros del período. Los valores automáticos provienen de Horas, Novedades remuneración, la asignación vigente cuando existe y la ficha de Personal como referencia.'
                        : null,
                ],
                'warnings' => $warnings,
                'sections' => [
                    [
                        'title' => 'Fuentes aplicadas',
                        'rows' => $sourceRows,
                    ],
                    [
                        'title' => 'Honorarios',
                        'rows' => [
                            ['label' => 'Período', 'value' => $period],
                            ['label' => 'Base bruta', 'value' => UiFormatter::formatMoney($record->base_salary)],
                            ['label' => '% Retención', 'value' => UiFormatter::formatPercent($snapshot['honorarios_retention_rate'] ?? $record->retention_rate ?? 0)],
                            ['label' => 'Retención', 'value' => UiFormatter::formatMoney($record->employee_retention)],
                            ['label' => 'Líquido', 'value' => UiFormatter::formatMoney($record->net_pay), 'strong' => true],
                        ],
                    ],
                ],
                'parameters' => $parameters,
            ];
        }

        $deductionRows = [];
        foreach ([
            ['label' => 'AFP 10%', 'amount' => (float) $record->afp_mandatory],
            ['label' => 'Comisión AFP', 'amount' => (float) $record->afp_commission],
            ['label' => 'Salud', 'amount' => (float) $record->health_employee],
            ['label' => 'AFC trabajador', 'amount' => (float) $record->afc_employee],
            ['label' => 'IUSC', 'amount' => (float) $record->iusc_amount],
        ] as $row) {
            if ($row['amount'] > 0) {
                $deductionRows[] = ['label' => $row['label'], 'value' => UiFormatter::formatMoney($row['amount'])];
            }
        }

        $employerRows = [];
        foreach ([
            ['label' => 'AFC empleador', 'amount' => (float) $record->afc_employer],
            ['label' => 'Aporte previsional', 'amount' => (float) $record->employer_pension],
            ['label' => 'Ley 16.744', 'amount' => (float) $record->accident_insurance],
            ['label' => 'SANNA', 'amount' => (float) $record->sanna],
            ['label' => 'Vacaciones', 'amount' => (float) $record->vacation_provision_amount],
        ] as $row) {
            if ($row['amount'] > 0) {
                $employerRows[] = ['label' => $row['label'], 'value' => UiFormatter::formatMoney($row['amount'])];
            }
        }

        $parameters = [];
        foreach ([
            isset($snapshot['period']) ? ['label' => 'Período', 'value' => UiFormatter::formatDate($snapshot['period']), 'validity' => 'Período del snapshot', 'source' => 'Snapshot'] : null,
            isset($snapshot['uf_value']) ? ['label' => 'UF', 'value' => UiFormatter::formatUf($snapshot['uf_value']), 'validity' => $period, 'source' => 'Snapshot'] : null,
            isset($snapshot['pension_cap_uf']) ? ['label' => 'Tope previsional', 'value' => UiFormatter::formatUf($snapshot['pension_cap_uf']), 'validity' => $period, 'source' => 'Snapshot'] : null,
            isset($snapshot['afc_cap_uf']) ? ['label' => 'Tope AFC', 'value' => UiFormatter::formatUf($snapshot['afc_cap_uf']), 'validity' => $period, 'source' => 'Snapshot'] : null,
            isset($snapshot['afp_mandatory_rate']) ? ['label' => 'AFP trabajador', 'value' => UiFormatter::formatPercent($snapshot['afp_mandatory_rate']), 'validity' => $period, 'source' => 'Snapshot'] : null,
            isset($snapshot['afp_commission_rate']) ? ['label' => 'Comisión AFP', 'value' => UiFormatter::formatPercent($snapshot['afp_commission_rate']), 'validity' => $period, 'source' => 'Snapshot'] : null,
            isset($snapshot['health_legal_rate']) ? ['label' => 'Salud legal', 'value' => UiFormatter::formatPercent($snapshot['health_legal_rate']), 'validity' => $period, 'source' => 'Snapshot'] : null,
            isset($snapshot['afc_employee_rate']) ? ['label' => 'AFC trabajador', 'value' => UiFormatter::formatPercent($snapshot['afc_employee_rate']), 'validity' => $period, 'source' => 'Snapshot'] : null,
            isset($snapshot['afc_employer_rate']) ? ['label' => 'AFC empleador', 'value' => UiFormatter::formatPercent($snapshot['afc_employer_rate']), 'validity' => $period, 'source' => 'Snapshot'] : null,
            isset($snapshot['employer_pension_rate']) ? ['label' => 'Aporte previsional', 'value' => UiFormatter::formatPercent($snapshot['employer_pension_rate']), 'validity' => $period, 'source' => 'Snapshot'] : null,
            isset($snapshot['accident_insurance_rate']) ? ['label' => 'Ley 16.744', 'value' => UiFormatter::formatPercent($snapshot['accident_insurance_rate']), 'validity' => $period, 'source' => 'Snapshot'] : null,
            isset($snapshot['sanna_rate']) ? ['label' => 'SANNA', 'value' => UiFormatter::formatPercent($snapshot['sanna_rate']), 'validity' => $period, 'source' => 'Snapshot'] : null,
            isset($snapshot['iusc_bracket']) ? ['label' => 'IUSC', 'value' => (string) $snapshot['iusc_bracket'], 'validity' => $period, 'source' => 'Tabla SII'] : null,
        ] as $parameter) {
            if ($parameter) {
                $parameters[] = $parameter;
            }
        }

        return [
            'result' => [
                'label' => 'Costo empresa',
                'value' => UiFormatter::formatMoney($record->employer_cost),
                'note' => ($snapshot['period'] ?? null)
                    ? 'Cálculo confirmado con parámetros del período. Los valores automáticos provienen de Horas, Novedades remuneración, la asignación vigente cuando existe y la ficha de Personal como referencia.'
                    : null,
            ],
            'warnings' => $warnings,
            'sections' => [
                [
                    'title' => 'Fuentes aplicadas',
                    'rows' => $sourceRows,
                ],
                [
                    'title' => 'Remuneración',
                    'rows' => [
                        ['label' => 'Período', 'value' => $period],
                        ['label' => 'Sueldo imponible', 'value' => UiFormatter::formatMoney($record->taxable_gross)],
                        ['label' => 'No imponibles', 'value' => UiFormatter::formatMoney($record->non_taxable_allowances)],
                        ['label' => 'Total imponible', 'value' => UiFormatter::formatMoney($record->taxable_gross), 'strong' => true],
                    ],
                ],
                [
                    'title' => 'Descuentos legales',
                    'rows' => $deductionRows,
                ],
                [
                    'title' => 'Aportes empleador y provisiones',
                    'rows' => $employerRows,
                ],
                [
                    'title' => 'Líquido',
                    'rows' => [
                        ['label' => 'Líquido a pagar', 'value' => UiFormatter::formatMoney($record->net_pay), 'strong' => true],
                        ['label' => 'Costo empresa', 'value' => UiFormatter::formatMoney($record->employer_cost), 'strong' => true],
                    ],
                ],
            ],
            'parameters' => $parameters,
        ];
    }

    public function formState(?PayrollRecord $record): array
    {
        if (! $record || ! $record->exists || ! $record->person || ! $record->period_date) {
            return $this->emptyPayrollFormState();
        }

        $record->loadMissing(['person', 'project.client']);
        $context = $this->payrollContext($record->person, Carbon::parse($record->period_date)->startOfMonth(), $record->project_id);
        $adjustments = $this->payrollAdjustmentTotals($record);

        return [
            'context' => $context,
            'adjustments' => $adjustments,
            'fields' => [
                'hours_approved' => $this->payrollFieldState($record->hours_approved, $adjustments['hours_approved'], $context['hours_approved_auto'], 2),
                'monthly_value' => $this->payrollFieldState($record->monthly_value, $adjustments['monthly_value'], $context['monthly_value_auto'], 2),
                'hourly_value' => $this->payrollFieldState(
                    $record->hourly_value,
                    $adjustments['hourly_value'],
                    $context['hourly_value_auto'],
                    2,
                    [$context['hourly_value_legacy_auto'] ?? null],
                ),
                'project_value' => $this->payrollFieldState($record->project_value, $adjustments['project_value'], $context['project_value_auto'], 2),
            ],
        ];
    }

    public function manualOverrideInputs(?PayrollRecord $record): array
    {
        $state = $this->formState($record);
        $overrides = [];

        foreach (['hours_approved', 'monthly_value', 'hourly_value', 'project_value'] as $field) {
            $override = $state['fields'][$field]['override'] ?? null;
            if ($override !== null) {
                $overrides[$field] = $override;
            }
        }

        return $overrides;
    }

    private function payrollAdjustmentTotals(PayrollRecord $record): array
    {
        if (! $record->period_date) {
            return [
                'hours_approved' => null,
                'monthly_value' => null,
                'hourly_value' => null,
                'project_value' => null,
                'health_additional' => null,
                'bonuses' => null,
                'non_taxable_allowances' => null,
                'advances' => null,
                'other_deductions' => null,
            ];
        }

        $totals = [
            'hours_approved' => null,
            'monthly_value' => null,
            'hourly_value' => null,
            'project_value' => null,
            'health_additional' => null,
            'bonuses' => null,
            'non_taxable_allowances' => null,
            'advances' => null,
            'other_deductions' => null,
        ];

        PayrollAdjustment::query()
            ->forCompany($record->company_id)
            ->where('person_id', $record->person_id)
            ->whereDate('period_date', optional($record->period_date)->toDateString() ?: null)
            ->where('active', true)
            ->get()
            ->each(function (PayrollAdjustment $adjustment) use (&$totals): void {
                $amount = (float) ($adjustment->amount ?? 0);
                $quantity = (float) ($adjustment->quantity ?? 0);

                match (strtoupper((string) $adjustment->type)) {
                    'HOURS_APPROVED' => $totals['hours_approved'] = round((float) ($totals['hours_approved'] ?? 0) + $quantity, 2),
                    'MONTHLY_VALUE' => $totals['monthly_value'] = round($amount, 2),
                    'HOURLY_VALUE' => $totals['hourly_value'] = round($amount, 2),
                    'PROJECT_VALUE' => $totals['project_value'] = round($amount, 2),
                    'HEALTH_ADDITIONAL' => $totals['health_additional'] = round((float) ($totals['health_additional'] ?? 0) + $amount, 2),
                    'BONUS_TAXABLE' => $totals['bonuses'] = round((float) ($totals['bonuses'] ?? 0) + $amount, 2),
                    'NON_TAXABLE_ALLOWANCE' => $totals['non_taxable_allowances'] = round((float) ($totals['non_taxable_allowances'] ?? 0) + $amount, 2),
                    'ADVANCE' => $totals['advances'] = round((float) ($totals['advances'] ?? 0) + $amount, 2),
                    'OTHER_DEDUCTION' => $totals['other_deductions'] = round((float) ($totals['other_deductions'] ?? 0) + $amount, 2),
                    default => null,
                };
            });

        return $totals;
    }

    private function emptyPayrollFormState(): array
    {
        $emptyField = [
            'automatic' => null,
            'override' => null,
            'effective' => null,
            'stored' => null,
            'has_override' => false,
            'source' => null,
        ];

        return [
            'context' => [
                'assignment' => null,
                'assignment_context' => ['ambiguous' => false, 'multiple_assignments_used' => false, 'assignment_display' => 'No configurada', 'assignment_range_display' => 'No configurada'],
                'project' => null,
                'project_id' => null,
                'time_entries' => collect(),
                'hours_approved_auto' => null,
                'monthly_value_auto' => null,
                'hourly_value_auto' => null,
                'hourly_value_source' => null,
                'hourly_value_legacy_auto' => null,
                'project_value_auto' => null,
                'project_value_source' => null,
                'health_additional_auto' => null,
                'notes' => [],
                'requires_review' => false,
                'project_display' => '—',
                'client_display' => '—',
            ],
            'adjustments' => [
                'hours_approved' => null,
                'monthly_value' => null,
                'hourly_value' => null,
                'project_value' => null,
                'health_additional' => null,
                'bonuses' => null,
                'non_taxable_allowances' => null,
                'advances' => null,
                'other_deductions' => null,
            ],
            'fields' => [
                'hours_approved' => $emptyField,
                'monthly_value' => $emptyField,
                'hourly_value' => $emptyField,
                'project_value' => $emptyField,
            ],
        ];
    }

    private function payrollFieldState(mixed $storedValue, ?float $adjustmentOverride, ?float $automaticValue, int $scale, array $legacyAutomaticCandidates = []): array
    {
        $stored = $this->payrollNumericValue($storedValue);
        $stored = $stored !== null ? round($stored, $scale) : null;
        $automatic = $automaticValue !== null ? round((float) $automaticValue, $scale) : null;
        $override = $adjustmentOverride !== null ? round((float) $adjustmentOverride, $scale) : null;
        $source = null;
        $tolerance = 0.00001;
        $legacyAutomaticCandidates = array_values(array_filter(array_map(
            fn (mixed $candidate): ?float => $candidate !== null ? round((float) $candidate, $scale) : null,
            $legacyAutomaticCandidates,
        ), static fn (?float $candidate): bool => $candidate !== null));

        if ($override !== null) {
            $source = 'adjustment';
        } elseif ($stored !== null) {
            $matchesAutomatic = $automatic !== null && abs($stored - $automatic) <= $tolerance;
            $matchesZeroFallback = $automatic === null && abs($stored) <= $tolerance;
            $matchesLegacyAutomatic = collect($legacyAutomaticCandidates)
                ->contains(fn (float $candidate): bool => abs($stored - $candidate) <= $tolerance);

            if (! $matchesAutomatic && ! $matchesZeroFallback && ! $matchesLegacyAutomatic) {
                $override = $stored;
                $source = 'record';
            }
        }

        $effective = $override ?? $automatic ?? $stored;

        return [
            'automatic' => $automatic,
            'override' => $override,
            'effective' => $effective,
            'stored' => $stored,
            'has_override' => $override !== null,
            'source' => $source,
        ];
    }

    private function payrollOverrideDisplay(array $state, bool $hours, bool $rate = false): string
    {
        if (($state['override'] ?? null) === null) {
            return 'No informado';
        }

        $formatted = $hours
            ? UiFormatter::formatHours($state['override'])
            : UiFormatter::formatMoney($state['override'], 'CLP').($rate ? ' / HH' : '');

        $origin = ($state['source'] ?? null) === 'adjustment'
            ? 'Novedades remuneración'
            : 'Registro histórico';

        return $hours ? ($formatted.' · '.$origin) : ($formatted.' · '.$origin);
    }

    private function payrollEffectiveDisplay(array $state, bool $hours, bool $rate = false): string
    {
        $value = $state['effective'] ?? null;
        if ($value === null) {
            return 'No configurado';
        }

        return $hours
            ? UiFormatter::formatHours($value)
            : UiFormatter::formatMoney($value, 'CLP').($rate ? ' / HH' : '');
    }

    private function payrollAssignmentForRecord(PayrollRecord $record): ?ProjectAssignment
    {
        if (! $record->period_date) {
            return null;
        }

        $query = ProjectAssignment::query()
            ->where('company_id', $record->company_id)
            ->where('person_id', $record->person_id)
            ->whereHas('assignmentStatus', fn ($query) => $query->where('code', 'active'))
            ->where(function ($query) use ($record) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', optional($record->period_date)->copy()->endOfMonth()->toDateString());
            })
            ->where(function ($query) use ($record) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', optional($record->period_date)->toDateString());
            })
            ->with(['project', 'hourlyRateCurrency:id,code,symbol,minor_units'])
            ->orderBy('start_date')
            ->orderBy('id');

        if ($record->project_id) {
            $assignments = $query->where('project_id', $record->project_id)->get();

            return $assignments->count() === 1 ? $assignments->first() : null;
        }

        $assignments = $query->get();

        return $assignments->count() === 1 ? $assignments->first() : null;
    }

    private function payrollAssignmentRangeLabel(?ProjectAssignment $assignment): string
    {
        if (! $assignment) {
            return 'No configurada';
        }

        $start = $assignment->start_date ? UiFormatter::formatDate($assignment->start_date) : 'sin inicio informado';
        $end = $assignment->end_date ? UiFormatter::formatDate($assignment->end_date) : 'sin término informado';

        if ($assignment->start_date && $assignment->end_date) {
            return $start.' al '.$end;
        }

        if ($assignment->start_date) {
            return 'desde '.$start;
        }

        if ($assignment->end_date) {
            return 'hasta '.$end;
        }

        return 'No informada';
    }

    private function payrollSourceDisplay(mixed $value, ?string $source = null, bool $hours = false, mixed $currency = 'CLP'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $formatted = $hours ? UiFormatter::formatHours($value) : UiFormatter::formatMoney($value, $currency);

        return $source ? $formatted.' · '.$source : $formatted;
    }

    public function deriveStatus(PayrollRecord $record, CarbonInterface|string|null $asOf = null): string
    {
        if (($record->calculation_status ?? null) && strtoupper((string) $record->calculation_status) !== 'OK') {
            return 'Requiere revisión';
        }

        $paid = $this->paidAmount($record, $asOf);
        $balance = $this->balance($record, $asOf);

        if ($balance <= 0.00001 && (float) $record->net_pay > 0) {
            return 'Pagado';
        }

        if ($paid > 0) {
            return 'Parcial';
        }

        if (! $record->payment_date) {
            return self::STATUS_PENDING_PAYMENT_DATE;
        }

        $date = $asOf ? Carbon::parse($asOf) : now();

        return Carbon::parse($record->payment_date)->lt($date->startOfDay()) ? 'Vencido' : 'Pendiente';
    }

    public function refreshStatus(PayrollRecord $record): PayrollRecord
    {
        $record->forceFill([
            'status' => $this->deriveStatus($record),
        ])->save();

        return $record->refresh();
    }
}
