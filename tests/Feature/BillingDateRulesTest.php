<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ApprovalStatus;
use App\Models\Company;
use App\Models\ContractType;
use App\Models\Currency;
use App\Models\PaymentTerm;
use App\Models\RecordStatus;
use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\SalesDocument;
use App\Models\TimeEntry;
use App\Models\UfValue;
use App\Models\LegalParameter;
use App\Services\ProjectBillingMilestoneService;
use App\Services\SalesPrefacturationService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingDateRulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Client $client;
    private Currency $uf;
    private Project $closedProject;
    private ProjectBillingMilestoneService $milestones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['code' => 'CMP-DATES', 'name' => 'Empresa fechas', 'status' => 'active']);
        $this->client = Client::query()->create(['company_id' => $this->company->id, 'code' => 'CLI-DATES', 'legal_name' => 'Cliente fechas']);
        $this->uf = Currency::query()->create(['company_id' => $this->company->id, 'code' => 'UF', 'name' => 'Unidad de fomento', 'symbol' => 'UF', 'minor_units' => 2, 'active' => true]);
        $closed = ContractType::query()->create(['company_id' => $this->company->id, 'domain' => 'commercial', 'code' => 'PROYECTO_CERRADO', 'name' => 'Proyecto cerrado', 'active' => true]);
        $this->closedProject = Project::query()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'code' => 'PRY-DATES', 'name' => 'Proyecto fechas', 'contract_type_id' => $closed->id, 'sales_currency_id' => $this->uf->id, 'sale_net' => 180, 'start_date' => '2026-01-01', 'end_date' => '2026-09-30']);
        $activeStatus = RecordStatus::query()->create(['company_id' => $this->company->id, 'domain' => 'project', 'code' => 'active', 'name' => 'Activo', 'active' => true]);
        $this->closedProject->update(['project_status_id' => $activeStatus->id]);
        foreach ([['2026-09-01', 40000], ['2026-09-02', 50000], ['2026-09-03', 60000], ['2026-09-06', 70000]] as [$date, $value]) UfValue::query()->create(['company_id' => $this->company->id, 'value_date' => $date, 'value' => $value, 'active' => true]);
        LegalParameter::query()->create(['company_id' => $this->company->id, 'parameter_code' => 'IVA', 'parameter_name' => 'IVA', 'valid_from' => '2026-01-01', 'value' => 0.19, 'unit' => '%', 'active' => true]);
        $this->milestones = app(ProjectBillingMilestoneService::class);
    }

    public function test_active_closed_project_requires_planned_dates_for_milestones(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('fecha prevista');
        app(\App\Services\BillingStrategyService::class)->validateProject($this->closedProject->refresh(), [['sequence' => 1, 'name' => 'Inicio', 'percentage' => 100]]);
    }

    public function test_milestone_planned_dates_must_follow_sequence_chronologically(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('orden cronológico');
        app(\App\Services\BillingStrategyService::class)->validateProject($this->closedProject, [['sequence' => 1, 'name' => 'Uno', 'percentage' => 50, 'planned_invoice_date' => '2026-09-10'], ['sequence' => 2, 'name' => 'Dos', 'percentage' => 50, 'planned_invoice_date' => '2026-09-09']]);
    }

    public function test_milestone_issue_date_cannot_be_future(): void
    {
        $milestone = $this->milestone(1, 100, '2026-09-01');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('futura');
        $this->milestones->issue($milestone, Carbon::today()->addDay()->toDateString(), false);
    }

    public function test_milestone_can_be_issued_before_or_after_planned_date(): void
    {
        $before = $this->milestone(1, 50, '2026-09-10');
        $after = $this->milestone(2, 50, '2026-09-01');
        $this->assertSame('2026-09-06', $this->milestones->issue($before, '2026-09-06', false)->issue_date->toDateString());
        $this->assertSame('2026-09-06', $this->milestones->issue($after, '2026-09-06', false)->issue_date->toDateString());
    }

    public function test_milestone_conversion_uses_issue_date_not_planned_date(): void
    {
        $document = $this->milestones->issue($this->milestone(1, 30, '2026-09-01'), '2026-09-02', false);
        $this->assertSame(50000.0, (float) data_get($document->billing_snapshot, 'conversion.exchange_rate'));
    }

    public function test_milestone_due_date_uses_project_payment_term_days(): void
    {
        $term = PaymentTerm::query()->create(['company_id' => $this->company->id, 'code' => 'NET10', 'name' => '10 días', 'days' => 10, 'active' => true]);
        $this->closedProject->update(['payment_term_id' => $term->id]);
        $document = $this->milestones->issue($this->milestone(1, 100), '2026-09-06', false);
        $this->assertSame('2026-09-16', $document->due_date->toDateString());
        $this->assertSame('2026-09-16', $document->projected_collection_date->toDateString());
    }

    public function test_milestone_due_date_falls_back_to_client_payment_term(): void
    {
        $term = PaymentTerm::query()->create(['company_id' => $this->company->id, 'code' => 'NET7', 'name' => '7 días', 'days' => 7, 'active' => true]);
        $this->client->update(['payment_term_id' => $term->id]);
        $document = $this->milestones->issue($this->milestone(1, 100), '2026-09-06', false);
        $this->assertSame('2026-09-13', $document->due_date->toDateString());
    }

    public function test_milestone_without_payment_term_leaves_due_date_null(): void
    {
        $document = $this->milestones->issue($this->milestone(1, 100), '2026-09-06', false);
        $this->assertNull($document->due_date);
        $this->assertNull($document->projected_collection_date);
    }

    public function test_projected_collection_date_initially_equals_due_date(): void
    {
        $term = PaymentTerm::query()->create(['company_id' => $this->company->id, 'code' => 'NET3', 'name' => '3 días', 'days' => 3, 'active' => true]);
        $this->closedProject->update(['payment_term_id' => $term->id]);
        $document = $this->milestones->issue($this->milestone(1, 100), '2026-09-06', false);
        $this->assertEquals($document->due_date, $document->projected_collection_date);
    }

    public function test_hourly_issue_date_cannot_be_future(): void
    {
        $project = $this->hourlyProject();
        $this->hourlyEntry($project, '2026-09-01', 2);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('futura');
        app(SalesPrefacturationService::class)->calculate($this->company->id, $project->id, '2026-09-01', Carbon::today()->addDay()->toDateString(), false);
    }

    public function test_hourly_billing_excludes_or_rejects_entries_after_issue_date(): void
    {
        $project = $this->hourlyProject();
        $this->hourlyEntry($project, '2026-08-05', 2);
        $this->hourlyEntry($project, '2026-08-07', 3);
        $calculation = app(SalesPrefacturationService::class)->calculate($this->company->id, $project->id, '2026-08-01', '2026-09-06', false);
        $this->assertSame(5.0, $calculation['hours_total']);
    }

    public function test_hourly_monthly_issue_date_cannot_be_before_period_end(): void
    {
        $project = $this->hourlyProject();
        $this->hourlyEntry($project, '2026-08-05', 2);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('término del período');
        app(SalesPrefacturationService::class)->calculate($this->company->id, $project->id, '2026-08-01', '2026-08-15', false);
    }

    public function test_hourly_conversion_uses_issue_date_for_all_lines(): void
    {
        $project = $this->hourlyProject();
        $this->hourlyEntry($project, '2026-08-01', 2);
        $this->hourlyEntry($project, '2026-08-02', 3);
        $calculation = app(SalesPrefacturationService::class)->calculate($this->company->id, $project->id, '2026-08-01', '2026-09-06', false);
        $this->assertSame(5.0, $calculation['hours_total']);
        $this->assertSame(['2026-09-06'], collect($calculation['lines'])->pluck('conversion_date')->unique()->values()->all());
    }

    private function milestone(int $sequence, float $percentage, ?string $planned = null): ProjectBillingMilestone
    {
        return $this->milestones->save($this->closedProject, ['sequence' => $sequence, 'name' => 'Hito '.$sequence, 'percentage' => $percentage, 'planned_invoice_date' => $planned]);
    }

    private function hourlyProject(): Project
    {
        $contract = ContractType::query()->create(['company_id' => $this->company->id, 'domain' => 'commercial', 'code' => 'POR_HORA', 'name' => 'Por Hora', 'active' => true]);
        return Project::query()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'code' => 'PRY-HH-'.$contract->id, 'name' => 'Proyecto HH', 'contract_type_id' => $contract->id, 'sales_currency_id' => $this->uf->id, 'contracted_hourly_rate' => 1.2]);
    }

    private function hourlyEntry(Project $project, string $date, float $hours): void
    {
        $person = \App\Models\Person::query()->firstOrCreate(['company_id' => $this->company->id, 'code' => 'PER-DATES'], ['name' => 'Consultor fechas', 'hourly_value' => 1, 'modality' => 'Honorarios por hora']);
        $approval = ApprovalStatus::query()->firstOrCreate(['company_id' => $this->company->id, 'code' => 'approved'], ['name' => 'Aprobado', 'active' => true]);
        TimeEntry::query()->create(['company_id' => $this->company->id, 'code' => 'HH-'.$date.'-'.$hours, 'person_id' => $person->id, 'client_id' => $this->client->id, 'project_id' => $project->id, 'entry_date' => $date, 'activity' => 'Consultoría', 'hours_worked' => $hours, 'hours_approved' => $hours, 'hourly_value' => 0, 'approval_status_id' => $approval->id, 'approval_status' => 'approved', 'payment_status' => 'pending']);
    }
}
