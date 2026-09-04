<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashMovementType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\LegalParameter;
use App\Models\MonthlyClosure;
use App\Models\ObligationType;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\RecordStatus;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\CatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialTransactionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private Client $client;
    private Project $project;
    private Person $person;
    private CashAccount $account;
    private int $movementTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['code' => 'CMP-FIN-GUARD', 'name' => 'Empresa Integridad', 'status' => 'active']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'active' => true]);
        app(CatalogService::class)->seedDefaultsForCompany($this->company->id);
        foreach (['IVA' => 0.19, 'RETENCION_HONORARIOS' => 0.1525] as $code => $value) {
            LegalParameter::query()->create([
                'company_id' => $this->company->id,
                'parameter_code' => $code,
                'parameter_name' => $code,
                'valid_from' => '2026-01-01',
                'value' => $value,
                'unit' => '%',
                'active' => true,
            ]);
        }

        $activeStatusId = RecordStatus::query()->where('company_id', $this->company->id)->where('code', 'active')->valueOrFail('id');
        $this->client = Client::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CLI-FIN-GUARD',
            'legal_name' => 'Cliente Integridad',
            'client_status_id' => $activeStatusId,
        ]);
        $this->project = Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'code' => 'PRY-FIN-GUARD',
            'name' => 'Proyecto Integridad',
            'project_status_id' => $activeStatusId,
        ]);
        $this->person = Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'PER-FIN-GUARD',
            'name' => 'Persona Integridad',
            'modality' => 'Honorarios mensual',
            'monthly_value' => 1000,
            'worker_status_id' => RecordStatus::query()->where('company_id', $this->company->id)->where('domain', 'worker')->where('code', 'active')->valueOrFail('id'),
            'status' => 'active',
        ]);
        ProjectAssignment::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'person_id' => $this->person->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'assignment_status_id' => RecordStatus::query()->where('company_id', $this->company->id)->where('domain', 'assignment')->where('code', 'active')->valueOrFail('id'),
            'status' => 'Activo',
        ]);
        $this->account = CashAccount::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CTA-FIN-GUARD',
            'name' => 'Cuenta Integridad',
            'currency_id' => Currency::query()->where('company_id', $this->company->id)->where('code', 'CLP')->valueOrFail('id'),
            'opening_balance' => 0,
            'is_active' => true,
        ]);
        $this->movementTypeId = CashMovementType::query()->where('company_id', $this->company->id)->valueOrFail('id');
    }

    public function test_posted_movements_for_all_financial_sources_cannot_be_edited_or_returned_to_draft(): void
    {
        foreach ($this->sourceTypes() as $sourceType) {
            $document = $this->document($sourceType, '2026-09-01');
            $movement = $this->movement($document, $sourceType, 'posted', 100);
            $before = $movement->toArray();

            $response = $this->actingAs($this->admin)->put(
                route('operational.update', ['cash-movements', $movement->id]),
                $this->movementPayload($document, $sourceType, 50, 'draft')
            );

            $response->assertSessionHasErrors('cash_movement');
            $this->assertSame($before['status'], $movement->fresh()->status);
            $this->assertSame((float) $before[$this->amountField($sourceType)], (float) $movement->fresh()->getAttribute($this->amountField($sourceType)));
        }
    }

    public function test_posted_movement_cannot_be_deleted_in_open_or_closed_period(): void
    {
        $document = $this->document('sales_document', '2026-09-01');
        $openMovement = $this->movement($document, 'sales_document', 'posted', 100);
        $this->actingAs($this->admin)
            ->delete(route('operational.destroy', ['cash-movements', $openMovement->id]))
            ->assertSessionHasErrors('cash_movement');
        $this->assertDatabaseHas('cash_movements', ['id' => $openMovement->id]);

        $closedMovement = $this->movement($document, 'sales_document', 'posted', 100, '2026-10-10');
        $this->closePeriod('2026-10-01');
        $this->actingAs($this->admin)
            ->delete(route('operational.destroy', ['cash-movements', $closedMovement->id]))
            ->assertSessionHasErrors('cash_movement');
        $this->assertDatabaseHas('cash_movements', ['id' => $closedMovement->id]);
    }

    public function test_draft_movement_can_be_edited_and_deleted_with_audit(): void
    {
        $movement = CashMovement::query()->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-DRAFT-EDIT',
            'movement_type_id' => $this->movementTypeId,
            'movement_type' => 'Otro',
            'source_document_type' => 'other',
            'movement_date' => '2026-09-10',
            'income' => 100,
            'expense' => 0,
            'cash_account_id' => $this->account->id,
            'status' => 'draft',
        ]);

        $this->actingAs($this->admin)->put(route('operational.update', ['cash-movements', $movement->id]), [
            'movement_type_id' => $this->movementTypeId,
            'source_document_type' => 'other',
            'movement_date' => '2026-09-11',
            'income' => 250,
            'expense' => 0,
            'cash_account_id' => $this->account->id,
            'status' => 'draft',
        ])->assertRedirect(route('operational.show', ['cash-movements', $movement->id]));

        $this->assertSame(250.0, (float) $movement->fresh()->income);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cash_movement.updated', 'auditable_id' => $movement->id]);

        $this->actingAs($this->admin)
            ->delete(route('operational.destroy', ['cash-movements', $movement->id]))
            ->assertRedirect(route('operational.index', 'cash-movements'));
        $this->assertDatabaseMissing('cash_movements', ['id' => $movement->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cash_movement.deleted', 'auditable_id' => $movement->id]);
    }

    public function test_draft_to_posted_validates_balance_period_and_refreshes_source_atomically(): void
    {
        $validDocument = $this->document('sales_document', '2026-09-01', 1000);
        $valid = $this->movement($validDocument, 'sales_document', 'draft', 100);
        $this->actingAs($this->admin)->put(
            route('operational.update', ['cash-movements', $valid->id]),
            $this->movementPayload($validDocument, 'sales_document', 400, 'posted')
        )->assertRedirect(route('operational.show', ['cash-movements', $valid->id]));
        $this->assertSame('posted', $valid->fresh()->status);
        $this->assertSame(400.0, (float) $validDocument->fresh()->collected_amount);
        $this->assertSame('Parcial', $validDocument->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'cash_movement.updated')->where('auditable_id', $valid->id)->count());

        $overpaidDocument = $this->document('sales_document', '2026-09-01', 500);
        $overpaid = $this->movement($overpaidDocument, 'sales_document', 'draft', 100);
        $this->actingAs($this->admin)->put(
            route('operational.update', ['cash-movements', $overpaid->id]),
            $this->movementPayload($overpaidDocument, 'sales_document', 501, 'posted')
        )->assertSessionHasErrors('cash_movement');
        $this->assertSame('draft', $overpaid->fresh()->status);
        $this->assertSame(100.0, (float) $overpaid->income);
        $this->assertSame(0.0, (float) $overpaidDocument->fresh()->collected_amount);

        $closedDocument = $this->document('sales_document', '2026-10-01', 500);
        $closed = $this->movement($closedDocument, 'sales_document', 'draft', 100, '2026-10-10');
        $this->closePeriod('2026-10-01');
        $this->actingAs($this->admin)->put(
            route('operational.update', ['cash-movements', $closed->id]),
            $this->movementPayload($closedDocument, 'sales_document', 200, 'posted', '2026-10-10')
        )->assertSessionHasErrors('cash_movement');
        $this->assertSame('draft', $closed->fresh()->status);
        $this->assertSame(0.0, (float) $closedDocument->fresh()->collected_amount);
    }

    public function test_all_source_documents_with_any_movement_are_protected_from_deletion(): void
    {
        foreach ($this->sourceTypes() as $sourceType) {
            $document = $this->document($sourceType, '2026-09-01');
            $this->movement($document, $sourceType, 'draft', 100);

            $this->actingAs($this->admin)
                ->delete(route('operational.destroy', [$this->resource($sourceType), $document->id]))
                ->assertSessionHasErrors('dependencies');
            $this->assertDatabaseHas($document->getTable(), ['id' => $document->id]);
        }
    }

    public function test_document_dependency_lookup_is_scoped_by_company(): void
    {
        $document = $this->document('expense_document', '2026-09-01');
        $other = Company::query()->create(['code' => 'CMP-FIN-OTHER', 'name' => 'Otra Empresa', 'status' => 'active']);
        CashMovement::query()->create([
            'company_id' => $other->id,
            'code' => 'MOV-OTHER-COMPANY',
            'movement_type' => 'Egreso',
            'source_document_type' => 'expense_document',
            'source_document_code' => $document->code,
            'movement_date' => '2026-09-10',
            'income' => 0,
            'expense' => 100,
            'status' => 'draft',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('operational.destroy', ['expense-documents', $document->id]))
            ->assertRedirect(route('operational.index', 'expense-documents'));
        $this->assertDatabaseMissing('expense_documents', ['id' => $document->id]);
    }

    public function test_source_documents_with_posted_movements_are_fully_immutable(): void
    {
        foreach ($this->sourceTypes() as $sourceType) {
            $document = $this->document($sourceType, '2026-09-01');
            $this->movement($document, $sourceType, 'posted', 100);
            $before = $document->toArray();

            $this->actingAs($this->admin)->put(
                route('operational.update', [$this->resource($sourceType), $document->id]),
                $this->documentPayload($sourceType, '2026-09-01', 2000)
            )->assertSessionHasErrors('financial');

            $document->refresh();
            $this->assertSame($before[$this->documentAmountField($sourceType)], $document->getAttribute($this->documentAmountField($sourceType)));
        }
    }

    public function test_source_documents_cannot_be_created_in_closed_period(): void
    {
        $this->closePeriod('2026-10-01');

        foreach ($this->sourceTypes() as $sourceType) {
            $model = $this->modelClass($sourceType);
            $before = $model::query()->count();
            $this->actingAs($this->admin)->post(
                route('operational.store', $this->resource($sourceType)),
                $this->documentPayload($sourceType, '2026-10-01', 1000)
            )->assertSessionHasErrors('financial');
            $this->assertSame($before, $model::query()->count());
        }
    }

    public function test_source_documents_cannot_be_edited_or_deleted_in_closed_period(): void
    {
        $documents = collect($this->sourceTypes())->mapWithKeys(fn (string $sourceType) => [
            $sourceType => $this->document($sourceType, '2026-10-01'),
        ]);
        $this->closePeriod('2026-10-01');

        foreach ($documents as $sourceType => $document) {
            $before = $document->toArray();
            $this->actingAs($this->admin)->put(
                route('operational.update', [$this->resource($sourceType), $document->id]),
                $this->documentPayload($sourceType, '2026-10-01', 2000)
            )->assertSessionHasErrors('financial');
            $this->assertSame($before[$this->documentAmountField($sourceType)], $document->fresh()->getAttribute($this->documentAmountField($sourceType)));

            $this->actingAs($this->admin)
                ->delete(route('operational.destroy', [$this->resource($sourceType), $document->id]))
                ->assertSessionHasErrors('financial');
            $this->assertDatabaseHas($document->getTable(), ['id' => $document->id]);
        }
    }

    public function test_source_documents_cannot_move_into_or_out_of_closed_periods(): void
    {
        $this->closePeriod('2026-10-01');

        foreach ($this->sourceTypes() as $sourceType) {
            $openDocument = $this->document($sourceType, '2026-09-01');
            $this->actingAs($this->admin)->put(
                route('operational.update', [$this->resource($sourceType), $openDocument->id]),
                $this->documentPayload($sourceType, '2026-10-01', 1000)
            )->assertSessionHasErrors('financial');
            $this->assertSame('2026-09-01', $openDocument->fresh()->getAttribute($this->dateField($sourceType))->toDateString());

            $closedDocument = $this->document($sourceType, '2026-10-01');
            $this->actingAs($this->admin)->put(
                route('operational.update', [$this->resource($sourceType), $closedDocument->id]),
                $this->documentPayload($sourceType, '2026-11-01', 1000)
            )->assertSessionHasErrors('financial');
            $this->assertSame('2026-10-01', $closedDocument->fresh()->getAttribute($this->dateField($sourceType))->toDateString());
        }
    }

    /** @return array<int, string> */
    private function sourceTypes(): array
    {
        return ['sales_document', 'expense_document', 'payroll_record', 'legal_obligation'];
    }

    private function document(string $sourceType, string $date, float $amount = 1000): Model
    {
        return match ($sourceType) {
            'sales_document' => SalesDocument::query()->create([
                'company_id' => $this->company->id, 'code' => 'ING-GUARD-'.uniqid(), 'client_id' => $this->client->id,
                'project_id' => $this->project->id, 'document_type' => 'Factura', 'issue_date' => $date, 'due_date' => $date,
                'net_amount' => $amount, 'vat_amount' => 0, 'gross_amount' => $amount, 'collected_amount' => 0,
                'status' => 'Pendiente', 'is_voided' => false,
            ]),
            'expense_document' => ExpenseDocument::query()->create([
                'company_id' => $this->company->id, 'code' => 'EGR-GUARD-'.uniqid(), 'vendor_name' => 'Proveedor Integridad',
                'client_id' => $this->client->id, 'project_id' => $this->project->id, 'document_type' => 'Factura', 'issue_date' => $date, 'due_date' => $date,
                'net_amount' => $amount, 'vat_amount' => 0, 'gross_amount' => $amount, 'paid_amount' => 0,
                'payment_status' => 'Pendiente',
            ]),
            'payroll_record' => PayrollRecord::query()->create([
                'company_id' => $this->company->id, 'code' => 'REM-GUARD-'.uniqid(), 'person_id' => $this->person->id,
                'project_id' => $this->project->id, 'period_date' => $date, 'payment_date' => $date,
                'monthly_value' => $amount, 'gross_amount' => $amount, 'net_pay' => $amount, 'employer_cost' => $amount,
                'calculation_status' => 'OK', 'status' => 'Pendiente',
            ]),
            'legal_obligation' => LegalObligation::query()->create([
                'company_id' => $this->company->id, 'code' => 'OBL-GUARD-'.uniqid(),
                'obligation_type_id' => ObligationType::query()->where('company_id', $this->company->id)->valueOrFail('id'),
                'obligation_type' => 'Previsional',
                'period_date' => $date, 'due_date' => $date, 'estimated_amount' => $amount,
                'paid_amount' => 0, 'pending_amount' => $amount, 'status' => 'Pendiente',
            ]),
        };
    }

    private function movement(Model $document, string $sourceType, string $status, float $amount, string $date = '2026-09-10'): CashMovement
    {
        $income = $sourceType === 'sales_document' ? $amount : 0;
        $expense = $sourceType === 'sales_document' ? 0 : $amount;

        return CashMovement::query()->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-GUARD-'.uniqid(),
            'movement_type_id' => $this->movementTypeId,
            'movement_type' => $income > 0 ? 'Ingreso' : 'Egreso',
            'source_document_type' => $sourceType,
            'source_document_code' => $document->getAttribute('code'),
            'counterparty_name' => 'Contraparte Integridad',
            'project_id' => $this->project->id,
            'movement_date' => $date,
            'income' => $income,
            'expense' => $expense,
            'cash_account_id' => $this->account->id,
            'status' => $status,
        ]);
    }

    private function movementPayload(Model $document, string $sourceType, float $amount, string $status, string $date = '2026-09-10'): array
    {
        return [
            'movement_type_id' => $this->movementTypeId,
            'source_document_type' => $sourceType,
            'source_document_code' => $document->getAttribute('code'),
            'counterparty_name' => 'Contraparte Integridad',
            'project_id' => $this->project->id,
            'movement_date' => $date,
            'income' => $sourceType === 'sales_document' ? $amount : 0,
            'expense' => $sourceType === 'sales_document' ? 0 : $amount,
            'cash_account_id' => $this->account->id,
            'status' => $status,
        ];
    }

    private function documentPayload(string $sourceType, string $date, float $amount): array
    {
        return match ($sourceType) {
            'sales_document' => [
                'client_id' => $this->client->id, 'project_id' => $this->project->id,
                'document_type_id' => DocumentType::query()->where('company_id', $this->company->id)->where('domain', 'sales')->valueOrFail('id'),
                'issue_date' => $date, 'due_date' => $date, 'net_amount' => $amount,
            ],
            'expense_document' => [
                'vendor_name' => 'Proveedor Integridad', 'client_id' => $this->client->id, 'project_id' => $this->project->id,
                'document_type_id' => DocumentType::query()->where('company_id', $this->company->id)->where('domain', 'expense')->valueOrFail('id'),
                'issue_date' => $date, 'due_date' => $date, 'net_amount' => $amount,
            ],
            'payroll_record' => [
                'person_id' => $this->person->id, 'project_id' => $this->project->id,
                'period_date' => $date, 'payment_date' => $date, 'monthly_value' => $amount,
            ],
            'legal_obligation' => [
                'obligation_type_id' => ObligationType::query()->where('company_id', $this->company->id)->valueOrFail('id'),
                'period_date' => $date, 'due_date' => $date, 'estimated_amount' => $amount,
            ],
        };
    }

    private function resource(string $sourceType): string
    {
        return match ($sourceType) {
            'sales_document' => 'sales-documents',
            'expense_document' => 'expense-documents',
            'payroll_record' => 'payroll-records',
            'legal_obligation' => 'legal-obligations',
        };
    }

    /** @return class-string<Model> */
    private function modelClass(string $sourceType): string
    {
        return match ($sourceType) {
            'sales_document' => SalesDocument::class,
            'expense_document' => ExpenseDocument::class,
            'payroll_record' => PayrollRecord::class,
            'legal_obligation' => LegalObligation::class,
        };
    }

    private function amountField(string $sourceType): string
    {
        return $sourceType === 'sales_document' ? 'income' : 'expense';
    }

    private function documentAmountField(string $sourceType): string
    {
        return match ($sourceType) {
            'sales_document', 'expense_document' => 'net_amount',
            'payroll_record' => 'monthly_value',
            'legal_obligation' => 'estimated_amount',
        };
    }

    private function dateField(string $sourceType): string
    {
        return in_array($sourceType, ['sales_document', 'expense_document'], true) ? 'issue_date' : 'period_date';
    }

    private function closePeriod(string $date): void
    {
        MonthlyClosure::query()->create([
            'company_id' => $this->company->id,
            'period_date' => $date,
            'opening_balance' => 0,
            'closing_balance' => 0,
            'cash_in' => 0,
            'cash_out' => 0,
            'accounts_receivable' => 0,
            'accounts_payable' => 0,
            'status' => 'closed',
        ]);
    }
}
