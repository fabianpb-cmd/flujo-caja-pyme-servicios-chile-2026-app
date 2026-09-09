<?php

namespace Tests\Feature;

use App\Models\ApprovalStatus;
use App\Models\Client;
use App\Models\DocumentType;
use App\Models\Company;
use App\Models\ContractType;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectBillingMilestone;
use App\Models\PaymentTerm;
use App\Models\CashAccount;
use App\Models\CashMovementType;
use App\Models\Currency as MoneyCurrency;
use App\Models\PaymentMethod;
use App\Models\SalesDocument;
use App\Models\TimeEntry;
use App\Models\UfValue;
use App\Models\LegalParameter;
use App\Models\User;
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
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['code' => 'CMP-MILESTONE', 'name' => 'Empresa Hitos', 'status' => 'active']);
        $this->admin = User::query()->create(['company_id' => $this->company->id, 'name' => 'Admin Hitos', 'email' => 'milestone-'.$this->company->id.'@test.local', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $client = Client::query()->create(['company_id' => $this->company->id, 'code' => 'CLI-MILESTONE', 'legal_name' => 'Cliente Hitos']);
        $this->uf = Currency::query()->create(['company_id' => $this->company->id, 'code' => 'UF', 'name' => 'Unidad de fomento', 'symbol' => 'UF', 'minor_units' => 2, 'active' => true]);
        $contract = ContractType::query()->create(['company_id' => $this->company->id, 'domain' => 'commercial', 'code' => 'PROYECTO_CERRADO', 'name' => 'Proyecto cerrado', 'active' => true]);
        DocumentType::query()->create(['company_id' => $this->company->id, 'domain' => 'sales', 'code' => 'FACTURA', 'name' => 'Factura', 'active' => true]);
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

    public function test_issue_assigns_sales_invoice_document_type(): void
    {
        $type = DocumentType::query()->where('company_id', $this->company->id)->where('domain', 'sales')->where('code', 'FACTURA')->firstOrFail();
        $document = $this->service->issue($this->milestone(1, 100), '2026-09-01', false);

        $this->assertSame($type->id, $document->document_type_id);
    }

    public function test_issue_hito_two_uses_its_contractual_amount_and_preserves_plan_integrity(): void
    {
        $term = PaymentTerm::query()->create(['company_id' => $this->company->id, 'code' => 'NET30-H2', 'name' => '30 días', 'days' => 30, 'active' => true]);
        $this->project->update(['payment_term_id' => $term->id]);
        $first = $this->service->save($this->project, ['sequence' => 1, 'name' => 'Inicio', 'percentage' => 30, 'planned_invoice_date' => '2026-08-05']);
        $second = $this->service->save($this->project, ['sequence' => 2, 'name' => 'Avance', 'percentage' => 40, 'planned_invoice_date' => '2026-08-20']);
        $third = $this->service->save($this->project, ['sequence' => 3, 'name' => 'Cierre', 'percentage' => 30, 'planned_invoice_date' => '2026-10-01']);

        $document = $this->service->issue($second, '2026-09-02', true);
        $fresh = $document->fresh();

        $this->assertSame('Borrador', $fresh->status);
        $this->assertNull($fresh->document_number);
        $this->assertSame($second->id, $fresh->project_billing_milestone_id);
        $this->assertSame('PROJECT_MILESTONE', $fresh->billing_source);
        $this->assertSame($this->project->id, $fresh->project_id);
        $this->assertSame($this->project->client_id, $fresh->client_id);
        $this->assertSame('Factura', $fresh->documentType->name);
        $this->assertSame(72.0, (float) data_get($fresh->billing_snapshot, 'contractual_amount'));
        $this->assertSame(3600000.0, (float) $fresh->net_amount);
        $this->assertSame(0.19, (float) $fresh->vat_rate);
        $this->assertSame(684000.0, (float) $fresh->vat_amount);
        $this->assertSame(4284000.0, (float) $fresh->gross_amount);
        $this->assertSame('2026-09-02', $fresh->issue_date->toDateString());
        $this->assertSame('2026-10-02', $fresh->due_date->toDateString());
        $this->assertSame('2026-10-02', $fresh->projected_collection_date->toDateString());
        $this->assertSame(50000.0, (float) data_get($fresh->billing_snapshot, 'conversion.exchange_rate'));
        $this->assertSame(0, SalesDocument::query()->whereIn('project_billing_milestone_id', [$first->id, $third->id])->count());
        $this->assertSame(0, $fresh->timeEntryLinks()->count());
        $this->assertSame(0, \App\Models\TimeEntry::query()->count());
        $this->assertSame(0, \App\Models\CashMovement::query()->count());
        $this->assertNull($this->project->refresh()->billing_status_id);
        $this->actingAs($this->admin)->get(route('operational.show', ['sales-documents', $fresh->id]))
            ->assertOk()->assertSee('Borrador')->assertSee($fresh->code);
    }

    public function test_milestone_invoice_uses_integer_clp_precision_through_partial_and_full_payment(): void
    {
        $this->uf->update(['minor_units' => 2]);
        UfValue::query()->create(['company_id' => $this->company->id, 'value_date' => '2026-09-04', 'value' => 40883, 'active' => true]);
        $document = $this->service->issue($this->milestone(2, 40), '2026-09-04', true);
        $document->update(['status' => 'Pendiente']);
        $document->refresh();

        $this->assertSame(2943576.0, (float) $document->net_amount);
        $this->assertSame(559279.0, (float) $document->vat_amount);
        $this->assertSame(3502855.0, (float) $document->gross_amount);

        app(\App\Services\CatalogService::class)->seedDefaultsForCompany($this->company->id);
        $clp = MoneyCurrency::query()->where('company_id', $this->company->id)->where('code', 'CLP')->firstOrFail();
        $account = CashAccount::query()->create(['company_id' => $this->company->id, 'name' => 'Caja precisión', 'currency_id' => $clp->id, 'opening_balance' => 0, 'is_active' => true]);
        $type = CashMovementType::query()->where('company_id', $this->company->id)->firstOrFail();
        $method = PaymentMethod::query()->where('company_id', $this->company->id)->firstOrFail();
        $common = ['company_id' => $this->company->id, 'movement_type' => 'Ingreso', 'movement_type_id' => $type->id, 'source_document_type' => 'sales_document', 'source_document_code' => $document->code, 'project_id' => $this->project->id, 'movement_date' => '2026-09-05', 'expense' => 0, 'payment_method_id' => $method->id, 'cash_account_id' => $account->id, 'status' => 'posted'];
        $cash = app(\App\Services\CashMovementService::class);

        $cash->create($common + ['income' => 1000000], $this->admin);
        $document->refresh();
        $this->assertSame(2502855.0, app(\App\Services\ReceivablesService::class)->balance($document));
        $this->assertSame('Parcial', $document->status);

        try {
            $cash->create($common + ['income' => 2502856], $this->admin);
            $this->fail('El sobrepago debía ser rechazado.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('saldo', strtolower($exception->getMessage()));
        }
        $this->assertSame(1, \App\Models\CashMovement::query()->where('source_document_code', $document->code)->count());

        $cash->create($common + ['income' => 2502855], $this->admin);
        $document->refresh();
        $this->assertSame(0.0, app(\App\Services\ReceivablesService::class)->balance($document));
        $this->assertSame('Pagado', $document->status);
    }

    public function test_issue_hito_three_is_independent_and_can_be_confirmed_without_changing_prior_milestones(): void
    {
        $term = PaymentTerm::query()->create(['company_id' => $this->company->id, 'code' => 'NET30-H3', 'name' => '30 días', 'days' => 30, 'active' => true]);
        $this->project->update(['payment_term_id' => $term->id]);
        UfValue::query()->create(['company_id' => $this->company->id, 'value_date' => '2026-09-09', 'value' => 50000, 'active' => true]);

        $first = $this->service->save($this->project, ['sequence' => 1, 'name' => 'Inicio', 'percentage' => 30, 'planned_invoice_date' => '2026-08-05']);
        $second = $this->service->save($this->project, ['sequence' => 2, 'name' => 'Avance', 'percentage' => 40, 'planned_invoice_date' => '2026-08-20']);
        $third = $this->service->save($this->project, ['sequence' => 3, 'name' => 'Cierre', 'percentage' => 30, 'planned_invoice_date' => '2026-10-01']);

        $firstDocument = $this->service->issue($first, '2026-09-01', true);
        $secondDocument = $this->service->issue($second, '2026-09-02', true);
        $priorDocuments = [
            $firstDocument->id => $firstDocument->fresh()->toArray(),
            $secondDocument->id => $secondDocument->fresh()->toArray(),
        ];
        $billingStatusBefore = $this->project->refresh()->billing_status_id;

        $draft = $this->service->issue($third, '2026-09-09', true)->fresh();

        $this->assertSame(54.0, (float) data_get($draft->billing_snapshot, 'contractual_amount'));
        $this->assertSame($third->id, $draft->project_billing_milestone_id);
        $this->assertSame('PROJECT_MILESTONE', $draft->billing_source);
        $this->assertSame('Borrador', $draft->status);
        $this->assertNull($draft->document_number);
        $this->assertSame('2026-09-09', $draft->issue_date->toDateString());
        $this->assertSame('2026-10-09', $draft->due_date->toDateString());
        $this->assertSame('2026-10-09', $draft->projected_collection_date->toDateString());
        $this->assertSame(2700000.0, (float) $draft->net_amount);
        $this->assertSame(513000.0, (float) $draft->vat_amount);
        $this->assertSame(3213000.0, (float) $draft->gross_amount);
        $this->assertSame('2026-10-01', $third->fresh()->planned_invoice_date->toDateString());
        $this->assertSame(50000.0, (float) data_get($draft->billing_snapshot, 'conversion.exchange_rate'));
        $this->assertSame(1, SalesDocument::query()->where('project_billing_milestone_id', $first->id)->count());
        $this->assertSame(1, SalesDocument::query()->where('project_billing_milestone_id', $second->id)->count());
        $this->assertSame(0, $draft->timeEntryLinks()->count());
        $this->assertSame(0, \App\Models\CashMovement::query()->count());
        $this->assertSame($billingStatusBefore, $this->project->refresh()->billing_status_id);

        $confirmed = app(\App\Services\SalesDocumentService::class)->confirm($draft, $this->admin, 'QA-H3');

        $this->assertSame('Pendiente', $confirmed->status);
        $this->assertSame('QA-H3', $confirmed->document_number);
        $this->assertSame(2700000.0, (float) $confirmed->net_amount);
        $this->assertSame(513000.0, (float) $confirmed->vat_amount);
        $this->assertSame(3213000.0, (float) $confirmed->gross_amount);
        $this->assertSame('2026-09-09', $confirmed->issue_date->toDateString());
        $this->assertSame('2026-10-09', $confirmed->due_date->toDateString());
        $this->assertSame('2026-10-09', $confirmed->projected_collection_date->toDateString());
        $this->assertSame($third->id, $confirmed->project_billing_milestone_id);
        $this->assertSame('PROJECT_MILESTONE', $confirmed->billing_source);
        $this->assertSame($priorDocuments[$firstDocument->id], $firstDocument->fresh()->toArray());
        $this->assertSame($priorDocuments[$secondDocument->id], $secondDocument->fresh()->toArray());
        $this->assertSame(0, $confirmed->timeEntryLinks()->count());
        $this->assertSame(0, \App\Models\CashMovement::query()->count());
        $this->assertSame($billingStatusBefore, $this->project->refresh()->billing_status_id);
    }

    public function test_issue_fails_without_active_sales_invoice_document_type(): void
    {
        DocumentType::query()->where('company_id', $this->company->id)->where('domain', 'sales')->where('code', 'FACTURA')->delete();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FACTURA activo');
        try {
            $this->service->issue($this->milestone(1, 100), '2026-09-01', false);
        } finally {
            $this->assertSame(0, SalesDocument::query()->count());
        }
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
