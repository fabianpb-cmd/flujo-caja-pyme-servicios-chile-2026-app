<?php

namespace App\Services;

use App\Models\PayrollAdjustment;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\ProjectAssignment;
use App\Support\MassAssignment;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollBatchService
{
    private const LOCKED_STATUSES = ['confirmado', 'pagado', 'cerrado'];

    public function __construct(private readonly PayrollService $payroll)
    {
    }

    public function generate(int $companyId, CarbonInterface|string $periodDate, bool $recalculateDrafts = false): array
    {
        $period = Carbon::parse($periodDate)->startOfMonth();
        $periodEnd = $period->copy()->endOfMonth();
        $summary = [
            'period' => $period->isoFormat('MMMM YYYY'),
            'period_date' => $period->toDateString(),
            'evaluated' => 0,
            'generated' => 0,
            'updated' => 0,
            'omitted' => 0,
            'warnings' => 0,
            'errors' => 0,
            'gross_total' => 0.0,
            'net_total' => 0.0,
            'employer_cost_total' => 0.0,
            'honorarios_retention_total' => 0.0,
            'messages' => [],
        ];

        $people = Person::query()
            ->forCompany($companyId)
            ->with(['employmentMode', 'employmentContractType', 'afp', 'healthSystemCatalog', 'workerStatus'])
            ->where(function ($query) use ($periodEnd) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $periodEnd);
            })
            ->where(function ($query) use ($period) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $period);
            })
            ->get()
            ->filter(fn (Person $person): bool => $this->personIsEligible($person))
            ->values();

        foreach ($people as $person) {
            $summary['evaluated']++;
            $existing = PayrollRecord::query()
                ->forCompany($companyId)
                ->where('person_id', $person->id)
                ->whereDate('period_date', $period->toDateString())
                ->first();

            if ($existing && $this->isLocked($existing)) {
                $summary['omitted']++;
                $summary['messages'][] = "{$person->full_name}: remuneración existente confirmada/pagada/cerrada, omitida.";
                continue;
            }

            if ($existing && ! $recalculateDrafts && ! $this->isDraftLike($existing)) {
                $summary['omitted']++;
                $summary['messages'][] = "{$person->full_name}: estado no recalculable ({$existing->status}), omitida.";
                continue;
            }

            $assignments = $this->validAssignments($person, $period, $periodEnd);
            $data = $this->adjustmentData($companyId, $person->id, $period);
            $projectId = $assignments->count() === 1 ? $assignments->first()->project_id : null;
            if ($existing) {
                $data = array_merge($data, $this->payroll->manualOverrideInputs($existing));
                $projectId ??= $existing->project_id;
            }
            $notes = [];

            if ($assignments->count() > 1) {
                $notes[] = 'Proyecto pendiente: persona con múltiples asignaciones válidas en el período.';
            }

            try {
                $calculation = $this->payroll->calculate($person, $period, $data);
                $calculationStatus = ($calculation['calculation_status'] ?? 'OK') === 'REQUIERE_REVISION' || ! empty($notes)
                    ? 'REQUIERE_REVISION'
                    : 'OK';
                $status = $calculationStatus === 'REQUIERE_REVISION' ? 'Requiere revisión' : 'Borrador';
                $payload = array_merge($data, $calculation, [
                    'company_id' => $companyId,
                    'person_id' => $person->id,
                    'project_id' => $projectId,
                    'period_date' => $period->toDateString(),
                    'status' => $status,
                    'calculation_status' => $calculationStatus,
                    'calculation_notes' => $this->mergeNotes($calculation['calculation_notes'] ?? null, $notes),
                ]);
            } catch (DomainException $exception) {
                $payload = array_merge($this->zeroPayload(), $data, [
                    'company_id' => $companyId,
                    'person_id' => $person->id,
                    'project_id' => $projectId,
                    'period_date' => $period->toDateString(),
                    'status' => 'Requiere revisión',
                    'calculation_status' => 'REQUIERE_REVISION',
                    'calculation_notes' => $this->mergeNotes($exception->getMessage(), $notes),
                ]);
            } catch (\Throwable $exception) {
                $summary['errors']++;
                $summary['messages'][] = "{$person->full_name}: ".$exception->getMessage();
                continue;
            }

            $record = DB::transaction(function () use ($existing, $payload): PayrollRecord {
                if ($existing) {
                    MassAssignment::fillAndSave($existing, $payload);

                    return $existing->refresh();
                }

                return MassAssignment::create(PayrollRecord::class, $payload)->refresh();
            });

            $existing ? $summary['updated']++ : $summary['generated']++;

            if ($record->calculation_status === 'REQUIERE_REVISION') {
                $summary['warnings']++;
            }

            $summary['gross_total'] += (float) $record->gross_amount;
            $summary['net_total'] += (float) $record->net_pay;
            $summary['employer_cost_total'] += (float) $record->employer_cost;
            $summary['honorarios_retention_total'] += (float) $record->employee_retention;
        }

        return $summary;
    }

    private function personIsEligible(Person $person): bool
    {
        $statusCode = strtolower((string) $person->workerStatus?->code);
        if ($statusCode !== '') {
            return $statusCode === 'active';
        }

        return strtolower((string) $person->status ?: 'active') === 'active';
    }

    private function validAssignments(Person $person, Carbon $period, Carbon $periodEnd)
    {
        return ProjectAssignment::query()
            ->where('company_id', $person->company_id)
            ->where('person_id', $person->id)
            ->with(['assignmentStatus:id,code'])
            ->where(function ($query) use ($periodEnd) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $periodEnd);
            })
            ->where(function ($query) use ($period) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $period);
            })
            ->get()
            ->filter(fn (ProjectAssignment $assignment): bool => strtolower((string) $assignment->assignmentStatus?->code ?: (string) $assignment->status) === 'active')
            ->values();
    }

    private function adjustmentData(int $companyId, int $personId, Carbon $period): array
    {
        $data = [
            'bonuses' => 0,
            'non_taxable_allowances' => 0,
            'advances' => 0,
            'other_deductions' => 0,
        ];

        PayrollAdjustment::query()
            ->forCompany($companyId)
            ->where('person_id', $personId)
            ->whereDate('period_date', $period->toDateString())
            ->where('active', true)
            ->get()
            ->each(function (PayrollAdjustment $adjustment) use (&$data): void {
                $amount = (float) ($adjustment->amount ?? 0);
                $quantity = (float) ($adjustment->quantity ?? 0);

                match (strtoupper((string) $adjustment->type)) {
                    'BONUS_TAXABLE' => $data['bonuses'] += $amount,
                    'NON_TAXABLE_ALLOWANCE' => $data['non_taxable_allowances'] += $amount,
                    'ADVANCE' => $data['advances'] += $amount,
                    'OTHER_DEDUCTION' => $data['other_deductions'] += $amount,
                    'HOURS_APPROVED' => $data['hours_approved'] = ($data['hours_approved'] ?? 0) + $quantity,
                    'HEALTH_ADDITIONAL' => $data['health_additional'] = ($data['health_additional'] ?? 0) + $amount,
                    'MONTHLY_VALUE' => $data['monthly_value'] = $amount,
                    'HOURLY_VALUE' => $data['hourly_value'] = $amount,
                    'PROJECT_VALUE' => $data['project_value'] = $amount,
                    default => null,
                };
            });

        return $data;
    }

    private function isLocked(PayrollRecord $record): bool
    {
        return in_array(strtolower((string) $record->status), self::LOCKED_STATUSES, true);
    }

    private function isDraftLike(PayrollRecord $record): bool
    {
        return in_array(strtolower((string) $record->status), [
            'borrador',
            'requiere revisión',
            'requiere revision',
            'pendiente',
            strtolower(\App\Services\PayrollService::STATUS_PENDING_PAYMENT_DATE),
            'falta fecha',
        ], true);
    }

    private function mergeNotes(?string $primary, array $notes): ?string
    {
        $all = collect([$primary])->merge($notes)->filter()->unique()->values();

        return $all->isEmpty() ? null : $all->implode(' ');
    }

    private function zeroPayload(): array
    {
        return [
            'base_salary' => 0,
            'gross_amount' => 0,
            'taxable_amount' => 0,
            'taxable_gross' => 0,
            'pension_health_base' => 0,
            'afc_base' => 0,
            'employee_retention' => 0,
            'retention_rate' => 0,
            'afp_mandatory' => 0,
            'afp_commission_rate' => 0,
            'afp_commission' => 0,
            'health_legal' => 0,
            'health_employee' => 0,
            'afc_employee_rate' => 0,
            'afc_employee' => 0,
            'afc_employer_rate' => 0,
            'afc_employer' => 0,
            'employer_pension_rate' => 0,
            'employer_pension' => 0,
            'accident_insurance_rate' => 0,
            'accident_insurance' => 0,
            'sanna_rate' => 0,
            'sanna' => 0,
            'iusc_taxable_base' => 0,
            'iusc_factor' => 0,
            'iusc_rebate' => 0,
            'iusc_amount' => 0,
            'vacation_provision' => 0,
            'vacation_days_accrued_period' => 0,
            'vacation_daily_value' => 0,
            'vacation_provision_amount' => 0,
            'employer_cost' => 0,
            'net_pay' => 0,
            'legal_snapshot' => null,
        ];
    }
}
