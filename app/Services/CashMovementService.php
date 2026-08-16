<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\MonthlyClosure;
use App\Models\PayrollRecord;
use App\Models\SalesDocument;
use App\Models\User;
use App\Support\MassAssignment;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CashMovementService
{
    public function __construct(
        private readonly ReceivablesService $receivables,
        private readonly PayablesService $payables,
        private readonly PayrollService $payroll,
        private readonly LegalObligationService $obligations,
        private readonly AuditService $audit,
    ) {
    }

    public function create(array $data, ?User $user = null): CashMovement
    {
        return DB::transaction(function () use ($data, $user): CashMovement {
            $income = round((float) ($data['income'] ?? 0), 2);
            $expense = round((float) ($data['expense'] ?? 0), 2);

            if (($income > 0 && $expense > 0) || ($income <= 0 && $expense <= 0)) {
                throw new DomainException('Un movimiento debe tener ingreso o egreso, pero no ambos.');
            }

            $data['income'] = $income;
            $data['expense'] = $expense;
            $data['created_by_user_id'] = $user?->id;
            $data['status'] = $data['status'] ?? 'posted';

            $this->rejectClosedPeriod($data);
            $this->validateAgainstDocument($data);

            $movement = MassAssignment::create(CashMovement::class, $data);

            $this->refreshSourceDocument($movement);
            $this->audit->record('cash_movement.created', $movement, $user);

            return $movement;
        });
    }

    private function rejectClosedPeriod(array $data): void
    {
        if (($data['status'] ?? 'posted') !== 'posted') {
            return;
        }

        $period = Carbon::parse($data['movement_date'])->startOfMonth()->toDateString();

        $closed = MonthlyClosure::query()
            ->where('company_id', $data['company_id'])
            ->whereDate('period_date', $period)
            ->where('status', 'closed')
            ->exists();

        if ($closed) {
            throw new DomainException("El periodo {$period} esta cerrado para movimientos de caja.");
        }
    }

    private function validateAgainstDocument(array $data): void
    {
        if (($data['source_document_type'] ?? null) === 'sales_document') {
            $document = SalesDocument::query()
                ->where('company_id', $data['company_id'])
                ->where('code', $data['source_document_code'])
                ->lockForUpdate()
                ->firstOrFail();

            if ((float) $data['income'] <= 0) {
                throw new DomainException('El cobro de una factura debe registrarse como ingreso.');
            }

            if ((float) $data['income'] > $this->receivables->balance($document) + 0.00001) {
                throw new DomainException('El cobro excede el saldo pendiente de la factura.');
            }
        }

        if (($data['source_document_type'] ?? null) === 'expense_document') {
            $document = ExpenseDocument::query()
                ->where('company_id', $data['company_id'])
                ->where('code', $data['source_document_code'])
                ->lockForUpdate()
                ->firstOrFail();

            if ((float) $data['expense'] <= 0) {
                throw new DomainException('El pago de un gasto debe registrarse como egreso.');
            }

            if ((float) $data['expense'] > $this->payables->balance($document) + 0.00001) {
                throw new DomainException('El pago excede el saldo pendiente del gasto.');
            }
        }

        if (($data['source_document_type'] ?? null) === 'payroll_record') {
            $record = PayrollRecord::query()
                ->where('company_id', $data['company_id'])
                ->where('code', $data['source_document_code'])
                ->lockForUpdate()
                ->firstOrFail();

            if ((float) $data['expense'] <= 0) {
                throw new DomainException('El pago de remuneracion debe registrarse como egreso.');
            }

            if ((float) $data['expense'] > $this->payroll->balance($record) + 0.00001) {
                throw new DomainException('El pago excede el saldo pendiente de la remuneracion.');
            }
        }

        if (($data['source_document_type'] ?? null) === 'legal_obligation') {
            $obligation = LegalObligation::query()
                ->where('company_id', $data['company_id'])
                ->where('code', $data['source_document_code'])
                ->lockForUpdate()
                ->firstOrFail();

            if ((float) $data['expense'] <= 0) {
                throw new DomainException('El pago de obligacion debe registrarse como egreso.');
            }

            if ((float) $data['expense'] > $this->obligations->balance($obligation) + 0.00001) {
                throw new DomainException('El pago excede el saldo pendiente de la obligacion.');
            }
        }
    }

    private function refreshSourceDocument(CashMovement $movement): void
    {
        if ($movement->source_document_type === 'sales_document') {
            $document = SalesDocument::query()
                ->where('company_id', $movement->company_id)
                ->where('code', $movement->source_document_code)
                ->first();

            if ($document) {
                $this->receivables->refreshDocumentState($document);
            }
        }

        if ($movement->source_document_type === 'expense_document') {
            $document = ExpenseDocument::query()
                ->where('company_id', $movement->company_id)
                ->where('code', $movement->source_document_code)
                ->first();

            if ($document) {
                $this->payables->refreshDocumentState($document);
            }
        }

        if ($movement->source_document_type === 'payroll_record') {
            $record = PayrollRecord::query()
                ->where('company_id', $movement->company_id)
                ->where('code', $movement->source_document_code)
                ->first();

            if ($record) {
                $this->payroll->refreshStatus($record);
            }
        }

        if ($movement->source_document_type === 'legal_obligation') {
            $obligation = LegalObligation::query()
                ->where('company_id', $movement->company_id)
                ->where('code', $movement->source_document_code)
                ->first();

            if ($obligation) {
                $this->obligations->refreshStatus($obligation);
            }
        }
    }
}
