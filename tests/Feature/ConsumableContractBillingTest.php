<?php

namespace Tests\Feature;

use App\Models\ApprovalStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\ContractType;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\LegalParameter;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\TimeEntry;
use App\Services\BillingStrategyService;
use App\Services\ProjectCommitmentService;
use App\Services\ProjectHoursCapacityService;
use App\Services\SalesPrefacturationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumableContractBillingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Client $client;
    private Currency $uf;
    private int $approvedId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['code' => 'CMP-CAP', 'name' => 'Capacidad', 'status' => 'active']);
        $this->client = Client::query()->create(['company_id' => $this->company->id, 'code' => 'CLI-CAP', 'legal_name' => 'Cliente Capacidad']);
        $this->uf = Currency::query()->create(['company_id' => $this->company->id, 'code' => 'UF', 'name' => 'UF', 'symbol' => 'UF', 'minor_units' => 2, 'active' => true]);
        DocumentType::query()->create(['company_id' => $this->company->id, 'domain' => 'sales', 'code' => 'FACTURA', 'name' => 'Factura', 'active' => true]);
        $this->approvedId = ApprovalStatus::query()->create(['company_id' => $this->company->id, 'code' => 'approved', 'name' => 'Aprobado', 'active' => true])->id;
        LegalParameter::query()->create(['company_id' => $this->company->id, 'parameter_code' => 'IVA', 'parameter_name' => 'IVA', 'valid_from' => '2026-01-01', 'value' => .19, 'unit' => '%', 'active' => true]);
        \App\Models\UfValue::query()->create(['company_id' => $this->company->id, 'value_date' => '2026-08-31', 'value' => 40000, 'active' => true]);
    }

    public function test_strategy_mapping_and_configuration_validation_cover_consumable_contracts(): void
    {
        $strategies = app(BillingStrategyService::class);
        foreach (['POR_HORA' => BillingStrategyService::HOURLY, 'PROYECTO_CERRADO' => BillingStrategyService::CLOSED_PROJECT, 'BOLSA_HORAS' => BillingStrategyService::HOURS_BANK, 'MENSUAL_RECURRENTE' => BillingStrategyService::MONTHLY_RECURRING] as $code => $expected) {
            $project = $this->project($code, 100, 1);
            $this->assertSame($expected, $strategies->forProject($project));
        }
        $unknown = $this->project('OTRO', 100, 1);
        $this->assertSame(BillingStrategyService::UNSUPPORTED, $strategies->forProject($unknown));

        $invalid = $this->project('BOLSA_HORAS', 0, 1);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Bolsa de horas requiere');
        $strategies->validateProject($invalid);
    }

    public function test_hours_bank_capacity_consumes_all_approved_hours_and_blocks_overage(): void
    {
        $project = $this->project('BOLSA_HORAS', 100, 1);
        $this->entry($project, 60, '2026-08-10');
        $summary = app(ProjectHoursCapacityService::class)->summarize($project);
        $this->assertSame(100.0, $summary['capacity_hours']);
        $this->assertSame(60.0, $summary['consumed_hours']);
        $this->assertSame(40.0, $summary['remaining_hours']);
        $this->entry($project, 41, '2026-08-11');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('bolsa total');
        app(ProjectHoursCapacityService::class)->assertWithinCapacity($project);
    }

    public function test_consumable_contracts_require_values_and_reject_milestones(): void
    {
        $strategies = app(BillingStrategyService::class);
        foreach (['BOLSA_HORAS', 'MENSUAL_RECURRENTE'] as $code) {
            foreach ([[0, 1], [100, 0]] as [$saleNet, $rate]) {
                try {
                    $strategies->validateProject($this->project($code, $saleNet, $rate));
                    $this->fail("{$code} accepted incomplete configuration.");
                } catch (DomainException) {
                    $this->addToAssertionCount(1);
                }
            }
            try {
                $strategies->validateProject($this->project($code, 100, 1), [['sequence' => 1, 'name' => 'No permitido', 'percentage' => 100]]);
                $this->fail("{$code} accepted billing milestones.");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_monthly_capacity_resets_without_carryover_and_blocks_each_month_independently(): void
    {
        $project = $this->project('MENSUAL_RECURRENTE', 100, 1);
        $this->entry($project, 60, '2026-09-10');
        $this->entry($project, 101, '2026-10-10');
        $service = app(ProjectHoursCapacityService::class);
        $september = $service->summarize($project, '2026-09-01');
        $october = $service->summarize($project, '2026-10-01');
        $this->assertSame(40.0, $september['remaining_hours']);
        $this->assertSame(-1.0, $october['remaining_hours']);
        $this->assertSame(100.0, $october['capacity_hours']);
        $this->assertSame('MONTH', $october['capacity_scope']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('bolsa mensual');
        $service->assertWithinCapacity($project, '2026-10-01');
    }

    public function test_consumable_strategies_generate_hourly_drafts_and_snapshot_capacity(): void
    {
        foreach (['BOLSA_HORAS', 'MENSUAL_RECURRENTE'] as $index => $code) {
            $project = $this->project($code, 100, 1, 'PRY-DRAFT-'.$index);
            $this->entry($project, 10, '2026-08-10');
            $draft = app(SalesPrefacturationService::class)->generateDraft($this->company->id, ['project_id' => $project->id, 'period' => '2026-08-01', 'issue_date' => '2026-08-31', 'taxable' => true]);
            $this->assertSame('TIME_ENTRIES', $draft->billing_source);
            $this->assertSame('Borrador', $draft->status);
            $this->assertSame($code === 'BOLSA_HORAS' ? 'HOURS_BANK' : 'MONTHLY_RECURRING', data_get($draft->billing_snapshot, 'billing_strategy'));
            $this->assertSame($code === 'BOLSA_HORAS' ? 'PROJECT' : 'MONTH', data_get($draft->billing_snapshot, 'capacity_scope'));
            $this->assertSame(100.0, (float) data_get($draft->billing_snapshot, 'capacity_hours'));
            $this->assertSame(10.0, (float) data_get($draft->billing_snapshot, 'consumed_hours'));
        }
    }

    public function test_capacity_cannot_be_reduced_below_existing_approved_consumption(): void
    {
        $project = $this->project('BOLSA_HORAS', 100, 1);
        $this->entry($project, 80, '2026-08-10');
        $project->update(['sale_net' => 70]);

        $this->expectException(DomainException::class);
        app(ProjectHoursCapacityService::class)->assertExistingConsumptionWithinCapacity($project->fresh());
    }

    public function test_monthly_capacity_cannot_be_reduced_below_existing_consumption(): void
    {
        $project = $this->project('MENSUAL_RECURRENTE', 100, 1);
        $this->entry($project, 80, '2026-08-10');
        $project->update(['sale_net' => 70]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('bolsa mensual');
        app(ProjectHoursCapacityService::class)->assertExistingConsumptionWithinCapacity($project->fresh());
    }

    public function test_monthly_recurring_sale_net_is_projected_by_full_calendar_months_only(): void
    {
        \App\Models\UfValue::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'value_date' => now()->toDateString()],
            ['value' => 40000, 'active' => true],
        );
        $project = $this->project('MENSUAL_RECURRENTE', 100, 1);
        $project->update(['start_date' => '2026-08-15', 'end_date' => '2026-09-10']);

        $summary = app(ProjectCommitmentService::class)->summarizeProject($project->fresh());

        $this->assertSame(200.0, $summary['sale_net_contractual']);

        $project->update(['start_date' => null, 'end_date' => null]);
        $withoutDates = app(ProjectCommitmentService::class)->summarizeProject($project->fresh());
        $this->assertNull($withoutDates['sale_net_contractual']);
        $this->assertStringContainsString('sin fechas de inicio y término', implode(' ', $withoutDates['warnings']));
    }

    private function project(string $contractCode, float $saleNet, float $rate, ?string $code = null): Project
    {
        $contract = ContractType::query()->firstOrCreate(['company_id' => $this->company->id, 'domain' => 'commercial', 'code' => $contractCode], ['name' => str_replace('_', ' ', $contractCode), 'active' => true]);
        return Project::query()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'sales_currency_id' => $this->uf->id, 'contract_type_id' => $contract->id, 'code' => $code ?: 'PRY-'.uniqid(), 'name' => 'Proyecto capacidad', 'sale_net' => $saleNet, 'contracted_hourly_rate' => $rate]);
    }

    private function entry(Project $project, float $hours, string $date): void
    {
        $person = Person::query()->firstOrCreate(['company_id' => $this->company->id, 'code' => 'PER-CAP'], ['name' => 'Persona capacidad', 'modality' => 'Honorarios mensual', 'status' => 'active']);
        $assignment = ProjectAssignment::query()->firstOrCreate(['company_id' => $this->company->id, 'project_id' => $project->id, 'person_id' => $person->id], ['client_id' => $this->client->id, 'code' => 'ASI-'.uniqid(), 'status' => 'active']);
        TimeEntry::query()->create(['company_id' => $this->company->id, 'code' => 'HOR-'.uniqid(), 'person_id' => $person->id, 'client_id' => $this->client->id, 'project_id' => $project->id, 'assignment_id' => $assignment->id, 'entry_date' => $date, 'activity' => 'QA', 'hours_worked' => $hours, 'hours_approved' => $hours, 'approval_status_id' => $this->approvedId, 'approval_status' => 'approved', 'payment_status' => 'pending']);
    }
}
