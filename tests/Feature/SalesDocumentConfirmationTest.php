<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\CashMovement;
use App\Models\ContractType;
use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\SalesDocumentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesDocumentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['code' => 'CMP-CONFIRM', 'name' => 'Empresa confirmación', 'status' => 'active']);
        $this->admin = User::query()->create(['company_id' => $this->company->id, 'name' => 'Admin', 'email' => 'confirm-'.$this->company->id.'@test.local', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->type = DocumentType::query()->create(['company_id' => $this->company->id, 'domain' => 'sales', 'code' => 'FACTURA', 'name' => 'Factura', 'active' => true]);
    }

    public function test_generic_edit_preserves_draft_and_dedicated_confirmation_emits_without_recalculating(): void
    {
        $document = $this->draft();
        $payload = ['code' => $document->code, 'client_id' => $document->client_id, 'document_type_id' => $this->type->id, 'document_number' => 'F-100', 'issue_date' => '2026-09-07', 'net_amount' => 1000, 'is_voided' => 0];
        $this->actingAs($this->admin)->put(route('operational.update', ['sales-documents', $document->id]), $payload)->assertRedirect();
        $this->assertSame('Borrador', $document->fresh()->status);

        $response = $this->actingAs($this->admin)->post(route('operational.sales-documents.confirm', ['sales-documents', $document->id]), ['document_number' => 'F-100']);
        $response->assertRedirect(route('operational.show', ['sales-documents', $document->id]));
        $confirmed = $document->fresh();
        $this->assertSame('Pendiente', $confirmed->status);
        $this->assertSame(1000.0, (float) $confirmed->net_amount);
        $this->assertSame(1190.0, (float) $confirmed->gross_amount);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sales_document.confirmed', 'auditable_id' => $document->id]);
    }

    public function test_confirmation_uses_existing_number_when_request_omits_it_and_rejects_blank_number(): void
    {
        $existing = $this->draft(['document_number' => 'F-EXISTING']);
        app(SalesDocumentService::class)->confirm($existing, $this->admin);
        $this->assertSame('F-EXISTING', $existing->fresh()->document_number);

        $blank = $this->draft(['document_number' => null]);
        try {
            app(SalesDocumentService::class)->confirm($blank, $this->admin, '  ');
            $this->fail('El número vacío debía ser rechazado.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('número', $exception->getMessage());
        }
        $this->assertSame('Borrador', $blank->fresh()->status);
        $this->assertNull($blank->fresh()->document_number);
    }

    public function test_confirmation_requires_required_fields_and_only_accepts_draft(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('número');
        app(SalesDocumentService::class)->confirm($this->draft(['document_number' => null]), $this->admin);
    }

    public function test_confirmed_or_voided_document_cannot_be_confirmed(): void
    {
        $confirmed = $this->draft(['status' => 'Pendiente']);
        $this->expectException(DomainException::class);
        app(SalesDocumentService::class)->confirm($confirmed, $this->admin);
    }

    public function test_confirmation_preserves_financial_snapshot_milestone_and_creates_no_time_entry_links(): void
    {
        $project = $this->makeProject();
        $milestone = ProjectBillingMilestone::query()->forceCreate(['company_id' => $this->company->id, 'project_id' => $project->id, 'sequence' => 1, 'name' => 'Hito', 'percentage' => 100]);
        $document = $this->draft(['project_id' => $milestone->project_id, 'project_billing_milestone_id' => $milestone->id, 'billing_source' => 'PROJECT_MILESTONE', 'billing_snapshot' => ['source' => 'PROJECT_MILESTONE', 'original' => 'keep']]);
        app(SalesDocumentService::class)->confirm($document, $this->admin);
        $confirmed = $document->fresh();
        $this->assertSame(0.19, (float) $confirmed->vat_rate);
        $this->assertSame(190.0, (float) $confirmed->vat_amount);
        $this->assertSame('2026-10-07', $confirmed->due_date->toDateString());
        $this->assertSame('2026-10-07', $confirmed->projected_collection_date->toDateString());
        $this->assertSame('PROJECT_MILESTONE', $confirmed->billing_source);
        $this->assertSame(['source' => 'PROJECT_MILESTONE', 'original' => 'keep'], $confirmed->billing_snapshot);
        $this->assertSame($milestone->id, $confirmed->project_billing_milestone_id);
        $this->assertSame(0, $confirmed->timeEntryLinks()->count());
    }

    public function test_generic_edit_of_pending_document_preserves_status_and_posted_movement_blocks_edit(): void
    {
        $pending = $this->draft(['status' => 'Pendiente']);
        $this->actingAs($this->admin)->put(route('operational.update', ['sales-documents', $pending->id]), ['code' => $pending->code, 'client_id' => $pending->client_id, 'document_type_id' => $this->type->id, 'document_number' => 'F-2', 'issue_date' => '2026-09-07', 'net_amount' => 1000, 'is_voided' => 0])->assertRedirect();
        $this->assertSame('Pendiente', $pending->fresh()->status);

        CashMovement::query()->create(['company_id' => $this->company->id, 'code' => 'MOV-POSTED', 'movement_type' => 'Ingreso', 'source_document_type' => 'sales_document', 'source_document_code' => $pending->code, 'movement_date' => '2026-09-07', 'income' => 1000, 'status' => 'posted']);
        $blocked = $this->actingAs($this->admin)->put(route('operational.update', ['sales-documents', $pending->id]), ['code' => $pending->code, 'client_id' => $pending->client_id, 'document_type_id' => $this->type->id, 'document_number' => 'F-3', 'issue_date' => '2026-09-07', 'net_amount' => 900, 'is_voided' => 0]);
        $blocked->assertRedirect();
        $this->assertSame(1000.0, (float) $pending->fresh()->net_amount);
    }

    public function test_sales_document_show_displays_null_probability_as_full_probability(): void
    {
        $document = $this->draft(['payment_probability' => null]);
        $this->actingAs($this->admin)->get(route('operational.show', ['sales-documents', $document->id]))
            ->assertOk()->assertSee('100 %');
    }

    public function test_confirmation_requires_type_and_issue_date_and_rejects_voided_documents(): void
    {
        foreach ([['document_type_id' => null, 'message' => 'tipo'], ['issue_date' => null, 'message' => 'fecha'], ['is_voided' => true, 'message' => 'anulada']] as $case) {
            $document = $this->draft(array_diff_key($case, ['message' => true]));
            try {
                app(SalesDocumentService::class)->confirm($document, $this->admin);
                $this->fail('La confirmación debía ser rechazada.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString($case['message'], $exception->getMessage());
            }
        }
    }

    private function draft(array $overrides = []): SalesDocument
    {
        unset($overrides['message']);
        $client = Client::query()->create(['company_id' => $this->company->id, 'code' => 'CLI-CONFIRM-'.uniqid(), 'legal_name' => 'Cliente']);
        return SalesDocument::query()->forceCreate(array_merge(['company_id' => $this->company->id, 'client_id' => $client->id, 'code' => 'ING-CONFIRM-'.uniqid(), 'document_type_id' => $this->type->id, 'document_type' => 'Factura', 'document_number' => 'F-1', 'issue_date' => '2026-09-07', 'due_date' => '2026-10-07', 'projected_collection_date' => '2026-10-07', 'payment_probability' => 0.5, 'net_amount' => 1000, 'vat_rate' => 0.19, 'vat_amount' => 190, 'gross_amount' => 1190, 'collected_amount' => 0, 'status' => 'Borrador', 'is_voided' => false, 'billing_source' => 'PROJECT_MILESTONE', 'billing_snapshot' => ['source' => 'PROJECT_MILESTONE'], 'calculation_status' => 'OK'], $overrides));
    }

    private function makeProject(): Project
    {
        $contract = ContractType::query()->create(['company_id' => $this->company->id, 'domain' => 'commercial', 'code' => 'PROYECTO_CERRADO', 'name' => 'Proyecto cerrado', 'active' => true]);
        $client = Client::query()->create(['company_id' => $this->company->id, 'code' => 'CLI-PROJECT', 'legal_name' => 'Cliente proyecto']);
        return Project::query()->create(['company_id' => $this->company->id, 'client_id' => $client->id, 'code' => 'PRY-CONFIRM', 'name' => 'Proyecto', 'contract_type_id' => $contract->id, 'sale_net' => 100]);
    }
}
