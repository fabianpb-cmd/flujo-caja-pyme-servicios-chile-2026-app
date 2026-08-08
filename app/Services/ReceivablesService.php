<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\SalesDocument;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ReceivablesService
{
    public function __construct(private readonly LegalParameterService $legalParameters)
    {
    }

    public function amountsWithVat(int $companyId, string|float|int $netAmount, CarbonInterface|string $date): array
    {
        $vatRate = (float) $this->legalParameters->value($companyId, 'IVA', $date);
        $net = round((float) $netAmount, 2);
        $vat = round($net * $vatRate, 2);

        return [
            'net_amount' => $net,
            'vat_amount' => $vat,
            'gross_amount' => round($net + $vat, 2),
        ];
    }

    public function collectedAmount(SalesDocument $document, CarbonInterface|string|null $asOf = null): float
    {
        return (float) $this->cashQuery($document, $asOf)->sum('income');
    }

    public function balance(SalesDocument $document, CarbonInterface|string|null $asOf = null): float
    {
        return max(0, round((float) $document->gross_amount - $this->collectedAmount($document, $asOf), 2));
    }

    public function forecastAmount(SalesDocument $document): float
    {
        $probability = $document->payment_probability === null ? 1.0 : (float) $document->payment_probability;

        return round($this->balance($document) * $probability, 2);
    }

    public function deriveStatus(SalesDocument $document, CarbonInterface|string|null $asOf = null): string
    {
        if ($document->is_voided) {
            return 'Anulado';
        }

        $collected = $this->collectedAmount($document, $asOf);
        $balance = $this->balance($document, $asOf);

        if ($balance <= 0.00001 && (float) $document->gross_amount > 0) {
            return 'Pagado';
        }

        if ($collected > 0) {
            return 'Parcial';
        }

        $date = $asOf ? Carbon::parse($asOf) : now();
        if ($document->due_date && Carbon::parse($document->due_date)->lt($date->startOfDay())) {
            return 'Vencido';
        }

        return 'Pendiente';
    }

    public function refreshDocumentState(SalesDocument $document): SalesDocument
    {
        $document->forceFill([
            'collected_amount' => $this->collectedAmount($document),
            'status' => $this->deriveStatus($document),
        ])->save();

        return $document->refresh();
    }

    public function accountsReceivable(int $companyId, CarbonInterface|string $asOf): float
    {
        $date = Carbon::parse($asOf)->toDateString();

        return SalesDocument::query()
            ->forCompany($companyId)
            ->where('is_voided', false)
            ->whereDate('issue_date', '<=', $date)
            ->get()
            ->sum(fn (SalesDocument $document): float => $this->balance($document, $date));
    }

    private function cashQuery(SalesDocument $document, CarbonInterface|string|null $asOf = null)
    {
        $query = CashMovement::query()
            ->forCompany($document->company_id)
            ->where('source_document_type', 'sales_document')
            ->where('source_document_code', $document->code)
            ->where('status', 'posted');

        if ($asOf) {
            $query->whereDate('movement_date', '<=', Carbon::parse($asOf)->toDateString());
        }

        return $query;
    }
}
