<?php

namespace Tests\Feature;

use App\Models\Afp;
use App\Models\AfpRate;
use App\Models\AuditLog;
use App\Models\ApprovalStatus;
use App\Models\Budget;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ContractType;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\LegalParameter;
use App\Models\MonthlyClosure;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\SalesDocument;
use App\Models\SalesDocumentTimeEntry;
use App\Models\Scenario;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\UfValue;
use App\Services\PayrollBatchService;
use App\Services\SalesPrefacturationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mysql-critical')]
class MySqlCriticalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private int $contractTypeId;
    private int $afpId;
    private Client $client;
    private Project $project;
    private int $approvedStatusId;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL critical suite only runs with phpunit.mysql.xml.');
        }

        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertStringContainsString('mysql_critical', DB::connection()->getDatabaseName());
        $this->assertTrue(Schema::hasTable('clients'));
        $this->assertTrue(Schema::hasTable('projects'));
        $this->assertTrue(Schema::hasTable('people'));

        $this->company = Company::query()->create([
            'code' => 'CMP-MYSQL-CRIT',
            'name' => 'Empresa MySQL Critica',
            'status' => 'active',
        ]);

        $this->admin = User::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Admin MySQL',
            'email' => 'mysql-critical@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        $this->seedCurrencies();
        $this->seedLegalParameters();
        $this->seedAfp();
        $this->contractTypeId = ContractType::query()->create([
            'company_id' => $this->company->id,
            'domain' => 'employment',
            'code' => 'INDEFINIDO',
            'name' => 'Indefinido',
            'active' => true,
        ])->id;

        $this->client = Client::query()->create([
            'company_id' => $this->company->id,
            'legal_name' => 'Cliente MySQL',
        ]);

        $this->project = Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'code' => 'PRY-MYSQL',
            'name' => 'Proyecto MySQL',
            'sales_currency_id' => Currency::query()->where('company_id', $this->company->id)->where('code', 'CLP')->value('id'),
        ]);

        $this->approvedStatusId = ApprovalStatus::query()->create([
            'company_id' => $this->company->id,
            'code' => 'approved',
            'name' => 'Aprobado',
            'active' => true,
        ])->id;
    }

    public function test_schema_currency_and_code_generation_are_available_in_mysql(): void
    {
        $this->assertSame(0, (int) Currency::query()->where('code', 'CLP')->value('minor_units'));
        $this->assertSame(2, (int) Currency::query()->where('code', 'USD')->value('minor_units'));

        $client = Client::query()->create([
            'company_id' => $this->company->id,
            'legal_name' => 'Cliente Auto Code',
        ]);

        $project = Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'name' => 'Proyecto Auto Code',
        ]);

        $person = $this->person();

        $this->assertMatchesRegularExpression('/^[A-Z]{3}-\d+$/', $client->code);
        $this->assertMatchesRegularExpression('/^[A-Z]{3}-\d+$/', $project->code);
        $this->assertMatchesRegularExpression('/^[A-Z]{3}-\d+$/', $person->code);

        $originalCode = $person->code;
        $person->update(['code' => 'HACK-999']);
        $this->assertSame($originalCode, $person->refresh()->code);
    }

    public function test_rut_unique_per_company_is_enforced_in_mysql(): void
    {
        $this->person(['rut' => '12345678-5']);

        $this->expectException(QueryException::class);

        $this->person(['rut' => '12345678-5', 'first_names' => 'Otro', 'paternal_surname' => 'Duplicado']);
    }

    public function test_fk_constraints_require_valid_parent_records(): void
    {
        $this->expectException(QueryException::class);

        Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => 999999,
            'name' => 'Proyecto FK roto',
        ]);
    }

    public function test_person_project_assignment_links_are_saved(): void
    {
        $person = $this->person(['modality' => 'Dependiente mensual', 'monthly_value' => 1000000]);
        $assignment = ProjectAssignment::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'code' => 'ASI-MYSQL-'.uniqid(),
            'hourly_value' => 35000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('project_assignments', [
            'id' => $assignment->id,
            'person_id' => $person->id,
            'project_id' => $this->project->id,
        ]);
    }

    public function test_payroll_generation_is_idempotent_and_preserves_confirmed_snapshot(): void
    {
        $person = $this->person([
            'modality' => 'Dependiente mensual',
            'monthly_value' => 1000000,
            'afp_id' => $this->afpId,
            'employment_contract_type_id' => $this->contractTypeId,
        ]);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');
        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $originalSnapshot = $record->legal_snapshot;

        $this->assertSame(1, $summary['generated']);
        $this->assertSame(1, PayrollRecord::query()->where('person_id', $person->id)->count());
        $this->assertNotEmpty($record->status);

        $record->update(['status' => 'Confirmado']);
        LegalParameter::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'parameter_code' => 'AFP_TRABAJADOR', 'valid_from' => '2026-01-01'],
            ['parameter_name' => 'AFP trabajador', 'value' => 0.11, 'unit' => '%', 'valid_to' => null, 'active' => true]
        );

        $summaryAgain = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01', true);

        $this->assertSame(1, $summaryAgain['omitted']);
        $this->assertSame(1, PayrollRecord::query()->where('person_id', $person->id)->count());
        $this->assertSame($originalSnapshot, PayrollRecord::query()->where('person_id', $person->id)->firstOrFail()->legal_snapshot);
    }

    public function test_sales_hh_cannot_be_billed_twice_in_mysql(): void
    {
        $person = $this->person([
            'modality' => 'Honorarios mensual',
            'monthly_value' => 100000,
        ]);
        $project = Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'code' => 'PRY-SALES-MYSQL',
            'name' => 'Proyecto Ventas MySQL',
            'sales_currency_id' => Currency::query()->where('company_id', $this->company->id)->where('code', 'CLP')->value('id'),
        ]);
        $assignment = ProjectAssignment::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'client_id' => $this->client->id,
            'project_id' => $project->id,
            'code' => 'ASI-SALES-'.uniqid(),
            'hourly_value' => 35000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'start_date' => '2026-08-01',
            'status' => 'active',
        ]);
        TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HOR-SALES-'.uniqid(),
            'person_id' => $person->id,
            'client_id' => $this->client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-09',
            'activity' => 'Consultoría',
            'hours_worked' => 10,
            'hours_approved' => 10,
            'hourly_value' => 35000,
            'approval_status' => 'approved',
            'approval_status_id' => $this->approvedStatusId,
            'payment_status' => 'pending',
        ]);

        $service = app(SalesPrefacturationService::class);
        $draft = $service->generateDraft($this->company->id, [
            'project_id' => $project->id,
            'period' => '2026-08-01',
            'issue_date' => '2026-08-09',
            'taxable' => true,
        ]);

        $this->assertSame('Borrador', $draft->status);
        $this->assertSame(1, SalesDocumentTimeEntry::query()->count());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('No existen HH aprobadas facturables');
        $service->generateDraft($this->company->id, [
            'project_id' => $project->id,
            'period' => '2026-08-01',
            'issue_date' => '2026-08-09',
            'taxable' => true,
        ]);
    }

    public function test_uat_clear_data_command_removes_operational_data_in_mysql(): void
    {
        $client = $this->client;
        $project = $this->project;
        $person = $this->person();
        $assignment = ProjectAssignment::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-UAT-MYSQL',
            'start_date' => '2026-08-01',
            'status' => 'active',
        ]);
        $timeEntry = TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HOR-UAT-MYSQL',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-10',
            'activity' => 'Consultoría',
            'hours_worked' => 8,
            'hours_approved' => 8,
            'hourly_value' => 35000,
            'calculated_amount' => 280000,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);
        $sales = SalesDocument::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ING-UAT-MYSQL',
            'document_type' => 'Factura',
            'issue_date' => '2026-08-10',
            'net_amount' => 280000,
            'vat_amount' => 53200,
            'gross_amount' => 333200,
            'status' => 'Pendiente',
            'is_voided' => false,
        ]);
        $sales->timeEntries()->attach($timeEntry->id, [
            'company_id' => $this->company->id,
            'hours_approved' => 8,
            'hourly_rate_amount' => 35000,
            'rate_unit_type' => 'CURRENCY',
            'subtotal_original' => 280000,
            'subtotal_clp' => 280000,
        ]);
        $payroll = PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-UAT-MYSQL',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'base_salary' => 500000,
            'employer_cost' => 600000,
            'net_pay' => 500000,
            'status' => 'Pendiente',
        ]);
        $payroll->timeEntries()->attach($timeEntry->id);
        PayrollAdjustment::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'period_date' => '2026-08-01',
            'type' => 'bono',
            'amount' => 100000,
            'description' => 'Bono UAT',
            'active' => true,
        ]);
        ExpenseDocument::query()->create([
            'company_id' => $this->company->id,
            'code' => 'EGR-UAT-MYSQL',
            'vendor_name' => 'Proveedor UAT',
            'project_id' => $project->id,
            'issue_date' => '2026-08-10',
            'net_amount' => 10000,
            'gross_amount' => 11900,
            'payment_status' => 'Pendiente',
        ]);
        LegalObligation::query()->create([
            'company_id' => $this->company->id,
            'code' => 'OBL-UAT-MYSQL',
            'obligation_type' => 'IVA',
            'period_date' => '2026-08-01',
            'due_date' => '2026-09-12',
            'estimated_amount' => 53200,
            'pending_amount' => 53200,
            'status' => 'Pendiente',
        ]);
        CashMovement::query()->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-UAT-MYSQL',
            'movement_type' => 'income',
            'source_document_type' => 'sales_document',
            'source_document_code' => $sales->code,
            'project_id' => $project->id,
            'movement_date' => '2026-08-10',
            'income' => 333200,
            'expense' => 0,
            'cash_account_id' => CashAccount::query()->where('company_id', $this->company->id)->value('id'),
            'status' => 'posted',
            'created_by_user_id' => $this->admin->id,
        ]);
        Budget::query()->create([
            'company_id' => $this->company->id,
            'project_id' => $project->id,
            'scenario_id' => Scenario::query()->where('company_id', $this->company->id)->where('code', 'BASE')->value('id'),
            'period_date' => '2026-08-01',
            'revenue_budget' => 500000,
            'personnel_budget' => 200000,
            'other_direct_budget' => 100000,
            'legal_budget' => 50000,
            'other_indirect_budget' => 25000,
        ]);
        MonthlyClosure::query()->create([
            'company_id' => $this->company->id,
            'period_date' => '2026-08-01',
            'opening_balance' => 0,
            'closing_balance' => 333200,
            'cash_in' => 333200,
            'cash_out' => 0,
            'status' => 'open',
        ]);
        AuditLog::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'action' => 'demo',
        ]);

        $this->artisan('uat:clear-data', ['--force' => true])->assertExitCode(0);

        foreach ([
            'payroll_record_time_entries',
            'time_entries',
            'sales_document_time_entries',
            'payroll_records',
            'payroll_adjustments',
            'sales_documents',
            'expense_documents',
            'legal_obligations',
            'cash_movements',
            'budgets',
            'monthly_closures',
            'audit_logs',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' debe quedar vacío');
        }

        $this->assertGreaterThan(0, Company::count());
        $this->assertGreaterThan(0, User::query()->count());
        $this->assertTrue(Client::query()->whereKey($client->id)->exists());
        $this->assertTrue(Project::query()->whereKey($project->id)->exists());
        $this->assertTrue(Person::query()->whereKey($person->id)->exists());
        $this->assertTrue(ProjectAssignment::query()->whereKey($assignment->id)->exists());
        $this->assertGreaterThan(0, Currency::count());
        $this->assertGreaterThan(0, LegalParameter::count());
        $this->assertGreaterThan(0, UfValue::count());
    }

    private function seedCurrencies(): void
    {
        foreach ([
            ['code' => 'CLP', 'name' => 'Peso chileno', 'symbol' => '$', 'minor_units' => 0, 'base' => true],
            ['code' => 'USD', 'name' => 'Dólar estadounidense', 'symbol' => 'US$', 'minor_units' => 2, 'base' => false],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'minor_units' => 2, 'base' => false],
            ['code' => 'UF', 'name' => 'Unidad de Fomento', 'symbol' => 'UF', 'minor_units' => 2, 'base' => false],
        ] as $currency) {
            Currency::query()->updateOrCreate(
                ['company_id' => $this->company->id, 'code' => $currency['code']],
                [
                    'name' => $currency['name'],
                    'symbol' => $currency['symbol'],
                    'minor_units' => $currency['minor_units'],
                    'active' => true,
                    'is_base_currency' => $currency['base'],
                    'sort_order' => 10,
                ]
            );
        }
    }

    private function seedLegalParameters(): void
    {
        foreach ([
            ['IVA', 'IVA', '2026-01-01', null, 0.19, '%'],
            ['RETENCION_HONORARIOS', 'Retencion honorarios', '2026-01-01', '2026-12-31', 0.1525, '%'],
            ['AFP_TRABAJADOR', 'AFP trabajador', '2026-01-01', null, 0.10, '%'],
            ['SALUD_MINIMA', 'Salud minima', '2026-01-01', null, 0.07, '%'],
            ['AFC_TRABAJADOR_INDEFINIDO', 'AFC trabajador indefinido', '2026-01-01', null, 0.006, '%'],
            ['AFC_EMPLEADOR_INDEFINIDO', 'AFC empleador indefinido', '2026-01-01', null, 0.024, '%'],
            ['AFC_EMPLEADOR_PLAZO_FIJO', 'AFC empleador plazo fijo', '2026-01-01', null, 0.03, '%'],
            ['LEY_16744_BASICA', 'Ley 16744 basica', '2026-01-01', null, 0.009, '%'],
            ['LEY_16744_ADICIONAL', 'Ley 16744 adicional', '2026-01-01', null, 0.0, '%'],
            ['SANNA_RATE', 'SANNA', '2026-01-01', null, 0.0003, '%'],
            ['TOPE_IMPONIBLE_UF', 'Tope previsional', '2026-01-01', '2026-12-31', 90.0, 'UF'],
            ['TOPE_AFC_UF', 'Tope AFC', '2026-01-01', '2026-12-31', 135.2, 'UF'],
            ['COTIZACION_EMPLEADOR', 'Cotizacion empleador', '2026-01-01', '2026-07-31', 0.01, '%'],
            ['SIS_RATE', 'SIS', '2026-01-01', '2026-07-31', 0.0162, '%'],
            ['COTIZACION_EMPLEADOR', 'Cotizacion empleador', '2026-08-01', '2027-07-31', 0.035, '%'],
            ['SIS_RATE', 'SIS integrado', '2026-08-01', '2027-07-31', 0.0, '%'],
        ] as [$code, $name, $from, $to, $value, $unit]) {
            LegalParameter::query()->updateOrCreate(
                ['company_id' => $this->company->id, 'parameter_code' => $code, 'valid_from' => $from],
                [
                    'parameter_name' => $name,
                    'valid_to' => $to,
                    'value' => $value,
                    'unit' => $unit,
                    'active' => true,
                ]
            );
        }

        UfValue::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'value_date' => '2026-08-01'],
            ['value' => 40844.79, 'active' => true]
        );
    }

    private function seedAfp(): void
    {
        $this->afpId = Afp::query()->updateOrCreate(
            ['code' => 'HABITAT'],
            ['name' => 'Habitat']
        )->id;

        AfpRate::query()->updateOrCreate(
            ['afp_id' => $this->afpId, 'valid_from' => '2026-01-01'],
            [
                'employee_commission_rate' => 0.0127,
                'employer_commission_rate' => 0.0,
                'insurance_rate' => 0.0,
            ]
        );
    }

    private function person(array $overrides = []): Person
    {
        return Person::query()->create(array_merge([
            'company_id' => $this->company->id,
            'first_names' => 'Persona',
            'paternal_surname' => 'MySQL',
            'rut' => '12345678-5',
            'email' => 'persona-'.uniqid().'@test.local',
            'modality' => 'Dependiente mensual',
            'contract_type' => 'Indefinido',
            'employment_mode_id' => DB::table('employment_modes')->where('code', 'DEPENDIENTE_MENSUAL')->value('id'),
            'employment_contract_type_id' => $this->contractTypeId,
            'afp_id' => $this->afpId,
            'health_system' => 'Fonasa',
            'health_system_id' => DB::table('health_systems')->where('code', 'FONASA')->value('id'),
            'monthly_value' => 1000000,
            'hourly_value' => 0,
            'status' => 'active',
            'start_date' => '2026-01-01',
        ], $overrides));
    }
}
