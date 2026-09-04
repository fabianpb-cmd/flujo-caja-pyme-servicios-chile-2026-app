<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\MonthlyClosure;
use App\Models\PayrollRecord;
use App\Models\SalesDocument;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class FinancialDocumentGuard
{
    private const RESOURCE_TYPES = [
        'sales-documents' => ['sales_document', 'issue_date'],
        'expense-documents' => ['expense_document', 'issue_date'],
        'payroll-records' => ['payroll_record', 'period_date'],
        'legal-obligations' => ['legal_obligation', 'period_date'],
    ];

    public function assertCreateAllowed(string $resource, int $companyId, array $data): void
    {
        $definition = self::RESOURCE_TYPES[$resource] ?? null;
        if ($definition === null) {
            return;
        }

        $this->assertPeriodOpen($companyId, $data[$definition[1]] ?? null);
    }

    public function assertUpdateAllowed(Model $document, array $data): void
    {
        $definition = $this->definitionFor($document);
        if ($definition === null) {
            return;
        }

        [$sourceType, $dateField] = $definition;
        $this->assertPeriodOpen((int) $document->getAttribute('company_id'), $document->getAttribute($dateField));
        $this->assertPeriodOpen(
            (int) $document->getAttribute('company_id'),
            array_key_exists($dateField, $data) ? $data[$dateField] : $document->getAttribute($dateField),
        );

        $hasPostedMovement = CashMovement::query()
            ->where('company_id', $document->getAttribute('company_id'))
            ->where('source_document_type', $sourceType)
            ->where('source_document_code', $document->getAttribute('code'))
            ->where('status', 'posted')
            ->exists();

        if ($hasPostedMovement) {
            throw new DomainException('El documento tiene movimientos de caja contabilizados y no puede modificarse. Revierta los movimientos antes de modificarlo.');
        }
    }

    public function assertDeleteAllowed(Model $document): void
    {
        $definition = $this->definitionFor($document);
        if ($definition === null) {
            return;
        }

        $this->assertPeriodOpen(
            (int) $document->getAttribute('company_id'),
            $document->getAttribute($definition[1]),
        );
    }

    public function assertPeriodOpen(int $companyId, mixed $date): void
    {
        if (blank($date)) {
            return;
        }

        $period = Carbon::parse($date)->startOfMonth();
        $closed = MonthlyClosure::query()
            ->where('company_id', $companyId)
            ->whereDate('period_date', $period->toDateString())
            ->where('status', 'closed')
            ->exists();

        if ($closed) {
            throw new DomainException(sprintf(
                'El período %s esta cerrado. Reabra el período antes de modificar documentos financieros.',
                $period->format('Y-m'),
            ));
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function definitionFor(Model $document): ?array
    {
        return match ($document::class) {
            SalesDocument::class => self::RESOURCE_TYPES['sales-documents'],
            ExpenseDocument::class => self::RESOURCE_TYPES['expense-documents'],
            PayrollRecord::class => self::RESOURCE_TYPES['payroll-records'],
            LegalObligation::class => self::RESOURCE_TYPES['legal-obligations'],
            default => null,
        };
    }
}
