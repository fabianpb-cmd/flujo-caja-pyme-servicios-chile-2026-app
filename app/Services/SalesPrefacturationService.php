<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\SalesDocument;
use App\Models\SalesDocumentTimeEntry;
use App\Models\TimeEntry;
use App\Support\MassAssignment;
use App\Support\UiFormatter;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesPrefacturationService
{
    public function __construct(
        private readonly LegalParameterService $legalParameters,
        private readonly CurrencyConversionService $conversions,
        private readonly ReceivablesService $receivables,
        private readonly HourlyRateService $hourlyRates,
    ) {
    }

    public function calculate(
        int $companyId,
        int $projectId,
        CarbonInterface|string $periodDate,
        CarbonInterface|string $issueDate,
        bool $taxable = true,
        float|int|string $adjustmentAmount = 0,
        ?string $adjustmentReason = null,
    ): array {
        $project = Project::query()->with(['client', 'salesCurrency'])->forCompany($companyId)->findOrFail($projectId);
        $period = Carbon::parse($periodDate)->startOfMonth();
        $periodEnd = $period->copy()->endOfMonth();
        $issue = Carbon::parse($issueDate);
        $entries = $this->billableEntries($companyId, $projectId, $period, $periodEnd);
        $commercialCurrency = $project->salesCurrency ?: 'CLP';

        if ($entries->isEmpty()) {
            throw new DomainException('No existen HH aprobadas facturables para el proyecto y período seleccionados.');
        }

        $lines = $entries->map(fn (TimeEntry $entry): array => $this->lineForEntry($entry, $commercialCurrency));
        $netBeforeAdjustment = round($lines->sum('subtotal_clp'), 0, PHP_ROUND_HALF_UP);
        $adjustment = round((float) $adjustmentAmount, 0, PHP_ROUND_HALF_UP);

        if ($adjustment !== 0.0 && trim((string) $adjustmentReason) === '') {
            throw new DomainException('Debe indicar motivo para el ajuste comercial.');
        }

        $net = max(0, round($netBeforeAdjustment + $adjustment, 0, PHP_ROUND_HALF_UP));
        $vatRate = $taxable ? (float) $this->receivables->vatRate($companyId, $issue) : 0.0;
        $vat = round($net * $vatRate, 0, PHP_ROUND_HALF_UP);
        $gross = round($net + $vat, 0, PHP_ROUND_HALF_UP);
        $commercialNet = $this->convertClpAmount($net, $commercialCurrency, $companyId, $issue);
        $commercialVat = UiFormatter::roundAmount((float) $commercialNet['converted_amount'] * $vatRate, $commercialCurrency);
        $commercialGross = UiFormatter::roundAmount((float) $commercialNet['converted_amount'] + $commercialVat, $commercialCurrency);

        return [
            'project' => $project,
            'client' => $project->client,
            'commercial_currency' => $this->currencyDescriptor($commercialCurrency),
            'period_date' => $period->toDateString(),
            'period_label' => $period->isoFormat('MMMM YYYY'),
            'issue_date' => $issue->toDateString(),
            'taxable' => $taxable,
            'hours_total' => round($lines->sum('hours_approved'), 4),
            'net_before_adjustment' => $netBeforeAdjustment,
            'adjustment_amount' => $adjustment,
            'adjustment_reason' => $adjustmentReason,
            'net_amount' => $net,
            'vat_rate' => $vatRate,
            'vat_amount' => $vat,
            'gross_amount' => $gross,
            'commercial_net_amount' => (float) $commercialNet['converted_amount'],
            'commercial_vat_amount' => $commercialVat,
            'commercial_gross_amount' => $commercialGross,
            'lines' => $lines->values()->all(),
            'calculation_breakdown' => $this->breakdown($period, $issue, $taxable, $netBeforeAdjustment, $adjustment, $vatRate, $net, $vat, $gross, $commercialCurrency, $commercialNet['converted_amount'], $commercialVat, $commercialGross, $lines->values()->all(), null),
        ];
    }

    public function documentBreakdown(SalesDocument $document): array
    {
        $snapshot = is_array($document->billing_snapshot ?? null) ? $document->billing_snapshot : [];
        $commercialCurrency = $snapshot['commercial_currency'] ?? 'CLP';
        $commercialNetAmount = (float) ($snapshot['commercial_net_amount'] ?? $document->net_amount);
        $commercialVatAmount = (float) ($snapshot['commercial_vat_amount'] ?? $document->vat_amount);
        $commercialGrossAmount = (float) ($snapshot['commercial_gross_amount'] ?? $document->gross_amount);
        $lines = $snapshot['lines'] ?? $document->timeEntries->map(fn (TimeEntry $entry) => $entry->pivot ? [
            'time_entry_code' => $entry->code,
            'hours_approved' => (float) $entry->pivot->hours_approved,
            'hourly_rate_amount' => (float) $entry->pivot->hourly_rate_amount,
            'rate_unit_type' => $entry->pivot->rate_unit_type,
            'currency_code' => $entry->pivot->rate_unit_type === 'UF' ? 'UF' : 'CLP',
            'subtotal_original' => (float) $entry->pivot->subtotal_original,
            'subtotal_clp' => (float) $entry->pivot->subtotal_clp,
            'conversion_rate' => (float) ($entry->pivot->conversion_rate ?? 1),
            'conversion_date' => is_string($entry->pivot->conversion_date ?? null) ? $entry->pivot->conversion_date : optional($entry->pivot->conversion_date)?->toDateString(),
        ] : null)->filter()->values()->all();

        return $this->breakdown(
            Carbon::parse($snapshot['period_date'] ?? $document->billing_period_date ?? $document->issue_date ?? now())->startOfMonth(),
            Carbon::parse($snapshot['issue_date'] ?? $document->issue_date ?? now()),
            (bool) ($snapshot['taxable'] ?? ($document->vat_rate > 0)),
            (float) ($snapshot['net_before_adjustment'] ?? $document->net_amount),
            (float) ($snapshot['adjustment_amount'] ?? $document->adjustment_amount ?? 0),
            (float) ($snapshot['vat_rate'] ?? $document->vat_rate ?? 0),
            (float) ($snapshot['net_amount'] ?? $document->net_amount),
            (float) ($snapshot['vat_amount'] ?? $document->vat_amount),
            (float) ($snapshot['gross_amount'] ?? $document->gross_amount),
            $commercialCurrency,
            $commercialNetAmount,
            $commercialVatAmount,
            $commercialGrossAmount,
            $lines,
            $snapshot,
        );
    }

    public function generateDraft(int $companyId, array $input): SalesDocument
    {
        $calculation = $this->calculate(
            companyId: $companyId,
            projectId: (int) $input['project_id'],
            periodDate: $input['period'],
            issueDate: $input['issue_date'],
            taxable: (bool) ($input['taxable'] ?? true),
            adjustmentAmount: $input['adjustment_amount'] ?? 0,
            adjustmentReason: $input['adjustment_reason'] ?? null,
        );

        return DB::transaction(function () use ($companyId, $calculation): SalesDocument {
            $document = MassAssignment::create(SalesDocument::class, [
                'company_id' => $companyId,
                'client_id' => $calculation['client']->id,
                'project_id' => $calculation['project']->id,
                'document_type' => 'Prefacturación HH',
                'issue_date' => $calculation['issue_date'],
                'net_amount' => $calculation['net_amount'],
                'vat_rate' => $calculation['vat_rate'],
                'vat_amount' => $calculation['vat_amount'],
                'gross_amount' => $calculation['gross_amount'],
                'collected_amount' => 0,
                'status' => 'Borrador',
                'is_voided' => false,
                'billing_source' => 'TIME_ENTRIES',
                'billing_period_date' => $calculation['period_date'],
                'adjustment_amount' => $calculation['adjustment_amount'],
                'adjustment_reason' => $calculation['adjustment_reason'],
                'billing_snapshot' => $this->snapshot($calculation),
                'calculation_status' => 'OK',
                'calculation_notes' => null,
            ])->refresh();

            foreach ($calculation['lines'] as $line) {
                MassAssignment::create(SalesDocumentTimeEntry::class, [
                    'company_id' => $companyId,
                    'sales_document_id' => $document->id,
                    'time_entry_id' => $line['time_entry_id'],
                    'project_assignment_id' => $line['project_assignment_id'],
                    'hours_approved' => $line['hours_approved'],
                    'hourly_rate_amount' => $line['hourly_rate_amount'],
                    'rate_unit_type' => $line['rate_unit_type'],
                    'currency_id' => $line['currency_id'],
                    'subtotal_original' => $line['subtotal_original'],
                    'conversion_rate' => $line['conversion_rate'],
                    'conversion_date' => $line['conversion_date'],
                    'subtotal_clp' => $line['subtotal_clp'],
                    'snapshot' => $line,
                ]);
            }

            return $document;
        });
    }

    private function billableEntries(int $companyId, int $projectId, Carbon $period, Carbon $periodEnd)
    {
        return TimeEntry::query()
            ->forCompany($companyId)
            ->with(['approvalStatus', 'salesDocuments', 'salesDocuments.timeEntryLinks'])
            ->where('project_id', $projectId)
            ->whereBetween('entry_date', [$period->toDateString(), $periodEnd->toDateString()])
            ->where('hours_approved', '>', 0)
            ->whereDoesntHave('salesDocuments', function ($query) {
                $query->where('is_voided', false)->whereNotIn('status', ['Anulado']);
            })
            ->get()
            ->filter(fn (TimeEntry $entry): bool => $this->isApproved($entry))
            ->values();
    }

    private function lineForEntry(TimeEntry $entry, mixed $commercialCurrency = 'CLP'): array
    {
        $assignment = $this->assignmentForEntry($entry);
        $resolution = $this->hourlyRates->resolveForEntry($entry);
        $hours = (float) $entry->hours_approved;
        $entryDate = $entry->entry_date;
        $rate = (float) ($resolution['amount'] ?? $assignment?->hourly_value ?? $entry->hourly_value ?? 0);

        if ($rate <= 0) {
            throw new DomainException("Falta tarifa HH para la hora {$entry->code}.");
        }

        $unit = strtoupper((string) ($resolution['unit_type'] ?? ($assignment?->hourly_rate_unit_type ?: 'CURRENCY')));
        $currency = $resolution['currency'] ?? $assignment?->hourlyRateCurrency;
        $currencyCode = strtoupper((string) ($resolution['currency_code'] ?? ($unit === 'UF' ? 'UF' : ($currency instanceof Currency ? $currency->code : 'CLP'))));
        $subtotalOriginal = $hours * $rate;
        $conversionRate = 1.0;
        $rawClp = $subtotalOriginal;

        if ($unit === 'UF') {
            $conversionRate = (float) $this->legalParameters->ufValue($entry->company_id, $entryDate);
            $rawClp = $subtotalOriginal * $conversionRate;
        } elseif ($currencyCode !== 'CLP') {
            if (! $currency instanceof Currency) {
                throw new DomainException("Falta moneda para la hora {$entry->code}.");
            }
            $conversionRate = (float) $this->legalParameters->exchangeRate($entry->company_id, $currency->id, $entryDate);
            $rawClp = $this->conversions->convert($subtotalOriginal, $currency, 'CLP', $conversionRate, $entryDate)['raw_converted_amount'];
        }

        $commercial = $this->convertClpAmount((float) UiFormatter::roundAmount($rawClp, 'CLP'), $commercialCurrency, $entry->company_id, $entryDate);

        return [
            'time_entry_id' => $entry->id,
            'time_entry_code' => $entry->code,
            'person_id' => $entry->person_id,
            'project_assignment_id' => $assignment?->id,
            'entry_date' => $entryDate?->toDateString(),
            'hours_approved' => round($hours, 4),
            'hourly_rate_amount' => $rate,
            'rate_unit_type' => $unit === 'UF' ? 'UF' : 'CURRENCY',
            'currency_code' => $currencyCode,
            'currency_id' => $currency instanceof Currency ? $currency->id : null,
            'subtotal_original' => round($subtotalOriginal, 6),
            'conversion_rate' => $conversionRate,
            'conversion_date' => $entryDate?->toDateString(),
            'raw_clp' => $rawClp,
            'subtotal_clp' => UiFormatter::roundAmount($rawClp, 'CLP'),
            'commercial_currency_code' => UiFormatter::currencyCode($commercialCurrency),
            'subtotal_commercial' => (float) $commercial['converted_amount'],
        ];
    }

    private function convertClpAmount(float $amount, mixed $currency, int $companyId, CarbonInterface|string $date): array
    {
        $currencyCode = UiFormatter::currencyCode($currency);
        if ($currencyCode === 'CLP') {
            return [
                'converted_amount' => round($amount, 0, PHP_ROUND_HALF_UP),
                'exchange_rate' => 1.0,
                'currency_code' => 'CLP',
            ];
        }

        $exchangeRate = $currencyCode === 'UF'
            ? (float) $this->legalParameters->ufValue($companyId, $date)
            : (float) $this->legalParameters->exchangeRate($companyId, (int) data_get($currency, 'id'), $date);

        return $this->conversions->convert(
            amount: $amount,
            fromCurrency: 'CLP',
            toCurrency: $currency,
            exchangeRate: $exchangeRate > 0 ? 1 / $exchangeRate : 0,
            date: $date,
        );
    }

    private function currencyDescriptor(mixed $currency): array
    {
        if ($currency instanceof Currency) {
            return [
                'id' => $currency->id,
                'code' => strtoupper((string) $currency->code),
                'symbol' => $currency->symbol ?: strtoupper((string) $currency->code),
                'minor_units' => (int) ($currency->minor_units ?? 2),
            ];
        }

        return [
            'code' => UiFormatter::currencyCode($currency),
            'symbol' => match (UiFormatter::currencyCode($currency)) {
                'CLP' => '$',
                'USD' => 'US$',
                'EUR' => '€',
                'UF' => 'UF',
                default => UiFormatter::currencyCode($currency),
            },
            'minor_units' => UiFormatter::currencyCode($currency) === 'CLP' ? 0 : 2,
        ];
    }

    private function assignmentForEntry(TimeEntry $entry): ?ProjectAssignment
    {
        if ($entry->assignment_id) {
            $assignment = ProjectAssignment::query()
                ->with(['assignmentStatus', 'hourlyRateCurrency'])
                ->find($entry->assignment_id);
            if ($assignment) {
                return $assignment;
            }
        }

        return ProjectAssignment::query()
            ->where('company_id', $entry->company_id)
            ->where('person_id', $entry->person_id)
            ->where('project_id', $entry->project_id)
            ->with(['assignmentStatus', 'hourlyRateCurrency'])
            ->where(function ($query) use ($entry) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $entry->entry_date);
            })
            ->where(function ($query) use ($entry) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $entry->entry_date);
            })
            ->get()
            ->first(fn (ProjectAssignment $assignment): bool => strtolower((string) $assignment->assignmentStatus?->code ?: (string) $assignment->status) === 'active');
    }

    private function isApproved(TimeEntry $entry): bool
    {
        $code = strtolower((string) ($entry->approvalStatus?->code ?: $entry->approval_status));

        return in_array($code, ['approved', 'aprobado'], true);
    }

    private function snapshot(array $calculation): array
    {
        return [
            'period_date' => $calculation['period_date'],
            'issue_date' => $calculation['issue_date'],
            'taxable' => $calculation['taxable'],
            'hours_total' => $calculation['hours_total'],
            'net_amount' => $calculation['net_amount'],
            'vat_rate' => $calculation['vat_rate'],
            'vat_amount' => $calculation['vat_amount'],
            'gross_amount' => $calculation['gross_amount'],
            'commercial_currency' => $calculation['commercial_currency'],
            'commercial_net_amount' => $calculation['commercial_net_amount'],
            'commercial_vat_amount' => $calculation['commercial_vat_amount'],
            'commercial_gross_amount' => $calculation['commercial_gross_amount'],
            'lines' => $calculation['lines'],
        ];
    }

    private function breakdown(
        Carbon $period,
        Carbon $issue,
        bool $taxable,
        float $netBeforeAdjustment,
        float $adjustment,
        float $vatRate,
        float $net,
        float $vat,
        float $gross,
        mixed $commercialCurrency,
        float $commercialNet,
        float $commercialVat,
        float $commercialGross,
        array $lines,
        ?array $snapshot,
    ): array {
        return [
            'result' => [
                'label' => 'Venta total',
                'value' => UiFormatter::formatMoney($commercialGross, $commercialCurrency),
                'note' => $taxable ? 'Documento afecto a IVA.' : 'Documento exento.',
            ],
            'warnings' => [],
            'sections' => [
                [
                    'title' => 'Venta calculada',
                    'rows' => [
                        ['label' => 'Período', 'value' => UiFormatter::formatDate($period)],
                        ['label' => 'Fecha documento', 'value' => UiFormatter::formatDate($issue)],
                        ['label' => 'Venta neta', 'value' => UiFormatter::formatMoney($commercialNet, $commercialCurrency)],
                        ['label' => 'IVA', 'value' => UiFormatter::formatPercent($vatRate)],
                        ['label' => 'IVA monto', 'value' => UiFormatter::formatMoney($commercialVat, $commercialCurrency)],
                        ['label' => 'Venta total', 'value' => UiFormatter::formatMoney($commercialGross, $commercialCurrency), 'strong' => true],
                    ],
                ],
                [
                    'title' => 'Detalle por línea',
                    'rows' => collect($lines)->map(fn (array $line): array => [
                        'label' => ($line['time_entry_code'] ?? 'HH').' · '.UiFormatter::formatHours($line['hours_approved'] ?? 0),
                        'value' => UiFormatter::formatMoney($line['subtotal_commercial'] ?? $line['subtotal_clp'] ?? 0, $commercialCurrency),
                    ])->values()->all(),
                ],
            ],
            'parameters' => array_values(array_filter([
                ['label' => 'IVA', 'value' => UiFormatter::formatPercent($vatRate), 'validity' => UiFormatter::formatDate($issue), 'source' => 'Parámetro legal'],
                ['label' => 'Moneda comercial', 'value' => UiFormatter::currencyCode($commercialCurrency), 'validity' => UiFormatter::formatDate($issue), 'source' => 'Proyecto'],
                $snapshot && isset($snapshot['period_date']) ? ['label' => 'Período', 'value' => UiFormatter::formatDate($snapshot['period_date']), 'validity' => UiFormatter::formatDate($issue), 'source' => 'Snapshot'] : null,
            ])),
        ];
    }
}
