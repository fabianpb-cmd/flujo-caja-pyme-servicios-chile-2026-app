<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\Afp;
use App\Models\AfpRate;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\ApprovalStatus;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ContractType;
use App\Models\ExpenseDocument;
use App\Models\LegalParameter;
use App\Models\MonthlyClosure;
use App\Models\Person;
use App\Models\PayrollRecord;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\RecordStatus;
use App\Models\SalesDocument;
use App\Models\TimeEntry;
use App\Models\UfValue;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\CompanySettingsService;
use App\Services\HourlyRateService;
use App\Services\IncomeTaxService;
use App\Services\LegalParameterService;
use App\Services\PayablesService;
use App\Services\PayrollService;
use App\Services\ReceivablesService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialCoreTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private CashAccount $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'code' => 'CMP-TST',
            'name' => 'Empresa Test',
            'status' => 'active',
        ]);

        $this->user = User::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Admin Test',
            'email' => 'admin@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        $this->cashAccount = CashAccount::query()->create([
            'company_id' => $this->company->id,
            'code' => 'BANK-TST',
            'name' => 'Banco Test',
            'currency' => 'CLP',
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        $this->seed(\Database\Seeders\IncomeTaxBracketSeeder::class);
        $this->seedAfp('HABITAT', 0.0127);
        $this->seedUf('2026-02-01', 40844.79);
        $this->seedUf('2026-03-01', 40844.79);
        $this->seedUf('2026-06-01', 40844.79);
        $this->seedUf('2026-07-01', 40844.79);
        $this->seedUf('2026-08-01', 40844.79);
        $this->seedLegalParameter('IVA', 'IVA', '2026-01-01', null, 0.19);
        $this->seedLegalParameter('RETENCION_HONORARIOS', 'Retencion honorarios', '2026-01-01', '2026-12-31', 0.1525);
        $this->seedLegalParameter('RETENCION_HONORARIOS', 'Retencion honorarios', '2027-01-01', '2027-12-31', 0.16);
        $this->seedLegalParameter('AFP_TRABAJADOR', 'AFP trabajador', '2026-01-01', null, 0.10);
        $this->seedLegalParameter('SALUD_MINIMA', 'Salud minima', '2026-01-01', null, 0.07);
        $this->seedLegalParameter('AFC_TRABAJADOR_INDEFINIDO', 'AFC trabajador indefinido', '2026-01-01', null, 0.006);
        $this->seedLegalParameter('AFC_EMPLEADOR_INDEFINIDO', 'AFC empleador indefinido', '2026-01-01', null, 0.024);
        $this->seedLegalParameter('AFC_EMPLEADOR_PLAZO_FIJO', 'AFC empleador plazo fijo', '2026-01-01', null, 0.03);
        $this->seedLegalParameter('LEY_16744_BASICA', 'Ley 16744 basica', '2026-01-01', null, 0.009);
        $this->seedLegalParameter('LEY_16744_ADICIONAL', 'Ley 16744 adicional', '2026-01-01', null, 0);
        $this->seedLegalParameter('SANNA_RATE', 'SANNA', '2026-01-01', null, 0.0003);
        $this->seedLegalParameter('TOPE_IMPONIBLE_UF', 'Tope previsional', '2026-01-01', '2026-12-31', 90.0);
        $this->seedLegalParameter('TOPE_AFC_UF', 'Tope AFC', '2026-01-01', '2026-12-31', 135.2);
        $this->seedLegalParameter('COTIZACION_EMPLEADOR', 'Cotizacion empleador', '2026-01-01', '2026-07-31', 0.01);
        $this->seedLegalParameter('SIS_RATE', 'SIS', '2026-01-01', '2026-07-31', 0.0162);
        $this->seedLegalParameter('COTIZACION_EMPLEADOR', 'Cotizacion empleador', '2026-08-01', '2027-07-31', 0.035);
        $this->seedLegalParameter('SIS_RATE', 'SIS integrado', '2026-08-01', '2027-07-31', 0.0);
    }

    public function test_iva_is_calculated_from_legal_parameter(): void
    {
        $amounts = app(ReceivablesService::class)->amountsWithVat($this->company->id, 1000, '2026-07-01');

        $this->assertSame(1000.0, $amounts['net_amount']);
        $this->assertSame(190.0, $amounts['vat_amount']);
        $this->assertSame(1190.0, $amounts['gross_amount']);
    }

    public function test_missing_historical_parameter_raises_controlled_error(): void
    {
        $other = Company::query()->create([
            'code' => 'CMP-NOPARAM',
            'name' => 'Sin parámetros',
            'status' => 'active',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Falta parametro legal IVA vigente para 2026-08-09.');

        app(ReceivablesService::class)->amountsWithVat($other->id, 1000, '2026-08-09');
    }

    public function test_honorarios_use_historical_retention_rate(): void
    {
        $person = $this->person([
            'modality' => 'Honorarios mensual',
            'monthly_value' => 1000000,
        ]);

        $payroll = app(PayrollService::class)->calculate($person, '2026-07-01');

        $this->assertSame(152500.0, $payroll['employee_retention']);
        $this->assertSame(847500.0, $payroll['net_pay']);
        $this->assertSame(0.0, $payroll['vacation_provision']);
        $this->assertSame(0.0, $payroll['taxable_amount']);
    }

    public function test_hourly_non_dependent_payments_use_honorarios_retention_without_vacation_provision(): void
    {
        $person = $this->person([
            'modality' => 'Pago por hora',
            'hourly_value' => 32800,
        ]);

        $payroll = app(PayrollService::class)->calculate($person, '2026-07-01', ['hours_approved' => 10]);

        $this->assertSame(328000.0, $payroll['base_salary']);
        $this->assertSame(50020.0, $payroll['employee_retention']);
        $this->assertSame(277980.0, $payroll['net_pay']);
        $this->assertSame(0.0, $payroll['vacation_provision']);
        $this->assertSame(0.0, $payroll['taxable_amount']);
    }

    public function test_monthly_salary_is_proportional_to_worked_days(): void
    {
        $person = $this->person([
            'modality' => 'Dependiente mensual',
            'monthly_value' => 1000000,
            'start_date' => '2026-06-16',
        ]);

        $payroll = app(PayrollService::class)->calculate($person, '2026-06-01');

        $this->assertSame(15, $payroll['worked_days']);
        $this->assertSame(30, $payroll['month_days']);
        $this->assertSame(500000.0, $payroll['base_salary']);
        $this->assertSame(0.625, (float) $payroll['vacation_days_accrued_period']);
        $this->assertSame(20833.33, $payroll['vacation_provision']);
    }

    public function test_honorarios_2026_retention_exact_case(): void
    {
        $payroll = app(PayrollService::class)->calculate($this->person([
            'modality' => 'Honorarios mensual',
            'monthly_value' => 100000,
        ]), '2026-07-01');

        $this->assertSame(15250.0, $payroll['employee_retention']);
        $this->assertSame(84750.0, $payroll['net_pay']);
    }

    public function test_payroll_uses_person_hourly_rate_for_remuneration_even_when_assignment_has_project_cost_rate(): void
    {
        $client = Client::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CLI-PRL-01',
            'legal_name' => 'Cliente Payroll',
            'client_status_id' => $this->statusId('client', 'active'),
        ]);
        $project = Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $client->id,
            'code' => 'PRY-PRL-01',
            'name' => 'Proyecto Payroll',
            'sales_currency_id' => Currency::query()->where('company_id', $this->company->id)->where('code', 'UF')->value('id'),
            'contracted_hourly_rate' => 0.50,
            'project_status_id' => $this->statusId('project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId('billing', 'pending'),
        ]);

        $person = $this->person([
            'modality' => 'Dependiente por hora',
            'hourly_value' => 1.10,
            'hourly_rate_unit_type' => 'UF',
            'monthly_value' => 0,
        ]);

        $assignmentStatus = RecordStatus::query()->create([
            'company_id' => $this->company->id,
            'domain' => 'assignment',
            'code' => 'active',
            'name' => 'Activo',
            'active' => true,
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_status_id' => $assignmentStatus->id,
            'code' => 'ASI-PRL-01',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'hourly_value' => 1.30,
            'hourly_rate_unit_type' => 'UF',
            'project_value' => 0,
        ]);

        $approvalStatus = ApprovalStatus::query()->create([
            'company_id' => $this->company->id,
            'code' => 'approved',
            'name' => 'Aprobado',
            'active' => true,
        ]);

        $activity = Activity::query()->create([
            'company_id' => $this->company->id,
            'code' => 'ACT-PRL-01',
            'name' => 'Actividad Payroll',
            'active' => true,
        ]);

        TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HRS-PRL-01',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-12',
            'activity' => 'Actividad Payroll',
            'activity_id' => $activity->id,
            'hours_worked' => 10,
            'hours_approved' => 10,
            'hourly_value' => 1.30,
            'calculated_amount' => 10 * 1.30,
            'approval_status_id' => $approvalStatus->id,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        $payroll = app(PayrollService::class)->calculate($person, '2026-08-01', ['project_id' => $project->id]);

        $this->assertSame(10.0, $payroll['hours_approved']);
        $this->assertGreaterThan(0, $payroll['base_salary']);
        $this->assertSame('OK', $payroll['calculation_status']);
        $this->assertSame(app(HourlyRateService::class)->resolvePersonRate($person->fresh(['hourlyRateCurrency']), '2026-08-01'), (float) $payroll['hourly_value']);

        $record = PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-PRL-01',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'hours_approved' => $payroll['hours_approved'],
            'hourly_value' => $payroll['hourly_value'],
            'project_value' => $payroll['project_value'],
            'base_salary' => $payroll['base_salary'],
            'gross_amount' => $payroll['gross_amount'],
            'taxable_amount' => $payroll['taxable_amount'],
            'taxable_gross' => $payroll['taxable_gross'],
            'employee_retention' => $payroll['employee_retention'],
            'retention_rate' => $payroll['retention_rate'],
            'employer_cost' => $payroll['employer_cost'],
            'net_pay' => $payroll['net_pay'],
            'calculation_status' => $payroll['calculation_status'],
            'calculation_notes' => $payroll['calculation_notes'],
            'legal_snapshot' => $payroll['legal_snapshot'],
            'status' => 'Borrador',
        ]);

        $explanation = app(PayrollService::class)->explain($record);
        $explanationJson = json_encode($explanation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame('Costo empresa', $explanation['result']['label']);
        $this->assertStringContainsString('Horas aprobadas del período', $explanationJson);
        $this->assertStringContainsString('Tarifa pactada', $explanationJson);
        $this->assertStringContainsString('Persona', $explanationJson);
        $this->assertStringContainsString('UF 1,10 / HH', $explanationJson);
        $this->assertStringContainsString('ASI-PRL-01', $explanationJson);
    }

    public function test_payroll_falls_back_to_person_hourly_rate_when_assignment_rate_is_empty(): void
    {
        $client = Client::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CLI-PRL-02',
            'legal_name' => 'Cliente Payroll Proyecto',
            'client_status_id' => $this->statusId('client', 'active'),
        ]);
        $project = Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $client->id,
            'sales_currency_id' => Currency::query()->where('company_id', $this->company->id)->where('code', 'UF')->value('id'),
            'code' => 'PRY-PRL-02',
            'name' => 'Proyecto Tarifa Proyecto',
            'contracted_hourly_rate' => 0.50,
            'project_status_id' => $this->statusId('project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId('billing', 'pending'),
        ]);

        $person = $this->person([
            'modality' => 'Dependiente por hora',
            'hourly_value' => 0.40,
            'hourly_rate_unit_type' => 'UF',
            'monthly_value' => 0,
        ]);

        $assignmentStatus = RecordStatus::query()->create([
            'company_id' => $this->company->id,
            'domain' => 'assignment',
            'code' => 'active',
            'name' => 'Activo',
            'active' => true,
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_status_id' => $assignmentStatus->id,
            'code' => 'ASI-PRL-02',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => null,
        ]);

        $approvalStatus = ApprovalStatus::query()->create([
            'company_id' => $this->company->id,
            'code' => 'approved',
            'name' => 'Aprobado',
            'active' => true,
        ]);

        $activity = Activity::query()->create([
            'company_id' => $this->company->id,
            'code' => 'ACT-PRL-02',
            'name' => 'Actividad Payroll Proyecto',
            'active' => true,
        ]);

        TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HRS-PRL-02',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-12',
            'activity' => 'Actividad Payroll Proyecto',
            'activity_id' => $activity->id,
            'hours_worked' => 10,
            'hours_approved' => 10,
            'hourly_value' => 0.50,
            'calculated_amount' => 5,
            'approval_status_id' => $approvalStatus->id,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        $payroll = app(PayrollService::class)->calculate($person, '2026-08-01', ['project_id' => $project->id]);
        $expectedPersonRate = app(HourlyRateService::class)->resolvePersonRate($person->fresh(['hourlyRateCurrency']), '2026-08-01');

        $this->assertSame(10.0, $payroll['hours_approved']);
        $this->assertSame($expectedPersonRate, (float) $payroll['hourly_value']);

        $record = PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-PRL-02',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'hours_approved' => $payroll['hours_approved'],
            'hourly_value' => $payroll['hourly_value'],
            'project_value' => $payroll['project_value'],
            'base_salary' => $payroll['base_salary'],
            'gross_amount' => $payroll['gross_amount'],
            'taxable_amount' => $payroll['taxable_amount'],
            'taxable_gross' => $payroll['taxable_gross'],
            'employee_retention' => $payroll['employee_retention'],
            'retention_rate' => $payroll['retention_rate'],
            'employer_cost' => $payroll['employer_cost'],
            'net_pay' => $payroll['net_pay'],
            'calculation_status' => $payroll['calculation_status'],
            'calculation_notes' => $payroll['calculation_notes'],
            'legal_snapshot' => $payroll['legal_snapshot'],
            'status' => 'Borrador',
        ]);

        $explanationJson = json_encode(app(PayrollService::class)->explain($record), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('UF 0,40 / HH', $explanationJson);
        $this->assertStringContainsString('Persona', $explanationJson);
        $this->assertStringNotContainsString('Proyecto · Proyecto Tarifa Proyecto', $explanationJson);
    }

    public function test_payroll_status_without_payment_date_remains_recalculable_and_specific(): void
    {
        $person = $this->person([
            'modality' => 'Honorarios mensual',
            'monthly_value' => 100000,
        ]);

        $record = PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-PRL-02',
            'person_id' => $person->id,
            'period_date' => '2026-08-01',
            'status' => 'Borrador',
        ]);

        app(PayrollService::class)->refreshStatus($record);

        $this->assertSame(PayrollService::STATUS_PENDING_PAYMENT_DATE, $record->refresh()->status);
    }

    public function test_dependent_payroll_calculates_afp_health_afc_and_company_cost(): void
    {
        $person = $this->person([
            'modality' => 'Dependiente mensual',
            'monthly_value' => 1000000,
            'afp_id' => Afp::query()->where('code', 'HABITAT')->value('id'),
            'employment_contract_type_id' => $this->contractType('INDEFINIDO'),
        ]);

        $payroll = app(PayrollService::class)->calculate($person, '2026-08-01');

        $this->assertSame(1000000.0, $payroll['taxable_amount']);
        $this->assertSame(100000.0, $payroll['afp_mandatory']);
        $this->assertSame(12700.0, $payroll['afp_commission']);
        $this->assertSame(70000.0, $payroll['health_legal']);
        $this->assertSame(6000.0, $payroll['afc_employee']);
        $this->assertSame(24000.0, $payroll['afc_employer']);
        $this->assertSame(35000.0, $payroll['employer_pension']);
        $this->assertSame(0.035, $payroll['employer_pension_rate']);
        $this->assertSame(9000.0, $payroll['accident_insurance']);
        $this->assertSame(300.0, $payroll['sanna']);
        $this->assertSame(41666.67, $payroll['vacation_provision']);
        $this->assertSame(1109966.67, $payroll['employer_cost']);
    }

    public function test_fixed_term_contract_uses_afc_employer_only(): void
    {
        $person = $this->person([
            'modality' => 'Dependiente mensual',
            'monthly_value' => 1000000,
            'employment_contract_type_id' => $this->contractType('PLAZO_FIJO'),
        ]);

        $payroll = app(PayrollService::class)->calculate($person, '2026-08-01');

        $this->assertSame(0.0, $payroll['afc_employee']);
        $this->assertSame(30000.0, $payroll['afc_employer']);
    }

    public function test_taxable_caps_use_uf_for_pension_health_and_afc(): void
    {
        $person = $this->person([
            'modality' => 'Dependiente mensual',
            'monthly_value' => 10000000,
            'employment_contract_type_id' => $this->contractType('INDEFINIDO'),
        ]);

        $payroll = app(PayrollService::class)->calculate($person, '2026-08-01');

        $this->assertSame(3676031.1, $payroll['pension_health_base']);
        $this->assertSame(5522215.61, $payroll['afc_base']);
        $this->assertSame(367603.11, $payroll['afp_mandatory']);
        $this->assertSame(33133.29, $payroll['afc_employee']);
    }

    public function test_income_tax_service_uses_monthly_sii_bracket(): void
    {
        $result = app(IncomeTaxService::class)->calculate(2000000, '2026-08-01');

        $this->assertSame(41309.54, $result['iusc_amount']);
    }

    public function test_income_tax_exempt_bracket_returns_zero(): void
    {
        $result = app(IncomeTaxService::class)->calculate(900000, '2026-08-01');

        $this->assertSame(0.0, $result['iusc_amount']);
    }

    public function test_payroll_calculation_returns_historical_snapshot_fields(): void
    {
        $person = $this->person([
            'modality' => 'Dependiente mensual',
            'monthly_value' => 1000000,
            'afp_id' => Afp::query()->where('code', 'HABITAT')->value('id'),
            'employment_contract_type_id' => $this->contractType('INDEFINIDO'),
        ]);

        $payroll = app(PayrollService::class)->calculate($person, '2026-08-01');

        $this->assertSame(40844.79, $payroll['legal_snapshot']['uf_value']);
        $this->assertSame(90.0, $payroll['legal_snapshot']['pension_cap_uf']);
        $this->assertSame(135.2, $payroll['legal_snapshot']['afc_cap_uf']);
        $this->assertSame(0.1, $payroll['legal_snapshot']['afp_mandatory_rate']);
        $this->assertSame(0.0127, $payroll['legal_snapshot']['afp_commission_rate']);
        $this->assertSame(0.07, $payroll['legal_snapshot']['health_legal_rate']);
        $this->assertSame(0.006, $payroll['legal_snapshot']['afc_employee_rate']);
        $this->assertSame(0.024, $payroll['legal_snapshot']['afc_employer_rate']);
        $this->assertSame(0.035, $payroll['legal_snapshot']['employer_pension_rate']);
        $this->assertSame(0.009, $payroll['legal_snapshot']['accident_insurance_rate']);
        $this->assertSame(0.0003, $payroll['legal_snapshot']['sanna_rate']);
        $this->assertSame(0.0, $payroll['legal_snapshot']['sis_rate']);
    }

    public function test_company_specific_additional_accident_rate_only_affects_that_company(): void
    {
        CompanySetting::query()->create([
            'company_id' => $this->company->id,
            'setting_key' => 'additional_accident_rate',
            'setting_value' => '0.004000',
            'setting_type' => 'decimal',
            'is_public' => false,
            'active' => true,
        ]);

        $person = $this->person([
            'modality' => 'Dependiente mensual',
            'monthly_value' => 1000000,
            'employment_contract_type_id' => $this->contractType('INDEFINIDO'),
        ]);

        $payroll = app(PayrollService::class)->calculate($person, '2026-08-01');
        $this->assertSame(13000.0, $payroll['accident_insurance']);

        $otherCompany = Company::query()->create(['code' => 'CMP-OTHER', 'name' => 'Otra', 'status' => 'active']);
        $this->seedLegalParameterForCompany($otherCompany->id, 'AFP_TRABAJADOR', '2026-01-01', null, 0.10);
        $this->seedLegalParameterForCompany($otherCompany->id, 'SALUD_MINIMA', '2026-01-01', null, 0.07);
        $this->seedLegalParameterForCompany($otherCompany->id, 'AFC_TRABAJADOR_INDEFINIDO', '2026-01-01', null, 0.006);
        $this->seedLegalParameterForCompany($otherCompany->id, 'AFC_EMPLEADOR_INDEFINIDO', '2026-01-01', null, 0.024);
        $this->seedLegalParameterForCompany($otherCompany->id, 'LEY_16744_BASICA', '2026-01-01', null, 0.009);
        $this->seedLegalParameterForCompany($otherCompany->id, 'LEY_16744_ADICIONAL', '2026-01-01', null, 0.0);
        $this->seedLegalParameterForCompany($otherCompany->id, 'SANNA_RATE', '2026-01-01', null, 0.0003);
        $this->seedLegalParameterForCompany($otherCompany->id, 'TOPE_IMPONIBLE_UF', '2026-01-01', null, 90);
        $this->seedLegalParameterForCompany($otherCompany->id, 'TOPE_AFC_UF', '2026-01-01', null, 135.2);
        $this->seedLegalParameterForCompany($otherCompany->id, 'COTIZACION_EMPLEADOR', '2026-08-01', '2027-07-31', 0.035);
        $this->seedLegalParameterForCompany($otherCompany->id, 'SIS_RATE', '2026-08-01', '2027-07-31', 0.0);
        UfValue::query()->create(['company_id' => $otherCompany->id, 'value_date' => '2026-08-01', 'value' => 40844.79]);

        $otherPerson = Person::query()->create([
            'company_id' => $otherCompany->id,
            'code' => 'PER-OTHER',
            'name' => 'Otra Persona',
            'modality' => 'Dependiente mensual',
            'monthly_value' => 1000000,
            'employment_contract_type_id' => $this->contractType('INDEFINIDO'),
        ]);

        $otherPayroll = app(PayrollService::class)->calculate($otherPerson, '2026-08-01');
        $this->assertSame(9000.0, $otherPayroll['accident_insurance']);
    }

    public function test_ppm_uses_company_setting_and_does_not_affect_other_company(): void
    {
        CompanySetting::query()->create([
            'company_id' => $this->company->id,
            'setting_key' => 'ppm_active',
            'setting_value' => '1',
            'setting_type' => 'boolean',
            'is_public' => false,
            'active' => true,
        ]);
        CompanySetting::query()->create([
            'company_id' => $this->company->id,
            'setting_key' => 'ppm_rate',
            'setting_value' => '0.002000',
            'setting_type' => 'decimal',
            'is_public' => false,
            'active' => true,
        ]);

        SalesDocument::query()->create([
            'company_id' => $this->company->id,
            'code' => 'ING-PPM',
            'client_id' => $this->clientId(),
            'document_type' => 'Factura',
            'issue_date' => '2026-08-09',
            'net_amount' => 1000000,
            'vat_amount' => 190000,
            'gross_amount' => 1190000,
            'status' => 'Pendiente',
        ]);

        $this->assertSame(2000.0, app(\App\Services\LegalObligationService::class)->ppmForPeriod($this->company->id, '2026-08-01'));
    }

    public function test_hourly_rate_service_uses_historical_exchange_rate_for_foreign_currency(): void
    {
        $currency = Currency::query()->create([
            'company_id' => $this->company->id,
            'code' => 'USD',
            'name' => 'Dólar',
            'symbol' => 'US$',
            'minor_units' => 2,
            'active' => true,
            'is_base_currency' => false,
        ]);

        ExchangeRate::query()->create([
            'company_id' => $this->company->id,
            'currency_id' => $currency->id,
            'rate_date' => '2026-08-01',
            'value_clp' => 924.78,
            'active' => true,
        ]);

        $person = $this->person([
            'modality' => 'Pago por hora',
            'hourly_value' => 45.5,
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $currency->id,
        ]);

        $resolved = app(HourlyRateService::class)->resolvePersonRate($person, '2026-08-01');

        $this->assertSame(42077.0, $resolved);
    }

    public function test_monthly_fixed_salary_always_uses_thirty_day_divisor(): void
    {
        $february = app(PayrollService::class)->calculate($this->person([
            'modality' => 'Dependiente mensual',
            'monthly_value' => 900000,
            'start_date' => '2026-02-16',
        ]), '2026-02-01');

        $march = app(PayrollService::class)->calculate($this->person([
            'modality' => 'Dependiente mensual',
            'monthly_value' => 900000,
            'start_date' => '2026-03-16',
        ]), '2026-03-01');

        $this->assertSame(390000.0, $february['base_salary']);
        $this->assertSame(480000.0, $march['base_salary']);
    }

    public function test_partial_payments_update_invoice_balance_and_status(): void
    {
        $invoice = $this->invoice('ING-001', 3000000, '2026-08-01');
        $service = app(CashMovementService::class);

        $service->create($this->cashData('MOV-001', 'sales_document', 'ING-001', '2026-08-15', 1000000, 0), $this->user);
        $this->assertSame(2000000.0, app(ReceivablesService::class)->balance($invoice->refresh()));
        $this->assertSame('Parcial', $invoice->refresh()->status);

        $service->create($this->cashData('MOV-002', 'sales_document', 'ING-001', '2026-08-30', 2000000, 0), $this->user);
        $this->assertSame(0.0, app(ReceivablesService::class)->balance($invoice->refresh()));
        $this->assertSame('Pagado', $invoice->refresh()->status);
    }

    public function test_overpayment_is_rejected_inside_cash_transaction(): void
    {
        $this->invoice('ING-002', 1000000, '2026-08-01');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede el saldo');

        app(CashMovementService::class)->create($this->cashData('MOV-003', 'sales_document', 'ING-002', '2026-08-15', 1000001, 0), $this->user);
    }

    public function test_cash_movements_are_rejected_for_closed_periods(): void
    {
        $this->invoice('ING-CLSD', 1000000, '2026-08-01');

        MonthlyClosure::query()->create([
            'company_id' => $this->company->id,
            'period_date' => '2026-08-01',
            'opening_balance' => 0,
            'closing_balance' => 0,
            'status' => 'closed',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('esta cerrado');

        app(CashMovementService::class)->create($this->cashData('MOV-CLSD', 'sales_document', 'ING-CLSD', '2026-08-15', 100000, 0), $this->user);
    }

    public function test_cash_movements_create_audit_log(): void
    {
        $this->invoice('ING-AUD', 1000000, '2026-08-01');

        $movement = app(CashMovementService::class)->create($this->cashData('MOV-AUD', 'sales_document', 'ING-AUD', '2026-08-15', 100000, 0), $this->user);

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'action' => 'cash_movement.created',
            'auditable_id' => $movement->id,
        ]);

        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_historical_accounts_receivable_ignore_future_collections(): void
    {
        $this->invoice('ING-003', 3000000, '2026-08-01');
        $service = app(CashMovementService::class);

        $service->create($this->cashData('MOV-004', 'sales_document', 'ING-003', '2026-08-15', 1000000, 0), $this->user);
        $service->create($this->cashData('MOV-005', 'sales_document', 'ING-003', '2026-09-15', 2000000, 0), $this->user);

        $receivables = app(ReceivablesService::class);
        $this->assertSame(3000000.0, $receivables->accountsReceivable($this->company->id, '2026-08-14'));
        $this->assertSame(2000000.0, $receivables->accountsReceivable($this->company->id, '2026-08-31'));
        $this->assertSame(0.0, $receivables->accountsReceivable($this->company->id, '2026-09-30'));
    }

    public function test_historical_accounts_payable_ignore_future_payments(): void
    {
        $this->expense('EGR-001', 1200000, '2026-08-01');
        $service = app(CashMovementService::class);

        $service->create($this->cashData('MOV-006', 'expense_document', 'EGR-001', '2026-08-20', 0, 200000), $this->user);
        $service->create($this->cashData('MOV-007', 'expense_document', 'EGR-001', '2026-09-20', 0, 1000000), $this->user);

        $payables = app(PayablesService::class);
        $this->assertSame(1200000.0, $payables->accountsPayable($this->company->id, '2026-08-19'));
        $this->assertSame(1000000.0, $payables->accountsPayable($this->company->id, '2026-08-31'));
        $this->assertSame(0.0, $payables->accountsPayable($this->company->id, '2026-09-30'));
    }

    public function test_legal_parameter_is_selected_by_vigency(): void
    {
        $service = app(LegalParameterService::class);

        $this->assertSame('0.152500', $service->value($this->company->id, 'RETENCION_HONORARIOS', '2026-07-01'));
        $this->assertSame('0.160000', $service->value($this->company->id, 'RETENCION_HONORARIOS', '2027-07-01'));
    }

    public function test_null_probability_is_one_hundred_percent_in_forecast(): void
    {
        $invoice = $this->invoice('ING-004', 900000, '2026-08-01', null);

        $this->assertSame(900000.0, app(ReceivablesService::class)->forecastAmount($invoice));
    }

    private function seedLegalParameter(string $code, string $name, string $from, ?string $to, float $value): void
    {
        LegalParameter::query()->create([
            'company_id' => $this->company->id,
            'parameter_code' => $code,
            'parameter_name' => $name,
            'valid_from' => $from,
            'valid_to' => $to,
            'value' => $value,
            'unit' => '%',
        ]);
    }

    private function seedLegalParameterForCompany(int $companyId, string $code, string $from, ?string $to, float $value): void
    {
        LegalParameter::query()->create([
            'company_id' => $companyId,
            'parameter_code' => $code,
            'parameter_name' => $code,
            'valid_from' => $from,
            'valid_to' => $to,
            'value' => $value,
            'unit' => '%',
            'active' => true,
        ]);
    }

    private function seedUf(string $date, float $value): void
    {
        UfValue::query()->create([
            'company_id' => $this->company->id,
            'value_date' => $date,
            'value' => $value,
            'source' => 'test',
        ]);
    }

    private function seedAfp(string $code, float $commission): void
    {
        $afp = Afp::query()->create([
            'code' => $code,
            'name' => ucfirst(strtolower($code)),
            'is_active' => true,
        ]);

        AfpRate::query()->create([
            'afp_id' => $afp->id,
            'valid_from' => '2026-01-01',
            'employee_commission_rate' => $commission,
            'employer_commission_rate' => 0,
            'insurance_rate' => 0,
        ]);
    }

    private function contractType(string $code): int
    {
        return ContractType::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'domain' => 'general', 'code' => $code],
            ['name' => str_replace('_', ' ', $code), 'active' => true]
        )->id;
    }

    private function person(array $overrides = []): Person
    {
        return Person::query()->create(array_merge([
            'company_id' => $this->company->id,
            'code' => 'PER-'.uniqid(),
            'name' => 'Persona Test',
            'modality' => 'Dependiente mensual',
            'monthly_value' => 1000000,
            'hourly_value' => 0,
            'status' => 'active',
        ], $overrides));
    }

    private function invoice(string $code, float $grossAmount, string $issueDate, ?float $probability = 1.0): SalesDocument
    {
        return SalesDocument::query()->create([
            'company_id' => $this->company->id,
            'code' => $code,
            'client_id' => $this->clientId(),
            'document_type' => 'Factura',
            'issue_date' => $issueDate,
            'due_date' => $issueDate,
            'net_amount' => $grossAmount,
            'vat_amount' => 0,
            'gross_amount' => $grossAmount,
            'collected_amount' => 0,
            'payment_probability' => $probability,
            'status' => 'Pendiente',
            'is_voided' => false,
        ]);
    }

    private function expense(string $code, float $grossAmount, string $issueDate): ExpenseDocument
    {
        return ExpenseDocument::query()->create([
            'company_id' => $this->company->id,
            'code' => $code,
            'vendor_name' => 'Proveedor Test',
            'issue_date' => $issueDate,
            'due_date' => $issueDate,
            'net_amount' => $grossAmount,
            'vat_amount' => 0,
            'recoverable_vat_amount' => 0,
            'gross_amount' => $grossAmount,
            'paid_amount' => 0,
            'payment_status' => 'Pendiente',
            'tax_deductible' => true,
            'deductible_vat' => false,
        ]);
    }

    private function clientId(): int
    {
        return \App\Models\Client::query()->firstOrCreate([
            'company_id' => $this->company->id,
            'code' => 'CLI-TST',
        ], [
            'legal_name' => 'Cliente Test',
            'payment_term_days' => 30,
            'status' => 'active',
        ])->id;
    }

    private function statusId(string $domain, string $code): int
    {
        return RecordStatus::query()
            ->firstOrCreate(
                [
                    'company_id' => $this->company->id,
                    'domain' => $domain,
                    'code' => $code,
                ],
                [
                    'name' => ucfirst(str_replace('_', ' ', strtolower($code))),
                    'active' => true,
                ]
            )
            ->id;
    }

    private function cashData(string $code, string $sourceType, string $sourceCode, string $date, float $income, float $expense): array
    {
        return [
            'company_id' => $this->company->id,
            'code' => $code,
            'movement_type' => $income > 0 ? 'Ingreso' : 'Egreso',
            'source_document_type' => $sourceType,
            'source_document_code' => $sourceCode,
            'movement_date' => $date,
            'income' => $income,
            'expense' => $expense,
            'cash_account_id' => $this->cashAccount->id,
            'status' => 'posted',
        ];
    }
}
