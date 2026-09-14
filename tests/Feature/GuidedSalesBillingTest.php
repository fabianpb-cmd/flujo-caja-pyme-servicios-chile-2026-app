<?php

namespace Tests\Feature;

use App\Models\ApprovalStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\ContractType;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\LegalParameter;
use App\Models\PaymentTerm;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectBillingMilestone;
use App\Models\RecordStatus;
use App\Models\SalesDocument;
use App\Models\SalesDocumentTimeEntry;
use App\Models\TimeEntry;
use App\Models\UfValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuidedSalesBillingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private Client $client;
    private Currency $uf;
    private DocumentType $invoiceType;
    private RecordStatus $activeProjectStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['code' => 'CMP-GUIDED', 'name' => 'Empresa guiada', 'status' => 'active']);
        $this->admin = User::query()->create(['company_id' => $this->company->id, 'name' => 'Admin', 'email' => 'guided@test.local', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->client = Client::query()->create(['company_id' => $this->company->id, 'code' => 'CLI-GUIDED', 'legal_name' => 'Cliente guiado']);
        $this->uf = Currency::query()->create(['company_id' => $this->company->id, 'code' => 'UF', 'name' => 'Unidad de fomento', 'symbol' => 'UF', 'minor_units' => 2, 'active' => true]);
        $this->invoiceType = DocumentType::query()->create(['company_id' => $this->company->id, 'domain' => 'sales', 'code' => 'FACTURA', 'name' => 'Factura', 'active' => true]);
        $this->activeProjectStatus = RecordStatus::query()->create(['company_id' => $this->company->id, 'domain' => 'project', 'code' => 'active', 'name' => 'Activo', 'active' => true]);
        LegalParameter::query()->create(['company_id' => $this->company->id, 'parameter_code' => 'IVA', 'parameter_name' => 'IVA', 'valid_from' => '2026-01-01', 'value' => 0.19, 'unit' => '%', 'active' => true]);
        UfValue::query()->create(['company_id' => $this->company->id, 'value_date' => '2026-08-31', 'value' => 40000, 'active' => true]);
    }

    public function test_closed_project_uses_pending_milestones_and_rejects_manual_amounts(): void
    {
        $project = $this->project('PROYECTO_CERRADO', 'Proyecto cerrado', ['sales_currency_id' => $this->uf->id, 'sale_net' => 180]);
        $term = PaymentTerm::query()->create(['company_id' => $this->company->id, 'code' => 'NET30', 'name' => '30 días', 'days' => 30, 'active' => true]);
        $project->update(['payment_term_id' => $term->id]);
        $pending = ProjectBillingMilestone::query()->forceCreate(['company_id' => $this->company->id, 'project_id' => $project->id, 'sequence' => 2, 'name' => 'Entrega', 'percentage' => 40, 'planned_invoice_date' => '2026-09-14']);
        $invoiced = ProjectBillingMilestone::query()->forceCreate(['company_id' => $this->company->id, 'project_id' => $project->id, 'sequence' => 1, 'name' => 'Inicio', 'percentage' => 30]);
        SalesDocument::query()->forceCreate(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'project_id' => $project->id, 'project_billing_milestone_id' => $invoiced->id, 'document_type_id' => $this->invoiceType->id, 'document_type' => 'Factura', 'code' => 'ING-EXISTE', 'issue_date' => '2026-08-31', 'net_amount' => 1, 'vat_rate' => 0, 'vat_amount' => 0, 'gross_amount' => 1, 'status' => 'Pendiente', 'is_voided' => false]);

        $this->actingAs($this->admin)->get(route('operational.create', 'sales-documents'))
            ->assertOk()
            ->assertSee('FACTURACIÓN POR HITO')
            ->assertSee('CLOSED_PROJECT', false)
            ->assertSee('Entrega')
            ->assertDontSee('Inicio');

        $this->actingAs($this->admin)->post(route('projects.milestones.preview', [$project, $pending]), ['issue_date' => '2026-08-31', 'taxable' => 1])
            ->assertOk()
            ->assertJsonPath('contractual_amount', 72)
            ->assertJsonPath('net_amount', 2880000)
            ->assertJsonPath('due_date', '2026-09-30');

        $this->actingAs($this->admin)->post(route('projects.milestones.issue', [$project, $pending]), ['issue_date' => '2026-08-31', 'taxable' => 1])
            ->assertRedirect();

        $document = SalesDocument::query()->where('project_billing_milestone_id', $pending->id)->sole();
        $this->assertSame('PROJECT_MILESTONE', $document->billing_source);
        $this->assertSame('Borrador', $document->status);
        $this->assertSame(2880000.0, (float) $document->net_amount);
        $this->assertSame(547200.0, (float) $document->vat_amount);
        $this->assertSame(3427200.0, (float) $document->gross_amount);
        $this->assertSame('2026-09-30', $document->due_date->toDateString());
        $this->assertSame('2026-09-30', $document->projected_collection_date->toDateString());
        $this->assertSame($pending->id, data_get($document->billing_snapshot, 'milestone_id'));

        $this->actingAs($this->admin)->post(route('projects.milestones.issue', [$project, $pending]), ['issue_date' => '2026-08-31', 'taxable' => 1])
            ->assertSessionHasErrors('milestone');

        $this->actingAs($this->admin)->post(route('operational.store', 'sales-documents'), $this->manualPayload($project->id, 1))
            ->assertSessionHasErrors('billing_source');
        $this->assertSame(2, SalesDocument::query()->count());
    }

    public function test_milestone_route_rejects_project_and_tenant_mismatches(): void
    {
        $project = $this->project('PROYECTO_CERRADO', 'Proyecto cerrado', ['sales_currency_id' => $this->uf->id, 'sale_net' => 100]);
        $otherProject = $this->project('PROYECTO_CERRADO', 'Proyecto cerrado 2', ['sales_currency_id' => $this->uf->id, 'sale_net' => 100]);
        $milestone = ProjectBillingMilestone::query()->forceCreate(['company_id' => $this->company->id, 'project_id' => $otherProject->id, 'sequence' => 1, 'name' => 'Otro', 'percentage' => 100]);

        $this->actingAs($this->admin)->post(route('projects.milestones.preview', [$project, $milestone]), ['issue_date' => '2026-08-31'])->assertNotFound();

        $otherCompany = Company::query()->create(['code' => 'CMP-OTHER', 'name' => 'Otra', 'status' => 'active']);
        $otherClient = Client::query()->create(['company_id' => $otherCompany->id, 'code' => 'CLI-OTHER', 'legal_name' => 'Otro cliente']);
        $otherContract = ContractType::query()->create(['company_id' => $otherCompany->id, 'domain' => 'commercial', 'code' => 'PROYECTO_CERRADO', 'name' => 'Proyecto cerrado', 'active' => true]);
        $otherProject = Project::query()->create(['company_id' => $otherCompany->id, 'client_id' => $otherClient->id, 'code' => 'PRY-OTHER', 'name' => 'Otro proyecto', 'contract_type_id' => $otherContract->id, 'sale_net' => 100]);
        $otherMilestone = ProjectBillingMilestone::query()->forceCreate(['company_id' => $otherCompany->id, 'project_id' => $otherProject->id, 'sequence' => 1, 'name' => 'Tenant', 'percentage' => 100]);

        $this->actingAs($this->admin)->post(route('projects.milestones.preview', [$otherProject, $otherMilestone]), ['issue_date' => '2026-08-31'])->assertNotFound();
    }

    public function test_hourly_project_uses_prefacturation_and_manual_document_without_project_stays_available(): void
    {
        $project = $this->project('POR_HORA', 'Por hora', ['sales_currency_id' => $this->uf->id, 'contracted_hourly_rate' => 1.5]);
        $approved = ApprovalStatus::query()->create(['company_id' => $this->company->id, 'code' => 'approved', 'name' => 'Aprobado', 'active' => true]);
        $person = Person::query()->create(['company_id' => $this->company->id, 'code' => 'PER-GUIDED', 'name' => 'Consultora', 'modality' => 'Honorarios por hora', 'status' => 'active']);
        $assignment = ProjectAssignment::query()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'person_id' => $person->id, 'project_id' => $project->id, 'code' => 'ASI-GUIDED', 'hourly_value' => 999, 'status' => 'active']);
        $entry = TimeEntry::query()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'person_id' => $person->id, 'project_id' => $project->id, 'assignment_id' => $assignment->id, 'code' => 'HOR-GUIDED', 'entry_date' => '2026-08-15', 'activity' => 'QA', 'hours_worked' => 2, 'hours_approved' => 2, 'approval_status_id' => $approved->id, 'approval_status' => 'approved', 'payment_status' => 'pending']);

        $this->actingAs($this->admin)->get(route('operational.create', 'sales-documents'))
            ->assertOk()
            ->assertSee('FACTURACIÓN POR HORAS APROBADAS')
            ->assertSee('HOURLY', false);

        $this->actingAs($this->admin)->post(route('sales-prefacturation.generate-draft'), ['project_id' => $project->id, 'period' => '08/2026', 'issue_date' => '2026-08-31', 'taxable' => 1])
            ->assertRedirect();
        $document = SalesDocument::query()->where('project_id', $project->id)->sole();
        $this->assertSame('TIME_ENTRIES', $document->billing_source);
        $this->assertSame(1, SalesDocumentTimeEntry::query()->where('sales_document_id', $document->id)->count());

        $this->actingAs($this->admin)->post(route('sales-prefacturation.generate-draft'), ['project_id' => $project->id, 'period' => '08/2026', 'issue_date' => '2026-08-31', 'taxable' => 1])
            ->assertSessionHasErrors('prefacturacion');

        $this->actingAs($this->admin)->post(route('operational.store', 'sales-documents'), $this->manualPayload($project->id, 1000))
            ->assertSessionHasErrors('billing_source');
        $this->assertSame(1, SalesDocument::query()->count());

        $this->actingAs($this->admin)->post(route('operational.store', 'sales-documents'), $this->manualPayload(null, 1000))
            ->assertRedirect(route('operational.index', 'sales-documents'));
        $this->assertSame(2, SalesDocument::query()->count());
        $this->assertSame('Pendiente', SalesDocument::query()->latest('id')->value('status'));
        $this->assertSame($entry->id, SalesDocumentTimeEntry::query()->where('sales_document_id', $document->id)->value('time_entry_id'));
    }

    private function project(string $code, string $name, array $attributes = []): Project
    {
        $contract = ContractType::query()->firstOrCreate(['company_id' => $this->company->id, 'domain' => 'commercial', 'code' => $code], ['name' => $name, 'active' => true]);

        return Project::query()->create(array_merge([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'code' => 'PRY-'.uniqid(),
            'name' => 'Proyecto '.uniqid(),
            'contract_type_id' => $contract->id,
            'project_status_id' => $this->activeProjectStatus->id,
        ], $attributes));
    }

    private function manualPayload(?int $projectId, float $netAmount): array
    {
        return [
            'code' => 'ING-MANUAL-'.uniqid(),
            'client_id' => $this->client->id,
            'project_id' => $projectId,
            'document_type_id' => $this->invoiceType->id,
            'document_number' => 'MANUAL-'.uniqid(),
            'issue_date' => '2026-08-31',
            'net_amount' => $netAmount,
            'is_voided' => 0,
        ];
    }
}
