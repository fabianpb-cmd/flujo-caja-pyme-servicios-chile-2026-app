<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\LegalParameter;
use App\Models\MonthlyClosure;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\SalesDocument;
use App\Models\Scenario;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\BudgetService;
use App\Services\CashFlowService;
use App\Services\CashMovementService;
use App\Services\DashboardService;
use App\Services\LegalObligationService;
use App\Services\MonthlyClosureService;
use App\Services\PayablesService;
use App\Services\ProfitabilityService;
use App\Services\ReceivablesService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalQaWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_end_to_end_financial_workflow_and_closed_period_controls(): void
    {
        [$company, $user, $cashAccount] = $this->baseCompany();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-E2E',
            'legal_name' => 'Cliente E2E',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-E2E',
            'name' => 'Proyecto E2E',
            'sale_net' => 3000000,
            'sale_total' => 3570000,
            'project_status' => 'active',
            'billing_status' => 'pending',
            'contracted_hourly_rate' => 100000,
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-E2E',
            'name' => 'Persona E2E',
            'modality' => 'Pago por hora',
            'hourly_value' => 50000,
            'status' => 'active',
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'code' => 'ASI-E2E',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'hourly_value' => 50000,
            'status' => 'active',
        ]);

        TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HOR-E2E',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'entry_date' => '2026-08-05',
            'activity' => 'Implementacion',
            'hours_worked' => 10,
            'hours_approved' => 10,
            'hourly_value' => 50000,
            'calculated_amount' => 500000,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        $payroll = PayrollRecord::query()->create([
            'company_id' => $company->id,
            'code' => 'REM-E2E',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'hours_approved' => 10,
            'hourly_value' => 50000,
            'base_salary' => 500000,
            'taxable_amount' => 500000,
            'vacation_provision' => 41650,
            'employer_cost' => 541650,
            'net_pay' => 500000,
            'calculation_status' => 'OK',
            'status' => 'Borrador',
        ]);
        app(\App\Services\PayrollService::class)->syncHourlyTimeEntryTrace($payroll, $person);
        app(\App\Services\PayrollService::class)->confirm($payroll, $user);

        $invoice = SalesDocument::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ING-E2E',
            'document_type' => 'Factura',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'projected_collection_date' => '2026-08-31',
            'net_amount' => 3000000,
            'vat_amount' => 570000,
            'gross_amount' => 3570000,
            'status' => 'Pendiente',
            'is_voided' => false,
        ]);

        $expense = ExpenseDocument::query()->create([
            'company_id' => $company->id,
            'code' => 'EGR-E2E',
            'vendor_name' => 'Proveedor E2E',
            'project_id' => $project->id,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'net_amount' => 300000,
            'vat_amount' => 57000,
            'recoverable_vat_amount' => 57000,
            'gross_amount' => 357000,
            'payment_status' => 'Pendiente',
            'deductible_vat' => true,
        ]);

        $cash = app(CashMovementService::class);
        $cash->create($this->movement($company, $cashAccount, 'MOV-E2E-1', 'sales_document', 'ING-E2E', '2026-08-10', 1000000, 0, $project), $user);
        $this->assertSame(2570000.0, app(ReceivablesService::class)->balance($invoice->refresh()));
        $this->assertSame('Parcial', $invoice->status);

        $cash->create($this->movement($company, $cashAccount, 'MOV-E2E-2', 'sales_document', 'ING-E2E', '2026-08-20', 2570000, 0, $project), $user);
        $this->assertSame(0.0, app(ReceivablesService::class)->balance($invoice->refresh()));
        $this->assertSame('Pagado', $invoice->status);

        $cash->create($this->movement($company, $cashAccount, 'MOV-E2E-3', 'expense_document', 'EGR-E2E', '2026-08-21', 0, 357000, $project), $user);
        $this->assertSame(0.0, app(PayablesService::class)->balance($expense->refresh()));
        $this->assertSame('Pagado', $expense->payment_status);

        $cash->create($this->movement($company, $cashAccount, 'MOV-E2E-4', 'payroll_record', 'REM-E2E', '2026-08-25', 0, 500000, $project), $user);
        $this->assertSame('Pagado', $payroll->refresh()->status);

        app(LegalObligationService::class)->syncMonthlyObligations($company->id, '2026-08-01', 1);
        $flow = app(CashFlowService::class)->monthly($company->id, '2026-08-01', 1)[0];
        $this->assertSame(3570000.0, $flow['income_real']);
        $this->assertSame(357000.0, $flow['other_real']);
        $this->assertSame(500000.0, $flow['personnel_real']);

        $this->assertSame(0.0, app(ReceivablesService::class)->accountsReceivable($company->id, '2026-08-31'));
        $this->assertSame(0.0, app(PayablesService::class)->accountsPayable($company->id, '2026-08-31'));
        $this->assertNotEmpty(app(ProfitabilityService::class)->byProject($company->id));
        $this->assertArrayHasKey('kpis', app(DashboardService::class)->data($company->id));

        $closure = app(MonthlyClosureService::class)->close($company->id, '2026-08-01', $user);
        $this->assertSame('closed', $closure->status);

        $this->expectException(DomainException::class);
        app(CashMovementService::class)->create($this->movement($company, $cashAccount, 'MOV-E2E-BLOCK', 'expense_document', 'EGR-E2E', '2026-08-28', 0, 1, $project), $user);
    }

    public function test_documents_do_not_count_as_real_cash_until_cash_movement_exists(): void
    {
        [$company, $user, $cashAccount] = $this->baseCompany();
        [$client, $project, $person] = $this->commercialSetup($company);

        SalesDocument::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ING-DC',
            'document_type' => 'Factura',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'projected_collection_date' => '2026-08-31',
            'net_amount' => 1000000,
            'vat_amount' => 190000,
            'gross_amount' => 1190000,
            'status' => 'Pendiente',
        ]);

        ExpenseDocument::query()->create([
            'company_id' => $company->id,
            'code' => 'EGR-DC',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'project_id' => $project->id,
            'net_amount' => 100000,
            'vat_amount' => 19000,
            'recoverable_vat_amount' => 19000,
            'gross_amount' => 119000,
            'payment_status' => 'Pendiente',
        ]);

        $payroll = PayrollRecord::query()->create([
            'company_id' => $company->id,
            'code' => 'REM-DC',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'net_pay' => 200000,
            'calculation_status' => 'OK',
            'status' => 'Confirmado',
        ]);

        LegalObligation::query()->create([
            'company_id' => $company->id,
            'code' => 'OBL-DC',
            'obligation_type' => 'IVA',
            'period_date' => '2026-08-01',
            'due_date' => '2026-09-12',
            'estimated_amount' => 71000,
            'pending_amount' => 71000,
            'status' => 'Pendiente',
        ]);

        $flow = app(CashFlowService::class)->monthly($company->id, '2026-08-01', 1)[0];
        $this->assertSame(0.0, $flow['income_real']);
        $this->assertSame(0.0, $flow['other_real']);
        $this->assertSame(0.0, $flow['personnel_real']);
        $this->assertSame(0.0, $flow['legal_real']);

        $cash = app(CashMovementService::class);
        $cash->create($this->movement($company, $cashAccount, 'MOV-DC-1', 'sales_document', 'ING-DC', '2026-08-15', 1190000, 0, $project), $user);
        $cash->create($this->movement($company, $cashAccount, 'MOV-DC-2', 'expense_document', 'EGR-DC', '2026-08-16', 0, 119000, $project), $user);
        $cash->create($this->movement($company, $cashAccount, 'MOV-DC-3', 'payroll_record', 'REM-DC', '2026-08-17', 0, 200000, $project), $user);
        $cash->create($this->movement($company, $cashAccount, 'MOV-DC-4', 'legal_obligation', 'OBL-DC', '2026-08-18', 0, 71000, $project), $user);
        $cash->create($this->movement($company, $cashAccount, 'MOV-DC-5', null, null, '2026-08-19', 0, 50000, $project), $user);

        $flow = app(CashFlowService::class)->monthly($company->id, '2026-08-01', 1)[0];
        $this->assertSame(1190000.0, $flow['income_real']);
        $this->assertSame(169000.0, $flow['other_real']);
        $this->assertSame(200000.0, $flow['personnel_real']);
        $this->assertSame(71000.0, $flow['legal_real']);
    }

    public function test_security_authorization_csrf_and_audit_coverage(): void
    {
        [$company, $user] = $this->baseCompany();
        $otherCompany = Company::query()->create(['code' => 'CMP-OTHER', 'name' => 'Otra', 'status' => 'active']);
        $otherClient = Client::query()->create([
            'company_id' => $otherCompany->id,
            'code' => 'CLI-OTHER',
            'legal_name' => 'Cliente Otro',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->put(route('operational.update', ['clients', $otherClient->id]), ['legal_name' => 'Hack'])->assertRedirect(route('login'));

        $this->actingAs($user);
        $this->get(route('operational.edit', ['clients', $otherClient->id]))->assertForbidden();

        $this->post(route('operational.store', 'projects'), [
            'code' => 'PRY-XTENANT',
            'client_id' => $otherClient->id,
            'name' => 'Proyecto cruzado',
            'project_status_id' => $this->recordStatusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->recordStatusId($company->id, 'billing', 'pending'),
            'sale_net' => 1000,
            'vat_rate' => 0.19,
            'sale_total' => 1190,
        ])->assertSessionHasErrors('client_id');

        $response = $this->post(route('operational.store', 'clients'), [
            'code' => 'CLI-AUD',
            'legal_name' => 'Cliente Audit',
            'payment_term_days' => 30,
            'client_status_id' => $this->recordStatusId($company->id, 'client', 'active'),
        ]);
        $response->assertRedirect(route('operational.index', 'clients'));
        $client = Client::query()->where('code', 'CLI-AUD')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', ['action' => 'operational.created', 'auditable_id' => $client->id]);

        $this->put(route('operational.update', ['clients', $client->id]), [
            'code' => 'CLI-AUD',
            'legal_name' => 'Cliente Audit Editado',
            'payment_term_days' => 45,
            'client_status_id' => $this->recordStatusId($company->id, 'client', 'active'),
        ])->assertRedirect(route('operational.show', ['clients', $client->id]));
        $this->assertDatabaseHas('audit_logs', ['action' => 'operational.updated', 'auditable_id' => $client->id]);

        $closure = app(MonthlyClosureService::class)->close($company->id, '2026-08-01', $user);
        app(MonthlyClosureService::class)->reopen($closure, $user);

        $this->assertDatabaseHas('audit_logs', ['action' => 'monthly_closure.closed', 'auditable_id' => $closure->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'monthly_closure.reopened', 'auditable_id' => $closure->id]);
    }

    private function baseCompany(): array
    {
        $company = Company::query()->create(['code' => 'CMP-QA', 'name' => 'Empresa QA', 'status' => 'active']);
        app(CatalogService::class)->seedDefaultsForCompany($company->id);
        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Admin QA',
            'email' => uniqid('qa').'@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);
        $cashAccount = CashAccount::query()->create([
            'company_id' => $company->id,
            'code' => 'BANK-QA-'.uniqid(),
            'name' => 'Banco QA',
            'currency' => 'CLP',
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        foreach ([['margin_minimum', '0.15'], ['active_scenario', 'BASE'], ['obligation_due_day', '13']] as [$key, $value]) {
            CompanySetting::query()->create(['company_id' => $company->id, 'setting_key' => $key, 'setting_value' => $value]);
        }

        foreach ([
            ['IVA', 0.19],
            ['RETENCION_HONORARIOS', 0.1525],
            ['PROVISION_VACACIONES', 0.0833],
            ['PPM_RATE', 0.01],
            ['COTIZACION_EMPLEADOR', 0.01],
            ['SIS_RATE', 0.0154],
            ['AFC_RATE', 0.006],
            ['IMPUESTO_SEGUNDA_CATEGORIA_RATE', 0],
        ] as [$code, $value]) {
            LegalParameter::query()->create([
                'company_id' => $company->id,
                'parameter_code' => $code,
                'parameter_name' => $code,
                'valid_from' => '2026-01-01',
                'valid_to' => '2027-12-31',
                'value' => $value,
                'unit' => '%',
            ]);
        }

        Scenario::query()->create([
            'company_id' => $company->id,
            'code' => 'BASE',
            'name' => 'Base',
            'sales_factor' => 1,
            'cost_factor' => 1,
            'collection_delay_days' => 0,
            'is_active' => true,
        ]);

        return [$company, $user, $cashAccount];
    }

    private function recordStatusId(int $companyId, string $domain, string $code): int
    {
        return \App\Models\RecordStatus::query()
            ->where('company_id', $companyId)
            ->where('domain', $domain)
            ->where('code', $code)
            ->valueOrFail('id');
    }

    private function commercialSetup(Company $company): array
    {
        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-DC',
            'legal_name' => 'Cliente DC',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-DC',
            'name' => 'Proyecto DC',
            'sale_net' => 1000000,
            'sale_total' => 1190000,
            'project_status' => 'active',
            'billing_status' => 'pending',
        ]);
        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-DC',
            'name' => 'Persona DC',
            'modality' => 'Pago por hora',
            'hourly_value' => 50000,
            'status' => 'active',
        ]);

        return [$client, $project, $person];
    }

    private function movement(Company $company, CashAccount $account, string $code, ?string $sourceType, ?string $sourceCode, string $date, float $income, float $expense, ?Project $project): array
    {
        return [
            'company_id' => $company->id,
            'code' => $code,
            'movement_type' => $income > 0 ? 'Ingreso' : 'Egreso',
            'source_document_type' => $sourceType,
            'source_document_code' => $sourceCode,
            'movement_date' => $date,
            'income' => $income,
            'expense' => $expense,
            'project_id' => $project?->id,
            'cash_account_id' => $account->id,
            'status' => 'posted',
        ];
    }
}
