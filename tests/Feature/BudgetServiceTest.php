<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Company;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\Scenario;
use App\Models\TimeEntry;
use App\Services\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Client $client;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'code' => 'CMP-BGT',
            'name' => 'Empresa Presupuesto',
            'status' => 'active',
        ]);

        $this->client = Client::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CLI-BGT',
            'legal_name' => 'Cliente Presupuesto',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        $this->project = Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'code' => 'PRY-BGT',
            'name' => 'Proyecto Presupuesto',
            'sale_net' => 1200000,
            'sale_total' => 1428000,
            'project_status' => 'active',
            'billing_status' => 'pending',
        ]);
    }

    public function test_project_budget_variance_uses_recognized_actuals_not_cash_movements_and_includes_indirect_budget(): void
    {
        Budget::query()->create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'period_date' => '2026-08-01',
            'revenue_budget' => 900000,
            'personnel_budget' => 700000,
            'other_direct_budget' => 100000,
            'legal_budget' => 20000,
            'other_indirect_budget' => 50000,
        ]);

        $person = Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'PER-BGT',
            'name' => 'Persona Presupuesto',
            'modality' => 'Dependiente mensual',
            'monthly_value' => 800000,
            'status' => 'active',
        ]);

        PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-BGT',
            'person_id' => $person->id,
            'project_id' => $this->project->id,
            'period_date' => '2026-08-01',
            'employer_cost' => 800000,
            'vacation_provision' => 0,
            'net_pay' => 650000,
            'status' => 'Pendiente',
        ]);

        TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HOR-BGT',
            'person_id' => $person->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'entry_date' => '2026-08-05',
            'activity' => 'Trabajo presupuestado',
            'hours_worked' => 40,
            'hours_approved' => 40,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        SalesDocument::query()->create([
            'company_id' => $this->company->id,
            'code' => 'ING-BGT',
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'document_type' => 'Factura',
            'issue_date' => '2026-08-10',
            'net_amount' => 1000000,
            'vat_amount' => 190000,
            'gross_amount' => 1190000,
            'status' => 'Confirmado',
            'is_voided' => false,
        ]);

        ExpenseDocument::query()->create([
            'company_id' => $this->company->id,
            'code' => 'EGR-BGT',
            'project_id' => $this->project->id,
            'vendor_name' => 'Proveedor presupuesto',
            'issue_date' => '2026-08-12',
            'net_amount' => 120000,
            'vat_amount' => 22800,
            'gross_amount' => 142800,
            'deductible_vat' => true,
            'payment_status' => 'Pendiente',
        ]);

        CashMovement::query()->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-BGT-1',
            'movement_type' => 'income',
            'source_document_type' => 'sales_document',
            'source_document_code' => 'ING-BGT',
            'project_id' => $this->project->id,
            'movement_date' => '2026-08-20',
            'income' => 3333333,
            'expense' => 0,
            'status' => 'posted',
        ]);

        CashMovement::query()->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-BGT-2',
            'movement_type' => 'expense',
            'source_document_type' => 'expense_document',
            'source_document_code' => 'EGR-BGT',
            'project_id' => $this->project->id,
            'movement_date' => '2026-08-22',
            'income' => 0,
            'expense' => 999999,
            'status' => 'posted',
        ]);

        $variance = app(BudgetService::class)->variance($this->company->id, '2026-08-01', $this->project->id);

        $this->assertSame(900000.0, $variance['revenue_budget']);
        $this->assertSame(700000.0, $variance['personnel_budget']);
        $this->assertSame(100000.0, $variance['other_direct_budget']);
        $this->assertSame(50000.0, $variance['other_indirect_budget']);
        $this->assertSame(150000.0, $variance['other_budget_total']);
        $this->assertSame(870000.0, $variance['total_budget']);

        $this->assertSame(1000000.0, $variance['revenue_real']);
        $this->assertSame(800000.0, $variance['personnel_real']);
        $this->assertSame(120000.0, $variance['other_real']);
        $this->assertSame(0.0, $variance['legal_real']);
        $this->assertSame(920000.0, $variance['total_real']);
        $this->assertSame(100000.0, $variance['revenue_difference']);
        $this->assertSame(50000.0, $variance['expense_difference']);
    }

    public function test_company_budget_variance_uses_recognized_legal_and_unassigned_personnel_costs(): void
    {
        Budget::query()->create([
            'company_id' => $this->company->id,
            'period_date' => '2026-08-01',
            'revenue_budget' => 1000000,
            'personnel_budget' => 700000,
            'other_direct_budget' => 100000,
            'legal_budget' => 50000,
            'other_indirect_budget' => 25000,
        ]);

        $person = Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'PER-BGT-UN',
            'name' => 'Persona Sin Proyecto',
            'modality' => 'Dependiente mensual',
            'monthly_value' => 600000,
            'status' => 'active',
        ]);

        PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-BGT-UN',
            'person_id' => $person->id,
            'period_date' => '2026-08-01',
            'employer_cost' => 600000,
            'vacation_provision' => 0,
            'net_pay' => 500000,
            'status' => 'Pendiente',
        ]);

        LegalObligation::query()->create([
            'company_id' => $this->company->id,
            'code' => 'OBL-BGT',
            'obligation_type' => 'IVA',
            'period_date' => '2026-08-01',
            'due_date' => '2026-09-12',
            'estimated_amount' => 71000,
            'pending_amount' => 71000,
            'status' => 'Pendiente',
        ]);

        $variance = app(BudgetService::class)->variance($this->company->id, '2026-08-01');

        $this->assertSame(600000.0, $variance['personnel_real']);
        $this->assertSame(71000.0, $variance['legal_real']);
        $this->assertSame(875000.0, $variance['total_budget']);
        $this->assertSame(671000.0, $variance['total_real']);
    }

    public function test_variance_for_budget_uses_the_current_budget_row_when_multiple_scenarios_exist(): void
    {
        $scenarioA = Scenario::query()->create([
            'company_id' => $this->company->id,
            'code' => 'BASE',
            'name' => 'Base',
            'sales_factor' => 1,
            'cost_factor' => 1,
            'collection_delay_days' => 0,
            'is_active' => true,
        ]);

        $scenarioB = Scenario::query()->create([
            'company_id' => $this->company->id,
            'code' => 'ALT',
            'name' => 'Alternativo',
            'sales_factor' => 1,
            'cost_factor' => 1,
            'collection_delay_days' => 0,
            'is_active' => false,
        ]);

        Budget::query()->create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'scenario_id' => $scenarioA->id,
            'period_date' => '2026-08-01',
            'revenue_budget' => 100000,
            'personnel_budget' => 200000,
            'other_direct_budget' => 300000,
            'legal_budget' => 400000,
            'other_indirect_budget' => 500000,
        ]);

        $budgetB = Budget::query()->create([
            'company_id' => $this->company->id,
            'project_id' => $this->project->id,
            'scenario_id' => $scenarioB->id,
            'period_date' => '2026-08-01',
            'revenue_budget' => 150000,
            'personnel_budget' => 250000,
            'other_direct_budget' => 350000,
            'legal_budget' => 450000,
            'other_indirect_budget' => 550000,
        ]);

        $variance = app(BudgetService::class)->varianceForBudget($budgetB);

        $this->assertSame(150000.0, $variance['revenue_budget']);
        $this->assertSame(250000.0, $variance['personnel_budget']);
        $this->assertSame(350000.0, $variance['other_direct_budget']);
        $this->assertSame(550000.0, $variance['other_indirect_budget']);
        $this->assertSame(900000.0, $variance['other_budget_total']);
        $this->assertSame(1600000.0, $variance['total_budget']);
    }
}
