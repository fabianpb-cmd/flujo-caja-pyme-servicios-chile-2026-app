<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Person;
use App\Models\PayrollRecord;
use App\Support\UiFormatter;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class PayrollService
{
    public function __construct(
        private readonly LegalParameterService $legalParameters,
        private readonly IncomeTaxService $incomeTax,
        private readonly HourlyRateService $hourlyRates,
        private readonly CompanySettingsService $settings,
    )
    {
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
        $hoursApproved = isset($data['hours_approved']) ? (float) $data['hours_approved'] : null;
        $amountBasis = strtoupper((string) ($data['amount_basis'] ?? 'GROSS')) === 'NET' ? 'NET' : 'GROSS';

        $isDependent = $this->isDependent($modeCode, $contractCode, $modality);
        $isHourly = $this->isHourly($modeCode, $modality);
        $isProject = $this->isProject($modeCode, $modality);

        if ($isDependent) {
            $workedDays = $this->workedDaysInMonth($person, $period);
            $base = $isHourly
                ? round((float) ($hoursApproved ?? 0) * (float) ($data['hourly_value'] ?? $this->hourlyRates->resolvePersonRate($person, $period)), 2)
                : $this->monthlySalaryForPeriod((float) $person->monthly_value, $workedDays, $monthDays);

            return $this->dependentCalculation($person, $period, $monthDays, $workedDays, $base, $data);
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
            'calculation_status' => 'OK',
            'calculation_notes' => null,
        ] + $this->zeroDependentFields();
    }

    private function dependentCalculation(Person $person, Carbon $period, int $monthDays, int $workedDays, float $base, array $data): array
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
                'base_salary' => $base,
                'gross_amount' => $taxableGross,
                'bonuses' => $bonuses,
                'non_taxable_allowances' => $nonTaxable,
                'taxable_amount' => 0.0,
                'taxable_gross' => 0.0,
                'employer_cost' => 0.0,
                'net_pay' => 0.0,
                'legal_snapshot' => ['period' => $period->toDateString()],
                'calculation_status' => 'OK',
                'calculation_notes' => null,
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
            'calculation_status' => 'OK',
            'calculation_notes' => $person->afp ? null : 'AFP no configurada; comisión AFP calculada en 0.',
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
        $record->loadMissing('person');
        $snapshot = is_array($record->legal_snapshot ?? null) ? $record->legal_snapshot : [];
        $period = $record->period_date ? UiFormatter::formatDate($record->period_date) : '—';
        $warnings = array_values(array_filter([
            $record->calculation_notes ?? null,
            $record->calculation_status && $record->calculation_status !== 'OK' ? $record->calculation_status : null,
        ]));

        $isHonorarios = str_contains(mb_strtolower((string) $record->person?->modality), 'honorarios')
            || (($record->employee_retention ?? 0) > 0 && (float) $record->afp_mandatory === 0.0 && (float) $record->health_employee === 0.0);

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
                    'note' => ($snapshot['period'] ?? null) ? 'Cálculo confirmado con parámetros del período.' : null,
                ],
                'warnings' => $warnings,
                'sections' => [
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
                'note' => ($snapshot['period'] ?? null) ? 'Cálculo confirmado con parámetros del período.' : null,
            ],
            'warnings' => $warnings,
            'sections' => [
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

    public function deriveStatus(PayrollRecord $record, CarbonInterface|string|null $asOf = null): string
    {
        $paid = $this->paidAmount($record, $asOf);
        $balance = $this->balance($record, $asOf);

        if ($balance <= 0.00001 && (float) $record->net_pay > 0) {
            return 'Pagado';
        }

        if ($paid > 0) {
            return 'Parcial';
        }

        if (! $record->payment_date) {
            return 'Falta fecha';
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
