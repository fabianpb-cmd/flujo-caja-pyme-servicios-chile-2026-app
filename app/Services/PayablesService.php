<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\ExpenseDocument;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class PayablesService
{
    public function __construct(private readonly LegalParameterService $legalParameters)
    {
    }

    public function amountsWithVat(int $companyId, string|float|int $netAmount, CarbonInterface|string $date, bool $recoverableVat = true): array
    {
        $vatRate = (float) $this->legalParameters->value($companyId, 'IVA', $date);
        $net = round((float) $netAmount, 2);
        $vat = round($net * $vatRate, 2);

        return [
            'net_amount' => $net,
            'vat_amount' => $vat,
            'recoverable_vat_amount' => $recoverableVat ? $vat : 0.0,
            'gross_amount' => round($net + $vat, 2),
        ];
    }

    public function paidAmount(ExpenseDocument $document, CarbonInterface|string|null $asOf = null): float
    {
        return (float) $this->cashQuery($document, $asOf)->sum('expense');
    }

    public function balance(ExpenseDocument $document, CarbonInterface|string|null $asOf = null): float
    {
        return max(0, round((float) $document->gross_amount - $this->paidAmount($document, $asOf), 2));
    }

    public function deriveStatus(ExpenseDocument $document, CarbonInterface|string|null $asOf = null): string
    {
        $paid = $this->paidAmount($document, $asOf);
        $balance = $this->balance($document, $asOf);

        if ($balance <= 0.00001 && (float) $document->gross_amount > 0) {
            return 'Pagado';
        }

        if ($paid > 0) {
            return 'Parcial';
        }

        $date = $asOf ? Carbon::parse($asOf) : now();
        if ($document->due_date && Carbon::parse($document->due_date)->lt($date->startOfDay())) {
            return 'Vencido';
        }

        return 'Pendiente';
    }

    public function refreshDocumentState(ExpenseDocument $document): ExpenseDocument
    {
        $document->forceFill([
            'paid_amount' => $this->paidAmount($document),
            'payment_status' => $this->deriveStatus($document),
        ])->save();

        return $document->refresh();
    }

    public function accountsPayable(int $companyId, CarbonInterface|string $asOf): float
    {
        $date = Carbon::parse($asOf)->toDateString();

        return ExpenseDocument::query()
            ->forCompany($companyId)
            ->whereDate('issue_date', '<=', $date)
            ->get()
            ->sum(fn (ExpenseDocument $document): float => $this->balance($document, $date));
    }

    private function cashQuery(ExpenseDocument $document, CarbonInterface|string|null $asOf = null)
    {
        $query = CashMovement::query()
            ->forCompany($document->company_id)
            ->where('source_document_type', 'expense_document')
            ->where('source_document_code', $document->code)
            ->where('status', 'posted');

        if ($asOf) {
            $query->whereDate('movement_date', '<=', Carbon::parse($asOf)->toDateString());
        }

        return $query;
    }
}
