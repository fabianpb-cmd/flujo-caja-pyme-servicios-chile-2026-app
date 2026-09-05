<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\Scenario;
use App\Services\CashFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashFlowServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Client $client;
    private Project $project;
    private CashAccount $cashAccount;
    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'code' => 'CMP-CFS',
            'name' => 'Empresa Caja',
            'status' => 'active',
        ]);

        $this->cashAccount = CashAccount::query()->create([
            'company_id' => $this->company->id,
            'code' => 'BANK-CFS',
            'name' => 'Banco Caja',
            'currency' => 'CLP',
            'opening_balance' => 1000000,
            'is_active' => true,
        ]);

        CompanySetting::query()->create([
            'company_id' => $this->company->id,
            'setting_key' => 'active_scenario',
            'setting_value' => 'BASE',
        ]);

        Scenario::query()->create([
            'company_id' => $this->company->id,
            'code' => 'BASE',
            'name' => 'Base',
            'sales_factor' => 1,
            'cost_factor' => 1,
            'collection_delay_days' => 0,
            'new_hires_monthly' => 0,
            'is_active' => true,
        ]);

        $this->client = Client::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CLI-CFS',
            'legal_name' => 'Cliente Caja',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        $this->project = Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'code' => 'PRY-CFS',
            'name' => 'Proyecto Caja',
            'sale_net' => 2000000,
            'sale_total' => 2380000,
            'project_status' => 'active',
            'billing_status' => 'pending',
        ]);

        $this->person = Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'PER-CFS',
            'name' => 'Persona Caja',
            'modality' => 'Honorarios',
            'status' => 'active',
        ]);
    }

    public function test_monthly_cash_flow_projects_only_remaining_receivable_after_partial_collection(): void
    {
        SalesDocument::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'code' => 'ING-CFS-1',
            'document_type' => 'Factura',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-09-15',
            'projected_collection_date' => '2026-09-15',
            'net_amount' => 1000000,
            'vat_amount' => 190000,
            'gross_amount' => 1190000,
            'status' => 'Pendiente',
            'is_voided' => false,
        ]);

        CashMovement::query()->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-CFS-1',
            'movement_type' => 'income',
            'source_document_type' => 'sales_document',
            'source_document_code' => 'ING-CFS-1',
            'project_id' => $this->project->id,
            'cash_account_id' => $this->cashAccount->id,
            'movement_date' => '2026-08-20',
            'income' => 400000,
            'expense' => 0,
            'status' => 'posted',
        ]);

        $row = app(CashFlowService::class)->monthly($this->company->id, '2026-09-01', 1)[0];

        $this->assertSame(790000.0, $row['income_projected']);
        $this->assertSame(790000.0, $row['accounts_receivable']);
    }

    public function test_cash_flow_counts_only_posted_movements_and_rejected_creation_leaves_no_effect(): void
    {
        foreach ([
            ['code' => 'MOV-CFS-POSTED', 'status' => 'posted', 'income' => 100],
            ['code' => 'MOV-CFS-DRAFT', 'status' => 'draft', 'income' => 200],
            ['code' => 'MOV-CFS-VOIDED-LEGACY', 'status' => 'voided', 'income' => 300],
        ] as $movement) {
            CashMovement::query()->create([
                'company_id' => $this->company->id,
                'code' => $movement['code'],
                'movement_type' => 'income',
                'cash_account_id' => $this->cashAccount->id,
                'movement_date' => '2026-08-20',
                'income' => $movement['income'],
                'expense' => 0,
                'status' => $movement['status'],
            ]);
        }

        $before = app(CashFlowService::class)->monthly($this->company->id, '2026-08-01', 1)[0];
        $this->assertSame(100.0, $before['income_real']);

        try {
            app(\App\Services\CashMovementService::class)->create([
                'company_id' => $this->company->id,
                'movement_type' => 'income',
                'movement_date' => '2026-08-20',
                'income' => 0,
                'expense' => 0,
                'status' => 'posted',
            ]);
            $this->fail('El movimiento inválido debía rechazarse.');
        } catch (\DomainException) {
            // La transacción debe revertirse sin dejar caja real.
        }

        $after = app(CashFlowService::class)->monthly($this->company->id, '2026-08-01', 1)[0];
        $this->assertSame(100.0, $after['income_real']);
        $this->assertSame(3, CashMovement::query()->forCompany($this->company->id)->count());
    }

    public function test_monthly_cash_flow_projects_only_remaining_payable_after_partial_payment(): void
    {
        ExpenseDocument::query()->create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'code' => 'EGR-CFS-1',
            'vendor_name' => 'Proveedor Caja',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-09-20',
            'net_amount' => 1000000,
            'vat_amount' => 190000,
            'recoverable_vat_amount' => 190000,
            'gross_amount' => 1190000,
            'payment_status' => 'Pendiente',
            'deductible_vat' => true,
        ]);

        CashMovement::query()->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-CFS-2',
            'movement_type' => 'expense',
            'source_document_type' => 'expense_document',
            'source_document_code' => 'EGR-CFS-1',
            'project_id' => $this->project->id,
            'cash_account_id' => $this->cashAccount->id,
            'movement_date' => '2026-08-25',
            'income' => 0,
            'expense' => 300000,
            'status' => 'posted',
        ]);

        $row = app(CashFlowService::class)->monthly($this->company->id, '2026-09-01', 1)[0];

        $this->assertSame(890000.0, $row['other_projected']);
        $this->assertSame(890000.0, $row['accounts_payable']);
    }

    public function test_monthly_cash_flow_projects_only_remaining_payroll_balance_on_payment_date(): void
    {
        PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-CFS-1',
            'person_id' => $this->person->id,
            'project_id' => $this->project->id,
            'period_date' => '2026-08-01',
            'payment_date' => '2026-09-10',
            'net_pay' => 1000000,
            'status' => 'Pendiente',
        ]);

        CashMovement::query()->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-CFS-3',
            'movement_type' => 'expense',
            'source_document_type' => 'payroll_record',
            'source_document_code' => 'REM-CFS-1',
            'cash_account_id' => $this->cashAccount->id,
            'movement_date' => '2026-08-25',
            'income' => 0,
            'expense' => 400000,
            'status' => 'posted',
        ]);

        $august = app(CashFlowService::class)->monthly($this->company->id, '2026-08-01', 1)[0];
        $september = app(CashFlowService::class)->monthly($this->company->id, '2026-09-01', 1)[0];

        $this->assertSame(400000.0, $august['personnel_real']);
        $this->assertSame(0.0, $august['personnel_projected']);
        $this->assertSame(600000.0, $september['personnel_projected']);
    }

    public function test_cash_flow_excludes_draft_or_cancelled_documents_from_projection(): void
    {
        SalesDocument::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'code' => 'ING-CFS-DR',
            'document_type' => 'Factura',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-09-15',
            'projected_collection_date' => '2026-09-15',
            'net_amount' => 500000,
            'vat_amount' => 95000,
            'gross_amount' => 595000,
            'status' => 'Borrador',
            'is_voided' => false,
        ]);

        SalesDocument::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'code' => 'ING-CFS-OK',
            'document_type' => 'Factura',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-09-15',
            'projected_collection_date' => '2026-09-15',
            'net_amount' => 600000,
            'vat_amount' => 114000,
            'gross_amount' => 714000,
            'status' => 'Pendiente',
            'is_voided' => false,
        ]);

        ExpenseDocument::query()->create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'code' => 'EGR-CFS-VOID',
            'vendor_name' => 'Proveedor anulado',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-09-20',
            'net_amount' => 300000,
            'vat_amount' => 57000,
            'recoverable_vat_amount' => 57000,
            'gross_amount' => 357000,
            'payment_status' => 'Anulado',
            'deductible_vat' => true,
        ]);

        $row = app(CashFlowService::class)->monthly($this->company->id, '2026-09-01', 1)[0];

        $this->assertSame(714000.0, $row['income_projected']);
        $this->assertSame(0.0, $row['other_projected']);
        $this->assertSame(714000.0, $row['accounts_receivable']);
        $this->assertSame(0.0, $row['accounts_payable']);
    }

    public function test_cash_flow_rolls_forward_closing_balance_into_next_opening_balance(): void
    {
        CashMovement::query()->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-CFS-4',
            'movement_type' => 'income',
            'cash_account_id' => $this->cashAccount->id,
            'movement_date' => '2026-08-05',
            'income' => 500000,
            'expense' => 0,
            'status' => 'posted',
        ]);

        CashMovement::query()->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-CFS-5',
            'movement_type' => 'expense',
            'cash_account_id' => $this->cashAccount->id,
            'movement_date' => '2026-08-10',
            'income' => 0,
            'expense' => 300000,
            'status' => 'posted',
        ]);

        $rows = app(CashFlowService::class)->monthly($this->company->id, '2026-08-01', 2);

        $this->assertSame(1000000.0, $rows[0]['opening_real']);
        $this->assertSame(1200000.0, $rows[0]['closing_real']);
        $this->assertSame(1200000.0, $rows[1]['opening_real']);
    }

    public function test_cash_flow_consolidates_multiple_accounts_without_double_counting(): void
    {
        $secondaryAccount = CashAccount::query()->create([
            'company_id' => $this->company->id,
            'code' => 'BANK-CFS-2',
            'name' => 'Banco Caja Secundario',
            'currency' => 'CLP',
            'opening_balance' => 2000000,
            'is_active' => true,
        ]);

        CashMovement::query()->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-CFS-6',
            'movement_type' => 'income',
            'cash_account_id' => $secondaryAccount->id,
            'movement_date' => '2026-08-12',
            'income' => 500000,
            'expense' => 0,
            'status' => 'posted',
        ]);

        CashMovement::query()->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-CFS-7',
            'movement_type' => 'expense',
            'cash_account_id' => $this->cashAccount->id,
            'movement_date' => '2026-08-13',
            'income' => 0,
            'expense' => 200000,
            'status' => 'posted',
        ]);

        $row = app(CashFlowService::class)->monthly($this->company->id, '2026-08-01', 1)[0];

        $this->assertSame(3000000.0, $row['opening_real']);
        $this->assertSame(500000.0, $row['income_real']);
        $this->assertSame(200000.0, $row['other_real']);
        $this->assertSame(3300000.0, $row['closing_real']);
    }

    public function test_cash_flow_scenario_delay_moves_projection_without_affecting_real_cash(): void
    {
        Scenario::query()->create([
            'company_id' => $this->company->id,
            'code' => 'DELAY',
            'name' => 'Delay',
            'sales_factor' => 1,
            'cost_factor' => 1,
            'collection_delay_days' => 10,
            'new_hires_monthly' => 0,
            'is_active' => false,
        ]);

        SalesDocument::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'code' => 'ING-CFS-SCN',
            'document_type' => 'Factura',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'projected_collection_date' => '2026-08-31',
            'net_amount' => 1000000,
            'vat_amount' => 190000,
            'gross_amount' => 1190000,
            'status' => 'Pendiente',
            'is_voided' => false,
        ]);

        $base = app(CashFlowService::class)->monthly($this->company->id, '2026-08-01', 1, 'BASE')[0];
        $delayed = app(CashFlowService::class)->monthly($this->company->id, '2026-08-01', 1, 'DELAY')[0];

        $this->assertSame(1190000.0, $base['income_projected']);
        $this->assertSame(0.0, $delayed['income_projected']);
        $this->assertSame(0.0, $base['income_real']);
        $this->assertSame(0.0, $delayed['income_real']);
    }
}
