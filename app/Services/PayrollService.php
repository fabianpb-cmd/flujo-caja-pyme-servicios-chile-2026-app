<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Person;
use App\Models\PayrollRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class PayrollService
{
    public function __construct(private readonly LegalParameterService $legalParameters)
    {
    }

    public function calculate(Person $person, CarbonInterface|string $periodDate, array $data = []): array
    {
        $period = Carbon::parse($periodDate)->startOfMonth();
        $modeCode = $person->employmentMode?->code;
        $modality = mb_strtolower((string) ($modeCode ?: $person->modality));
        $monthDays = $period->daysInMonth;
        $workedDays = null;
        $base = 0.0;
        $retention = 0.0;

        $isDependent = $modeCode
            ? $modeCode === 'DEPENDIENTE_MENSUAL'
            : str_contains($modality, 'dependiente');

        if ($isDependent) {
            $workedDays = $this->workedDaysInMonth($person, $period);
            $base = round((float) $person->monthly_value * ($workedDays / $monthDays), 2);
        } elseif (($modeCode && $modeCode === 'PAGO_POR_HORA') || str_contains($modality, 'hora')) {
            $base = round((float) ($data['hours_approved'] ?? 0) * (float) ($data['hourly_value'] ?? $person->hourly_value), 2);
        } elseif (($modeCode && $modeCode === 'HONORARIOS_MENSUAL') || str_contains($modality, 'honorarios')) {
            $base = round((float) ($data['monthly_value'] ?? $person->monthly_value ?? 0), 2);
        } elseif (($modeCode && $modeCode === 'POR_PROYECTO') || str_contains($modality, 'proyecto')) {
            $base = round((float) ($data['project_value'] ?? 0), 2);
        }

        if (! $isDependent && $base > 0) {
            $retention = round($base * (float) $this->legalParameters->value($person->company_id, 'RETENCION_HONORARIOS', $period), 2);
        }

        $taxableAmount = $isDependent ? $base : 0.0;
        $vacationProvision = $isDependent
            ? round($base * (float) $this->legalParameters->value($person->company_id, 'PROVISION_VACACIONES', $period), 2)
            : 0.0;

        return [
            'worked_days' => $workedDays,
            'month_days' => $monthDays,
            'base_salary' => $base,
            'taxable_amount' => $taxableAmount,
            'employee_retention' => $retention,
            'vacation_provision' => $vacationProvision,
            'employer_cost' => round($base + $vacationProvision, 2),
            'net_pay' => round($base - $retention, 2),
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
