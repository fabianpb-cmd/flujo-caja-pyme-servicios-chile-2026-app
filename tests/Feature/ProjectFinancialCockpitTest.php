<?php

namespace Tests\Feature;

use App\Models\ApprovalStatus;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Company;
use App\Models\ContractType;
use App\Models\Currency;
use App\Models\ExpenseDocument;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\SalesDocument;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ProjectFinancialCockpitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectFinancialCockpitTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Client $client;
    private Currency $clp;
    private ContractType $closedContract;
    private ContractType $hourlyContract;
    private ApprovalStatus $approved;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['code' => 'CMP-COCKPIT', 'name' => 'Empresa Cockpit', 'status' => 'active']);
        $this->client = Client::query()->create(['company_id' => $this->company->id, 'code' => 'CLI-COCKPIT', 'legal_name' => 'Cliente Cockpit']);
        $this->clp = Currency::query()->create(['company_id' => $this->company->id, 'code' => 'CLP', 'name' => 'Peso chileno', 'symbol' => '$', 'minor_units' => 0, 'active' => true]);
        $this->closedContract = ContractType::query()->create(['company_id' => $this->company->id, 'domain' => 'commercial', 'code' => 'PROYECTO_CERRADO', 'name' => 'Proyecto cerrado', 'active' => true]);
        $this->hourlyContract = ContractType::query()->create(['company_id' => $this->company->id, 'domain' => 'commercial', 'code' => 'POR_HORA', 'name' => 'Por Hora', 'active' => true]);
        $this->approved = ApprovalStatus::query()->create(['company_id' => $this->company->id, 'code' => 'approved', 'name' => 'Aprobado', 'active' => true]);
        $this->admin = User::query()->create(['company_id' => $this->company->id, 'name' => 'Admin Cockpit', 'email' => 'cockpit@test.local', 'password' => 'password', 'role' => 'admin', 'active' => true]);
    }

    public function test_cockpit_composes_financial_sources_without_mixing_net_and_gross_bases(): void
    {
        $project = $this->project('PRY-COCKPIT', $this->closedContract, 2000);
        $first = $this->milestone($project, 1, 'Inicio', 50, '2026-09-10');
        $this->milestone($project, 2, 'Cierre', 50, '2026-10-10');
        $document = $this->document($project, ['project_billing_milestone_id' => $first->id, 'net_amount' => 1000, 'vat_amount' => 190, 'gross_amount' => 1190, 'due_date' => '2026-09-12']);
        $this->document($project, ['code' => 'ING-DRAFT', 'status' => 'Borrador', 'net_amount' => 400, 'gross_amount' => 476]);
        $this->document($project, ['code' => 'ING-VOID', 'is_voided' => true, 'status' => 'Anulado', 'net_amount' => 600, 'gross_amount' => 714]);
        CashMovement::query()->create(['company_id' => $this->company->id, 'code' => 'MOV-COCKPIT', 'movement_type' => 'income', 'source_document_type' => 'sales_document', 'source_document_code' => $document->code, 'project_id' => $project->id, 'movement_date' => '2026-09-11', 'income' => 400, 'status' => 'posted']);
        ExpenseDocument::query()->create(['company_id' => $this->company->id, 'code' => 'EGR-COCKPIT', 'project_id' => $project->id, 'vendor_name' => 'Proveedor', 'issue_date' => '2026-09-10', 'net_amount' => 200, 'gross_amount' => 238, 'deductible_vat' => true]);
        $person = Person::query()->create(['company_id' => $this->company->id, 'code' => 'PER-COCKPIT', 'name' => 'Persona Cockpit', 'modality' => 'Dependiente mensual', 'monthly_hours' => 160, 'monthly_value' => 0, 'hourly_value' => 0, 'status' => 'active']);
        PayrollRecord::query()->create(['company_id' => $this->company->id, 'code' => 'REM-COCKPIT', 'person_id' => $person->id, 'project_id' => $project->id, 'period_date' => '2026-09-01', 'employer_cost' => 500, 'net_pay' => 500, 'status' => 'Confirmado']);
        $approvedEntry = TimeEntry::query()->create(['company_id' => $this->company->id, 'code' => 'HOR-COCKPIT-1', 'person_id' => $person->id, 'client_id' => $this->client->id, 'project_id' => $project->id, 'entry_date' => '2026-09-10', 'activity' => 'Servicio', 'hours_worked' => 10, 'hours_approved' => 10, 'approval_status_id' => $this->approved->id, 'approval_status' => 'approved', 'payment_status' => 'pending']);
        TimeEntry::query()->create(['company_id' => $this->company->id, 'code' => 'HOR-COCKPIT-2', 'person_id' => $person->id, 'client_id' => $this->client->id, 'project_id' => $project->id, 'entry_date' => '2026-09-11', 'activity' => 'Servicio', 'hours_worked' => 4, 'hours_approved' => 0, 'approval_status' => 'pending', 'payment_status' => 'pending']);
        $document->timeEntries()->attach($approvedEntry->id, ['company_id' => $this->company->id, 'hours_approved' => 10, 'hourly_rate_amount' => 100, 'rate_unit_type' => 'CURRENCY', 'subtotal_original' => 1000, 'subtotal_clp' => 1000]);

        $summary = app(ProjectFinancialCockpitService::class)->summarize($project, '2026-09-13');

        $this->assertSame(1000.0, $summary['profitability']['facturado']);
        $this->assertSame(400.0, $summary['profitability']['cobrado']);
        $this->assertSame(790.0, $summary['receivable_balance']);
        $this->assertSame(700.0, $summary['profitability']['total_cost']);
        $this->assertSame(300.0, $summary['profitability']['margin']);
        $this->assertSame(14.0, $summary['profitability']['hours_worked']);
        $this->assertSame(10.0, $summary['profitability']['hours']);
        $this->assertSame(10.0, $summary['profitability']['hours_billed']);
        $this->assertSame(0.0, $summary['profitability']['hours_pending']);
        $this->assertSame('PARCIALMENTE COBRADO', $summary['financial_status']);
        $this->assertSame(2, $summary['billing']['total']);
        $this->assertSame(1, $summary['billing']['invoiced']);
        $this->assertSame('Cierre', $summary['billing']['next']['model']->name);

        $response = $this->actingAs($this->admin)->get(route('operational.show', ['projects', $project->id]));
        $response->assertOk()->assertSee('Resumen financiero')->assertSee('Saldo por cobrar')->assertSee('PARCIALMENTE COBRADO')->assertSee('Próximos hitos / cobros');
    }

    public function test_empty_hourly_project_is_read_only_and_reports_sin_facturar(): void
    {
        $project = $this->project('PRY-HOURLY-EMPTY', $this->hourlyContract, 0, 12500);
        $summary = app(ProjectFinancialCockpitService::class)->summarize($project, '2026-09-13');

        $this->assertSame('SIN FACTURAR', $summary['financial_status']);
        $this->assertNull($summary['billing']);
        $this->assertSame(0.0, $summary['receivable_balance']);
        $this->assertSame([], $summary['events']);

        $this->actingAs($this->admin)->get(route('operational.show', ['projects', $project->id]))
            ->assertOk()->assertSee('HH trabajadas')->assertDontSee('Hitos totales');
    }

    public function test_financial_statuses_are_conservative_and_contractual_currency_is_preserved(): void
    {
        $partial = $this->project('PRY-PARTIAL', $this->closedContract, 2000);
        $this->document($partial, ['net_amount' => 1000, 'gross_amount' => 1190]);
        $this->assertSame('PARCIALMENTE FACTURADO', app(ProjectFinancialCockpitService::class)->summarize($partial, '2026-09-13')['financial_status']);

        $invoiced = $this->project('PRY-INVOICED', $this->closedContract, 1000);
        $this->document($invoiced, ['net_amount' => 1000, 'gross_amount' => 1190]);
        $this->assertSame('FACTURADO', app(ProjectFinancialCockpitService::class)->summarize($invoiced, '2026-09-13')['financial_status']);

        $collected = $this->project('PRY-COLLECTED', $this->closedContract, 1000);
        $document = $this->document($collected, ['net_amount' => 1000, 'gross_amount' => 1190]);
        CashMovement::query()->create(['company_id' => $this->company->id, 'code' => 'MOV-PAID', 'movement_type' => 'income', 'source_document_type' => 'sales_document', 'source_document_code' => $document->code, 'project_id' => $collected->id, 'movement_date' => '2026-09-11', 'income' => 1190, 'status' => 'posted']);
        $this->assertSame('COBRADO', app(ProjectFinancialCockpitService::class)->summarize($collected, '2026-09-13')['financial_status']);

        $uf = Currency::query()->create(['company_id' => $this->company->id, 'code' => 'UF', 'name' => 'Unidad de Fomento', 'symbol' => 'UF', 'minor_units' => 2, 'active' => true]);
        $ufProject = $this->project('PRY-UF', $this->closedContract, 180);
        $ufProject->forceFill(['sales_currency_id' => $uf->id])->save();
        $summary = app(ProjectFinancialCockpitService::class)->summarize($ufProject, '2026-09-13');
        $this->assertSame('UF', $summary['commitment']['sale_net_currency_code']);
        $this->assertSame(180.0, $summary['commitment']['sale_net_contractual']);
    }

    public function test_project_detail_remains_tenant_scoped(): void
    {
        $other = Company::query()->create(['code' => 'CMP-OTHER', 'name' => 'Otra empresa', 'status' => 'active']);
        $otherClient = Client::query()->create(['company_id' => $other->id, 'code' => 'CLI-OTHER', 'legal_name' => 'Cliente otra empresa']);
        $otherProject = Project::query()->create(['company_id' => $other->id, 'client_id' => $otherClient->id, 'code' => 'PRY-OTHER', 'name' => 'Proyecto ajeno']);

        $this->actingAs($this->admin)->get(route('operational.show', ['projects', $otherProject->id]))->assertForbidden();
    }

    private function project(string $code, ContractType $contract, float $saleNet, ?float $hourlyRate = null): Project
    {
        return Project::query()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'sales_currency_id' => $this->clp->id, 'contract_type_id' => $contract->id, 'code' => $code, 'name' => $code, 'sale_net' => $saleNet, 'contracted_hourly_rate' => $hourlyRate]);
    }

    private function milestone(Project $project, int $sequence, string $name, float $percentage, string $plannedDate): ProjectBillingMilestone
    {
        return ProjectBillingMilestone::query()->forceCreate(['company_id' => $this->company->id, 'project_id' => $project->id, 'sequence' => $sequence, 'name' => $name, 'percentage' => $percentage, 'planned_invoice_date' => $plannedDate]);
    }

    private function document(Project $project, array $overrides = []): SalesDocument
    {
        return SalesDocument::query()->forceCreate(array_merge(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'project_id' => $project->id, 'code' => 'ING-'.uniqid(), 'document_type' => 'Factura', 'issue_date' => '2026-09-10', 'due_date' => '2026-09-20', 'net_amount' => 1000, 'vat_rate' => 0.19, 'vat_amount' => 190, 'gross_amount' => 1190, 'status' => 'Pendiente', 'is_voided' => false], $overrides));
    }
}
