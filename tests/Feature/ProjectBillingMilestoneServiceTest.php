<?php

namespace Tests\Feature;

use App\Models\ApprovalStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\ContractType;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectBillingMilestone;
use App\Models\SalesDocument;
use App\Models\TimeEntry;
use App\Models\UfValue;
use App\Models\LegalParameter;
use App\Services\ProjectBillingMilestoneService;
use App\Services\SalesPrefacturationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectBillingMilestoneServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Project $project;
    private Currency $uf;
    private ProjectBillingMilestoneService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['code' => 'CMP-MILESTONE', 'name' => 'Empresa Hitos', 'status' => 'active']);
        $client = Client::query()->create(['company_id' => $this->company->id, 'code' => 'CLI-MILESTONE', 'legal_name' => 'Cliente Hitos']);
        $this->uf = Currency::query()->create(['company_id' => $this->company->id, 'code' => 'UF', 'name' => 'Unidad de fomento', 'symbol' => 'UF', 'minor_units' => 2, 'active' => true]);
        $contract = ContractType::query()->create(['company_id' => $this->company->id, 'domain' => 'commercial', 'code' => 'PROYECTO_CERRADO', 'name' => 'Proyecto cerrado', 'active' => true]);
        $this->project = Project::query()->create(['company_id' => $this->company->id, 'client_id' => $client->id, 'code' => 'PRY-MILESTONE', 'name' => 'Proyecto cerrado QA', 'contract_type_id' => $contract->id, 'sales_currency_id' => $this->uf->id, 'sale_net' => 180]);
        UfValue::query()->create(['company_id' => $this->company->id, 'value_date' => '2026-09-01', 'value' => 40000, 'active' => true]);
        UfValue::query()->create(['company_id' => $this->company->id, 'value_date' => '2026-09-02', 'value' => 50000, 'active' => true]);
        UfValue::query()->create(['company_id' => $this->company->id, 'value_date' => '2026-09-03', 'value' => 60000, 'active' => true]);
        LegalParameter::query()->create(['company_id' => $this->company->id, 'parameter_code' => 'IVA', 'parameter_name' => 'IVA', 'valid_from' => '2026-01-01', 'value' => 0.19, 'unit' => '%', 'active' => true]);
        $this->service = app(ProjectBillingMilestoneService::class);
    }

    public function test_plan_percentages_and_duplicate_sequence_are_controlled(): void
    {
        $this->service->save($this->project, ['sequence' => 1, 'name' => 'Inicio', 'percentage' => 30]);
        $this->service->save($this->project, ['sequence' => 2, 'name' => 'Avance', 'percentage' => 40]);
        $this->service->save($this->project, ['sequence' => 3, 'name' => 'Cierre', 'percentage' => 30]);
        $plan = $this->service->plan($this->project);

        $this->assertSame(100.0, $plan['scheduled_percentage']);
        $this->assertSame(54.0, (float) $plan['milestones'][0]['amount']);
        $this->assertSame(72.0, (float) $plan['milestones'][1]['amount']);
        $this->assertSame(54.0, (float) $plan['milestones'][2]['amount']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('secuencia del hito debe ser única');
        $this->service->save($this->project, ['sequence' => 1, 'name' => 'Duplicado', 'percentage' => 1]);
    }

    public function test_issue_uses_contractual_amount_without_hours_and_historical_coverage(): void
    {
        $first = $this->milestone(1, 30);
        $second = $this->milestone(2, 40);
        $third = $this->milestone(3, 30);
        $this->addApprovedCost(100, 1.5);

        $thirdDocument = $this->service->issue($third, '2026-09-01', false);
        $this->assertSame(2160000.0, (float) $thirdDocument->net_amount);
        $this->assertSame(0, $thirdDocument->timeEntryLinks()->count());
        $this->assertSame(40000.0, (float) data_get($thirdDocument->billing_snapshot, 'conversion.exchange_rate'));

        $futureDocument = $this->service->issue($first, '2026-09-03', false);
        $this->assertSame(3240000.0, (float) $futureDocument->net_amount);

        $coverage = $this->service->coverage($second, now()->setDate(2026, 9, 2));
        $this->assertSame(2160000.0 + 3600000.0, (float) $coverage['contractual_clp']);
        $this->assertNotNull($coverage['warning']);

        $secondDocument = $this->service->issue($second, '2026-09-02', false);
        $coverageAfter = $this->service->coverage($second, now()->setDate(2026, 9, 2));
        $this->assertSame(2160000.0 + 3600000.0, (float) $coverageAfter['contractual_clp']);
        $this->assertSame(1, SalesDocument::query()->where('project_billing_milestone_id', $second->id)->count());
    }

    public function test_plan_rejects_percentage_above_one_hundred(): void
    {
        $this->milestone(1, 60);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no puede superar 100%');
        $this->milestone(2, 40.01);
    }

    public function test_active_invoice_blocks_reissue_but_anulled_invoice_allows_it(): void
    {
        $milestone = $this->milestone(1, 30);
        $this->service->issue($milestone, '2026-09-01', false);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('factura activa');
        $this->service->issue($milestone->refresh(), '2026-09-01', false);
    }

    public function test_anulled_invoice_can_be_reissued(): void
    {
        $milestone = $this->milestone(1, 30);
        $document = $this->service->issue($milestone, '2026-09-01', false);
        $document->update(['status' => 'Anulado', 'is_voided' => true]);

        $replacement = $this->service->issue($milestone->refresh(), '2026-09-01', false);
        $this->assertNotSame($document->id, $replacement->id);
    }

    public function test_hh_prefacturation_is_rejected_for_closed_project(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Plan de facturación / hitos');
        app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-09-01', '2026-09-01');
    }

    public function test_non_closed_project_does_not_expose_a_billing_plan(): void
    {
        $this->project->update(['contract_type_id' => null]);
        $this->assertFalse($this->service->isClosedContract($this->project->refresh()));
    }

    private function milestone(int $sequence, float $percentage): ProjectBillingMilestone
    {
        return $this->service->save($this->project, ['sequence' => $sequence, 'name' => 'Hito '.$sequence, 'percentage' => $percentage, 'planned_invoice_date' => null, 'notes' => null]);
    }

    private function addApprovedCost(float $hours, float $rate): void
    {
        $person = \App\Models\Person::query()->create(['company_id' => $this->company->id, 'code' => 'PER-MILESTONE', 'name' => 'Consultor Hitos', 'hourly_value' => $rate, 'hourly_rate_unit_type' => 'UF', 'hourly_rate_currency_id' => $this->uf->id, 'modality' => 'Honorarios por hora', 'status' => 'active']);
        $assignment = ProjectAssignment::query()->create(['company_id' => $this->company->id, 'person_id' => $person->id, 'client_id' => $this->project->client_id, 'project_id' => $this->project->id, 'code' => 'ASI-MILESTONE', 'hourly_value' => $rate, 'hourly_rate_unit_type' => 'UF', 'hourly_rate_currency_id' => $this->uf->id, 'start_date' => '2026-01-01', 'status' => 'active']);
        $approved = ApprovalStatus::query()->create(['company_id' => $this->company->id, 'code' => 'approved', 'name' => 'Aprobado', 'active' => true]);
        TimeEntry::query()->create(['company_id' => $this->company->id, 'code' => 'HOR-MILESTONE', 'person_id' => $person->id, 'client_id' => $this->project->client_id, 'project_id' => $this->project->id, 'assignment_id' => $assignment->id, 'entry_date' => '2026-09-01', 'activity' => 'Consultoría', 'hours_worked' => $hours, 'hours_approved' => $hours, 'hourly_value' => $rate, 'approval_status_id' => $approved->id, 'approval_status' => 'approved', 'payment_status' => 'pending']);
    }
}
