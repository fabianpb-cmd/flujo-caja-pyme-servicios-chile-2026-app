<?php

namespace Tests\Feature;

use App\Models\ApprovalStatus;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\ExpenseDocument;
use App\Models\ExchangeRate;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfitabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Client $client;
    private Project $project;
    private Project $projectTwo;
    private ApprovalStatus $approvedStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'code' => 'CMP-PRF',
            'name' => 'Empresa Rentabilidad',
            'status' => 'active',
        ]);

        CompanySetting::query()->create([
            'company_id' => $this->company->id,
            'setting_key' => 'margin_minimum',
            'setting_value' => '0.15',
        ]);

        $this->client = Client::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CLI-PRF',
            'legal_name' => 'Cliente Rentable',
        ]);

        $this->project = Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'code' => 'PRY-PRF-1',
            'name' => 'Proyecto Uno',
            'sale_net' => 1000000,
            'sale_total' => 1190000,
        ]);

        $this->projectTwo = Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'code' => 'PRY-PRF-2',
            'name' => 'Proyecto Dos',
            'sale_net' => 500000,
            'sale_total' => 595000,
        ]);

        $this->approvedStatus = ApprovalStatus::query()->create([
            'company_id' => $this->company->id,
            'code' => 'approved',
            'name' => 'Aprobado',
            'active' => true,
        ]);
    }

    public function test_profitability_uses_net_sales_without_vat_and_direct_costs(): void
    {
        $person = $this->person();
        $this->payroll($person, $this->project, '2026-08-01', 800000, 100000);
        $this->approvedTime($person, $this->project, '2026-08-05', 40);

        SalesDocument::query()->create([
            'company_id' => $this->company->id,
            'code' => 'ING-PRF-1',
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'document_type' => 'Factura',
            'issue_date' => '2026-08-09',
            'net_amount' => 1000000,
            'vat_amount' => 190000,
            'gross_amount' => 1190000,
            'status' => 'Confirmado',
            'is_voided' => false,
        ]);

        ExpenseDocument::query()->create([
            'company_id' => $this->company->id,
            'code' => 'EGR-PRF-1',
            'project_id' => $this->project->id,
            'vendor_name' => 'Proveedor',
            'issue_date' => '2026-08-08',
            'net_amount' => 100000,
            'vat_amount' => 19000,
            'gross_amount' => 119000,
            'deductible_vat' => true,
            'payment_status' => 'Pendiente',
        ]);

        $row = collect(app(ProfitabilityService::class)->byProject($this->company->id, ['period' => '2026-08-01']))
            ->firstWhere('project_id', $this->project->id);

        $this->assertSame(1000000.0, $row['facturado']);
        $this->assertSame(800000.0, $row['cost_personal']);
        $this->assertSame(100000.0, $row['vacation_provision']);
        $this->assertSame(100000.0, $row['other_costs']);
        $this->assertSame(1000000.0, $row['sale']);
        $this->assertSame(100000.0, $row['margin']);
    }

    public function test_cash_movements_do_not_change_profitability_sales_basis(): void
    {
        $person = $this->person();
        $this->payroll($person, $this->project, '2026-08-01', 200000, 0);
        $this->approvedTime($person, $this->project, '2026-08-07', 10);

        SalesDocument::query()->create([
            'company_id' => $this->company->id,
            'code' => 'ING-PRF-2',
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'document_type' => 'Factura',
            'issue_date' => '2026-08-03',
            'net_amount' => 300000,
            'vat_amount' => 57000,
            'gross_amount' => 357000,
            'status' => 'Confirmado',
            'is_voided' => false,
        ]);

        CashMovement::query()->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-PRF-1',
            'movement_type' => 'income',
            'source_document_type' => 'sales_document',
            'source_document_code' => 'ING-PRF-2',
            'project_id' => $this->project->id,
            'movement_date' => '2026-08-10',
            'income' => 999999,
            'status' => 'posted',
        ]);

        $row = collect(app(ProfitabilityService::class)->byProject($this->company->id, ['period' => '2026-08-01']))
            ->firstWhere('project_id', $this->project->id);

        $this->assertSame(300000.0, $row['facturado']);
        $this->assertSame(999999.0, $row['cobrado']);
        $this->assertSame(100000.0, $row['margin']);
    }

    public function test_pending_billing_hours_are_calculated_without_double_billing(): void
    {
        $person = $this->person();
        $this->payroll($person, $this->project, '2026-08-01', 600000, 0);
        $timeA = $this->approvedTime($person, $this->project, '2026-08-05', 10);
        $timeB = $this->approvedTime($person, $this->project, '2026-08-06', 6);

        $sales = SalesDocument::query()->create([
            'company_id' => $this->company->id,
            'code' => 'ING-PRF-3',
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'document_type' => 'Factura',
            'issue_date' => '2026-08-09',
            'net_amount' => 200000,
            'vat_amount' => 38000,
            'gross_amount' => 238000,
            'status' => 'Confirmado',
            'is_voided' => false,
        ]);

        $sales->timeEntries()->attach($timeA->id, [
            'company_id' => $this->company->id,
            'hours_approved' => 10,
            'hourly_rate_amount' => 1.5,
            'rate_unit_type' => 'UF',
            'subtotal_original' => 15,
            'subtotal_clp' => 200000,
        ]);

        $row = collect(app(ProfitabilityService::class)->byProject($this->company->id, ['period' => '2026-08-01']))
            ->firstWhere('project_id', $this->project->id);

        $this->assertSame(16.0, $row['hours']);
        $this->assertSame(10.0, $row['hours_billed']);
        $this->assertSame(6.0, $row['hours_pending']);
        $this->assertContains('HH aprobadas no facturadas', $row['alerts']);
    }

    public function test_period_filter_and_multiple_projects_keep_costs_isolated(): void
    {
        $person = $this->person();
        $this->payroll($person, $this->project, '2026-08-01', 800000, 0);
        $this->payroll($person, $this->projectTwo, '2026-09-01', 900000, 0);
        $this->approvedTime($person, $this->project, '2026-08-05', 20);
        $this->approvedTime($person, $this->projectTwo, '2026-09-05', 30);

        $augustRows = collect(app(ProfitabilityService::class)->byProject($this->company->id, ['period' => '2026-08-01']));
        $septemberRows = collect(app(ProfitabilityService::class)->byProject($this->company->id, ['period' => '2026-09-01']));

        $this->assertSame(20.0, $augustRows->firstWhere('project_id', $this->project->id)['hours']);
        $this->assertSame(0.0, $augustRows->firstWhere('project_id', $this->projectTwo->id)['hours']);
        $this->assertSame(30.0, $septemberRows->firstWhere('project_id', $this->projectTwo->id)['hours']);
    }

    public function test_profitability_page_renders_new_operational_columns(): void
    {
        $user = User::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Admin Rentabilidad',
            'email' => 'rentabilidad@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('management.profitability', ['period' => '2026-08']));

        $response->assertOk();
        $response->assertSee('Venta generada');
        $response->assertSee('HH pendientes');
        $response->assertSee('Costo laboral');
    }

    public function test_project_sales_currency_is_converted_to_clp_for_profitability(): void
    {
        $usd = $this->currency('USD', 'Dólar de prueba');
        ExchangeRate::query()->create([
            'company_id' => $this->company->id,
            'currency_id' => $usd->id,
            'rate_date' => '2026-08-01',
            'value_clp' => 924.78,
            'active' => true,
        ]);

        $project = Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'code' => 'PRY-PRF-USD',
            'name' => 'Proyecto USD',
            'sales_currency_id' => $usd->id,
            'sale_net' => 1000,
            'sale_total' => 1190,
        ]);

        $row = collect(app(ProfitabilityService::class)->byProject($this->company->id, ['period' => '2026-08-01']))
            ->firstWhere('project_id', $project->id);

        $this->assertSame(924780.0, $row['sale']);
    }

    private function person(): Person
    {
        return Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'PER-'.uniqid(),
            'name' => 'Persona Rentabilidad',
            'modality' => 'Dependiente mensual',
            'monthly_hours' => 160,
            'status' => 'active',
        ]);
    }

    private function payroll(Person $person, Project $project, string $period, float $companyCost, float $vacationProvision): PayrollRecord
    {
        return PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-'.uniqid(),
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => $period,
            'employer_cost' => $companyCost,
            'vacation_provision' => $vacationProvision,
            'net_pay' => $companyCost,
            'status' => 'Confirmado',
        ]);
    }

    private function approvedTime(Person $person, Project $project, string $entryDate, float $hours): TimeEntry
    {
        return TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HOR-'.uniqid(),
            'person_id' => $person->id,
            'client_id' => $this->client->id,
            'project_id' => $project->id,
            'entry_date' => $entryDate,
            'activity' => 'Servicio',
            'hours_worked' => $hours,
            'hours_approved' => $hours,
            'hourly_value' => 0,
            'approval_status_id' => $this->approvedStatus->id,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);
    }

    private function currency(string $code, string $name): Currency
    {
        return Currency::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'code' => $code],
            [
                'name' => $name,
                'symbol' => match ($code) {
                    'CLP' => '$',
                    'USD' => 'US$',
                    'EUR' => '€',
                    'UF' => 'UF',
                    default => $code,
                },
                'minor_units' => $code === 'CLP' ? 0 : 2,
                'active' => true,
                'sort_order' => 999,
            ]
        );
    }
}
