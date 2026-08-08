<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CompanySetting;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\PayrollRecord;
use App\Models\SalesDocument;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class LegalObligationService
{
    public function __construct(private readonly LegalParameterService $legalParameters)
    {
    }

    public function vatForPeriod(int $companyId, CarbonInterface|string $periodDate): float
    {
        $start = Carbon::parse($periodDate)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $debit = (float) SalesDocument::query()
            ->forCompany($companyId)
            ->where('is_voided', false)
            ->whereBetween('issue_date', [$start, $end])
            ->sum('vat_amount');

        $credit = (float) ExpenseDocument::query()
            ->forCompany($companyId)
            ->whereBetween('issue_date', [$start, $end])
            ->sum('recoverable_vat_amount');

        return round(max($debit - $credit, 0), 2);
    }

    public function honorariosRetentionForPeriod(int $companyId, CarbonInterface|string $periodDate): float
    {
        $start = Carbon::parse($periodDate)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return (float) PayrollRecord::query()
            ->forCompany($companyId)
            ->whereBetween('period_date', [$start, $end])
            ->sum('employee_retention');
    }

    public function ppmForPeriod(int $companyId, CarbonInterface|string $periodDate): float
    {
        $start = Carbon::parse($periodDate)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $rate = (float) $this->legalParameters->value($companyId, 'PPM_RATE', $start);
        $base = (float) SalesDocument::query()
            ->forCompany($companyId)
            ->where('is_voided', false)
            ->whereBetween('issue_date', [$start, $end])
            ->sum('net_amount');

        return round($base * $rate, 2);
    }

    public function employerContributionsForPeriod(int $companyId, CarbonInterface|string $periodDate): float
    {
        $start = Carbon::parse($periodDate)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $base = (float) PayrollRecord::query()
            ->forCompany($companyId)
            ->whereBetween('period_date', [$start, $end])
            ->sum('taxable_amount');

        $rate = (float) $this->legalParameters->value($companyId, 'COTIZACION_EMPLEADOR', $start)
            + (float) $this->legalParameters->value($companyId, 'SIS_RATE', $start)
            + (float) $this->legalParameters->value($companyId, 'AFC_RATE', $start);

        return round($base * $rate, 2);
    }

    public function secondCategoryTaxForPeriod(int $companyId, CarbonInterface|string $periodDate): float
    {
        $start = Carbon::parse($periodDate)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $rate = (float) $this->legalParameters->value($companyId, 'IMPUESTO_SEGUNDA_CATEGORIA_RATE', $start);
        $base = (float) PayrollRecord::query()
            ->forCompany($companyId)
            ->whereBetween('period_date', [$start, $end])
            ->sum('taxable_amount');

        return round($base * $rate, 2);
    }

    public function syncMonthlyObligations(int $companyId, CarbonInterface|string $startPeriod, int $months = 12): void
    {
        $cursor = Carbon::parse($startPeriod)->startOfMonth();
        $dueDay = (int) (CompanySetting::query()->forCompany($companyId)->where('setting_key', 'obligation_due_day')->value('setting_value') ?? 13);

        for ($i = 0; $i < $months; $i++) {
            $period = $cursor->copy()->addMonths($i);
            $dueDate = $period->copy()->addMonth()->startOfMonth()->day(min($dueDay, $period->copy()->addMonth()->daysInMonth));

            $this->upsertObligation($companyId, $period, 'IVA', $this->vatForPeriod($companyId, $period), $dueDate, 'Ingresos IVA - egresos IVA recuperable');
            $this->upsertObligation($companyId, $period, 'RETENCIONES_HONORARIOS', $this->honorariosRetentionForPeriod($companyId, $period), $dueDate, 'Retencion por honorarios del periodo');
            $this->upsertObligation($companyId, $period, 'PPM', $this->ppmForPeriod($companyId, $period), $dueDate, 'PPM segun ventas netas del periodo');
            $this->upsertObligation($companyId, $period, 'COTIZACIONES', $this->employerContributionsForPeriod($companyId, $period), $dueDate, 'Cotizaciones empleador + SIS + AFC');
            $this->upsertObligation($companyId, $period, 'IMPUESTO_SEGUNDA_CATEGORIA', $this->secondCategoryTaxForPeriod($companyId, $period), $dueDate, 'Impuesto segunda categoria parametrizado');
        }
    }

    public function paidAmount(LegalObligation $obligation, CarbonInterface|string|null $asOf = null): float
    {
        $query = CashMovement::query()
            ->forCompany($obligation->company_id)
            ->where('source_document_type', 'legal_obligation')
            ->where('source_document_code', $obligation->code)
            ->where('status', 'posted');

        if ($asOf) {
            $query->whereDate('movement_date', '<=', Carbon::parse($asOf)->toDateString());
        }

        return (float) $query->sum('expense');
    }

    public function balance(LegalObligation $obligation, CarbonInterface|string|null $asOf = null): float
    {
        return max(0, round((float) $obligation->estimated_amount - $this->paidAmount($obligation, $asOf), 2));
    }

    public function deriveStatus(LegalObligation $obligation, CarbonInterface|string|null $asOf = null): string
    {
        $paid = $this->paidAmount($obligation, $asOf);
        $balance = $this->balance($obligation, $asOf);

        if ($balance <= 0.00001 && (float) $obligation->estimated_amount > 0) {
            return 'Pagado';
        }

        if ($paid > 0) {
            return 'Parcial';
        }

        $date = $asOf ? Carbon::parse($asOf) : now();

        return $obligation->due_date && Carbon::parse($obligation->due_date)->lt($date->startOfDay()) ? 'Vencido' : 'Pendiente';
    }

    public function refreshStatus(LegalObligation $obligation): LegalObligation
    {
        $obligation->forceFill([
            'paid_amount' => $this->paidAmount($obligation),
            'pending_amount' => $this->balance($obligation),
            'status' => $this->deriveStatus($obligation),
        ])->save();

        return $obligation->refresh();
    }

    private function upsertObligation(int $companyId, Carbon $period, string $type, float $amount, Carbon $dueDate, string $source): void
    {
        $code = sprintf('OBL-%s-%s', $period->format('Ym'), $type);

        $obligation = LegalObligation::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'code' => $code,
            ],
            [
                'obligation_type' => $type,
                'period_date' => $period->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'estimated_amount' => round($amount, 2),
                'pending_amount' => round($amount, 2),
                'source_calculation' => $source,
                'status' => 'Pendiente',
            ]
        );

        $this->refreshStatus($obligation);
    }
}
