<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\PayrollRecord;
use App\Models\SalesDocument;
use App\Models\User;
use App\Support\MassAssignment;
use DomainException;
use Illuminate\Support\Facades\DB;

class CashMovementService
{
    public function __construct(
        private readonly ReceivablesService $receivables,
        private readonly PayablesService $payables,
        private readonly PayrollService $payroll,
        private readonly LegalObligationService $obligations,
        private readonly AuditService $audit,
        private readonly FinancialDocumentGuard $financialDocuments,
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
            $this->assertSupportedStatus($data['status']);

            if ($data['status'] === 'posted') {
                $this->rejectClosedPeriod($data);
                $this->validateAgainstDocument($data);
            }

            $movement = MassAssignment::create(CashMovement::class, $data);

            if ($movement->status === 'posted') {
                $this->refreshSourceDocument($movement);
            }
            $this->audit->record('cash_movement.created', $movement, $user);

            return $movement;
        });
    }

    public function update(CashMovement $movement, array $data, ?User $user = null): CashMovement
    {
        return DB::transaction(function () use ($movement, $data, $user): CashMovement {
            $locked = CashMovement::query()
                ->whereKey($movement->getKey())
                ->where('company_id', $movement->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'posted') {
                throw new DomainException('Un movimiento de caja contabilizado es inmutable. Deberá utilizar una reversión cuando ese flujo esté disponible.');
            }

            if ($locked->status === 'voided') {
                throw new DomainException('Un movimiento de caja anulado legado no puede reactivarse. Elimínelo si corresponde a un registro sin efecto contable.');
            }

            $income = round((float) ($data['income'] ?? 0), 2);
            $expense = round((float) ($data['expense'] ?? 0), 2);
            if (($income > 0 && $expense > 0) || ($income <= 0 && $expense <= 0)) {
                throw new DomainException('Un movimiento debe tener ingreso o egreso, pero no ambos.');
            }

            $before = $locked->toArray();
            $data['income'] = $income;
            $data['expense'] = $expense;
            $data['company_id'] = $locked->company_id;
            $data['status'] = $data['status'] ?? $locked->status;
            $this->assertSupportedStatus($data['status']);

            if ($data['status'] === 'posted') {
                $this->rejectClosedPeriod($data);
                $this->validateAgainstDocument($data);
            }

            MassAssignment::fillAndSave($locked, $data);

            if ($locked->status === 'posted') {
                $this->refreshSourceDocument($locked);
            }

            $this->audit->record('cash_movement.updated', $locked->refresh(), $user, $before);

            return $locked->refresh();
        });
    }

    public function delete(CashMovement $movement, ?User $user = null): void
    {
        DB::transaction(function () use ($movement, $user): void {
            $locked = CashMovement::query()
                ->whereKey($movement->getKey())
                ->where('company_id', $movement->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'posted') {
                throw new DomainException('Un movimiento de caja contabilizado es inmutable. Deberá utilizar una reversión cuando ese flujo esté disponible.');
            }

            $before = $locked->toArray();
            $locked->delete();
            $this->audit->record('cash_movement.deleted', $locked, $user, $before, null);
        });
    }

    private function rejectClosedPeriod(array $data): void
    {
        if (($data['status'] ?? 'posted') !== 'posted') {
            return;
        }

        $this->financialDocuments->assertPeriodOpen((int) $data['company_id'], $data['movement_date']);
    }

    private function assertSupportedStatus(string $status): void
    {
        if (! in_array($status, ['draft', 'posted'], true)) {
            throw new DomainException('El estado Anulado no está disponible para movimientos de caja. Una reversión contable requiere un flujo específico.');
        }
    }

    private function validateAgainstDocument(array $data): void
    {
        if (($data['source_document_type'] ?? null) === 'sales_document') {
            $document = SalesDocument::query()
                ->where('company_id', $data['company_id'])
                ->where('code', $data['source_document_code'])
                ->lockForUpdate()
                ->first();

            if (! $document || $document->is_voided || in_array($document->status, ['Borrador', 'Anulado'], true)) {
                throw new DomainException('Seleccione una factura/ingreso vigente con saldo pendiente.');
            }

            if ((float) $data['income'] <= 0) {
                throw new DomainException('El cobro de una factura debe registrarse como ingreso.');
            }

            $balance = $this->receivables->balance($document);
            if ($balance <= 0.00001) {
                throw new DomainException('La factura seleccionada no tiene saldo pendiente.');
            }

            if ((float) $data['income'] > $balance + 0.00001) {
                throw new DomainException('El cobro excede el saldo pendiente de la factura.');
            }
        }

        if (($data['source_document_type'] ?? null) === 'expense_document') {
            $document = ExpenseDocument::query()
                ->where('company_id', $data['company_id'])
                ->where('code', $data['source_document_code'])
                ->lockForUpdate()
                ->first();

            if (! $document || in_array($document->payment_status, ['Borrador', 'Anulado'], true)) {
                throw new DomainException('Seleccione un gasto/egreso vigente con saldo pendiente.');
            }

            if ((float) $data['expense'] <= 0) {
                throw new DomainException('El pago de un gasto debe registrarse como egreso.');
            }

            $balance = $this->payables->balance($document);
            if ($balance <= 0.00001) {
                throw new DomainException('El gasto seleccionado no tiene saldo pendiente.');
            }

            if ((float) $data['expense'] > $balance + 0.00001) {
                throw new DomainException('El pago excede el saldo pendiente del gasto.');
            }
        }

        if (($data['source_document_type'] ?? null) === 'payroll_record') {
            $record = PayrollRecord::query()
                ->where('company_id', $data['company_id'])
                ->where('code', $data['source_document_code'])
                ->lockForUpdate()
                ->first();

            if (! $record || ! in_array($record->status, ['Confirmado', 'Parcial'], true)) {
                throw new DomainException('Seleccione una remuneracion vigente con saldo pendiente.');
            }

            if ((float) $data['expense'] <= 0) {
                throw new DomainException('El pago de remuneracion debe registrarse como egreso.');
            }

            $balance = $this->payroll->balance($record);
            if ($balance <= 0.00001) {
                throw new DomainException('La remuneracion seleccionada no tiene saldo pendiente.');
            }

            if ((float) $data['expense'] > $balance + 0.00001) {
                throw new DomainException('El pago excede el saldo pendiente de la remuneracion.');
            }
        }

        if (($data['source_document_type'] ?? null) === 'legal_obligation') {
            $obligation = LegalObligation::query()
                ->where('company_id', $data['company_id'])
                ->where('code', $data['source_document_code'])
                ->lockForUpdate()
                ->first();

            if (! $obligation || in_array($obligation->status, ['Borrador', 'Anulado'], true)) {
                throw new DomainException('Seleccione una obligacion vigente con saldo pendiente.');
            }

            if ((float) $data['expense'] <= 0) {
                throw new DomainException('El pago de obligacion debe registrarse como egreso.');
            }

            $balance = $this->obligations->balance($obligation);
            if ($balance <= 0.00001) {
                throw new DomainException('La obligacion seleccionada no tiene saldo pendiente.');
            }

            if ((float) $data['expense'] > $balance + 0.00001) {
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
