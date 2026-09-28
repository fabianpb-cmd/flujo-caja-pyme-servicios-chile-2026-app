<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ContractType;
use App\Models\Currency;
use App\Models\PaymentTerm;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectSourceDocument;
use App\Models\LegalParameter;
use App\Models\RecordStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectPurchaseOrderImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_is_staged_privately_and_only_attached_after_normal_project_creation(): void
    {
        [$company, $admin, $client, $currency, $term, $hourly, $status, $billing] = $this->fixtures();
        Storage::fake('local');
        config()->set('assistant.api_key', 'test-key');
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->responsePayload(), 200)]);
        $file = UploadedFile::fake()->createWithContent('oc-100.pdf', "%PDF-1.4\nOC QA");

        $landing = $this->actingAs($admin)->get(route('projects.from-purchase-order'));
        $landing->assertOk()->assertSee('data-oc-analysis-form')->assertSee('data-oc-analysis-progress')->assertSee('data-oc-analysis-submit')->assertSee('Procesando orden de compra');

        $analyze = $this->actingAs($admin)->post(route('projects.from-purchase-order.analyze'), ['purchase_order' => $file]);

        $analyze->assertRedirect();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('projects', 0);
        $imports = (array) session('project_oc_imports', []);
        $token = (string) (array_key_first($imports) ?? '');
        $this->assertNotSame('', $token);
        $review = $this->actingAs($admin)->get(route('projects.from-purchase-order', ['token' => $token]));
        $review->assertOk()->assertSee('Cliente OC')->assertSee('OC-100')->assertSee('Crear proyecto y adjuntar OC')->assertSee('En OC')->assertSee('Propuesto por IA')->assertSee('data-oc-project-form')->assertSee('Creando proyecto');

        $this->assertDatabaseCount('projects', 0);
        $create = $this->actingAs($admin)->post(route('operational.store', 'projects'), [
            'oc_import_token' => $token,
            'client_id' => $client->id,
            'sales_currency_id' => $currency->id,
            'name' => 'Servicio OC QA',
            'contract_type_id' => $hourly->id,
            'contracted_hourly_rate' => 1.5,
            'payment_term_id' => $term->id,
            'sale_net' => 125000,
            'project_status_id' => $status->id,
            'billing_status_id' => $billing->id,
        ]);

        if ($create->exception) {
            throw new \RuntimeException(get_class($create->exception).' '.$create->exception->getMessage().'\n'.$create->exception->getTraceAsString());
        }
        try {
            $create->assertRedirect(route('operational.index', 'projects'));
        } catch (\Throwable $exception) {
            throw new \RuntimeException($exception->getMessage()."\n".$exception->getTraceAsString(), 0, $exception);
        }
        $project = Project::query()->where('company_id', $company->id)->sole();
        $document = ProjectSourceDocument::query()->where('project_id', $project->id)->sole();
        $this->assertSame('PURCHASE_ORDER', $document->document_type);
        $this->assertSame('OC-100', $document->document_number);
        $this->assertSame($company->id, $document->company_id);
        $this->assertSame('EXPLICIT', $document->extracted_payload['billing_milestones'][0]['source']);

        $show = $this->actingAs($admin)->get(route('operational.show', ['projects', $project->id]));
        $show->assertOk()->assertSee('Documento origen')->assertSee('oc-100.pdf')->assertSee('Descargar OC');
        $this->actingAs($admin)->get(route('projects.source-documents.download', [$project, $document]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $otherCompany = Company::query()->create(['code' => 'CMP-OC-X', 'name' => 'Otra empresa', 'status' => 'active']);
        $otherUser = User::query()->create(['company_id' => $otherCompany->id, 'name' => 'Otro', 'email' => 'other-'.$otherCompany->id.'@oc.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->actingAs($otherUser)->get(route('projects.source-documents.download', [$project, $document]))->assertForbidden();

        $sourcePath = $document->storage_path;
        $this->actingAs($admin)->delete(route('operational.destroy', ['projects', $project->id]))
            ->assertRedirect(route('operational.index', 'projects'))
            ->assertSessionHas('status', 'Registro eliminado.');
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('project_source_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($sourcePath);
    }

    public function test_duplicate_oc_and_cross_tenant_preview_are_rejected_without_creating_a_project(): void
    {
        [$company, $admin] = $this->fixtures();
        $otherCompany = Company::query()->create(['code' => 'CMP-OC-OTHER', 'name' => 'Otra empresa', 'status' => 'active']);
        $otherUser = User::query()->create(['company_id' => $otherCompany->id, 'name' => 'Otro', 'email' => 'other@oc.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        Storage::fake('local');
        config()->set('assistant.api_key', 'test-key');
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->responsePayload(), 200)]);
        $content = "%PDF-1.4\nOC DUP";
        $first = UploadedFile::fake()->createWithContent('duplicate.pdf', $content);
        $this->actingAs($admin)->post(route('projects.from-purchase-order.analyze'), ['purchase_order' => $first])->assertRedirect();
        $token = array_key_first(session('project_oc_imports'));

        $this->actingAs($otherUser)->get(route('projects.from-purchase-order', ['token' => $token]))->assertOk()->assertSee('no está disponible para esta sesión');
        ProjectSourceDocument::query()->forceCreate([
            'company_id' => $company->id,
            'project_id' => Project::query()->create(['company_id' => $company->id, 'code' => 'PRY-OC-DUP', 'client_id' => Client::query()->where('company_id', $company->id)->value('id'), 'name' => 'Previo'])->id,
            'document_type' => 'PURCHASE_ORDER', 'original_filename' => 'old.pdf', 'storage_path' => 'project-source-documents/old.pdf', 'mime_type' => 'application/pdf', 'file_size' => strlen($content), 'sha256' => hash('sha256', $content), 'created_by' => $admin->id,
        ]);
        $duplicate = UploadedFile::fake()->createWithContent('duplicate.pdf', $content);
        Http::fake();
        $this->actingAs($admin)->from(route('projects.from-purchase-order'))->post(route('projects.from-purchase-order.analyze'), ['purchase_order' => $duplicate])->assertSessionHasErrors('purchase_order');
        Http::assertNothingSent();
        $this->assertSame(1, Project::query()->where('company_id', $company->id)->count());
    }

    public function test_invalid_pdf_is_rejected_before_openai_is_called(): void
    {
        [, $admin] = $this->fixtures();
        Http::fake();
        $file = UploadedFile::fake()->createWithContent('not-a-pdf.pdf', 'not a PDF');

        $this->actingAs($admin)->from(route('projects.from-purchase-order'))->post(route('projects.from-purchase-order.analyze'), ['purchase_order' => $file])->assertSessionHasErrors('purchase_order');
        Http::assertNothingSent();
    }

    public function test_milestone_date_metadata_is_resolved_without_inventing_calendar_days(): void
    {
        [, $admin] = $this->fixtures();
        Storage::fake('local');
        config()->set('assistant.api_key', 'test-key');
        $milestones = [
            ['sequence' => 1, 'name' => 'Inicio', 'source' => 'EXPLICIT', 'percentage' => 20, 'amount' => null, 'planned_invoice_date' => null, 'date_kind' => 'EXACT', 'date_text' => '30 de septiembre de 2026', 'date_anchor' => 'NONE', 'relative_value' => null, 'relative_unit' => null, 'relative_to_sequence' => null, 'date_evidence' => '30 de septiembre de 2026', 'date_confidence' => 0.98, 'evidence' => 'Inicio', 'confidence' => 0.98],
            ['sequence' => 2, 'name' => 'Avance', 'source' => 'EXPLICIT', 'percentage' => 30, 'amount' => null, 'planned_invoice_date' => null, 'date_kind' => 'RELATIVE', 'date_text' => '2 semanas después del hito 1', 'date_anchor' => 'SPECIFIC_MILESTONE', 'relative_value' => 2, 'relative_unit' => 'WEEKS', 'relative_to_sequence' => 1, 'date_evidence' => '2 semanas después del hito 1', 'date_confidence' => 0.9, 'evidence' => 'Avance', 'confidence' => 0.9],
            ['sequence' => 3, 'name' => 'Cierre', 'source' => 'EXPLICIT', 'percentage' => 50, 'amount' => null, 'planned_invoice_date' => null, 'date_kind' => 'MONTH_YEAR', 'date_text' => 'Octubre 2026', 'date_anchor' => 'NONE', 'relative_value' => null, 'relative_unit' => null, 'relative_to_sequence' => null, 'date_evidence' => 'Octubre 2026', 'date_confidence' => 0.85, 'evidence' => 'Cierre', 'confidence' => 0.85],
        ];
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->responsePayload($milestones, ['service_start_date' => '2026-09-01', 'service_end_date' => '2026-10-31']), 200)]);
        $file = UploadedFile::fake()->createWithContent('dates.pdf', "%PDF-1.4\nOC DATES");

        $this->actingAs($admin)->post(route('projects.from-purchase-order.analyze'), ['purchase_order' => $file])->assertRedirect();
        Http::assertSentCount(1);
        $state = array_values((array) session('project_oc_imports'))[0]['extracted']['billing_milestones'];

        $this->assertSame('2026-09-30', $state[0]['planned_invoice_date']);
        $this->assertSame('EXPLICIT', $state[0]['resolved_date_source']);
        $this->assertSame('2026-10-14', $state[1]['planned_invoice_date']);
        $this->assertSame('CALCULATED', $state[1]['resolved_date_source']);
        $this->assertNull($state[2]['planned_invoice_date']);
        $this->assertSame('Octubre 2026', $state[2]['date_text']);
        $this->assertSame('UNRESOLVED', $state[2]['resolved_date_source']);
    }

    public function test_source_document_migration_uses_mysql_safe_index_name(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_27_000100_create_project_source_documents_table.php'));

        $this->assertNotFalse($migration);
        $this->assertStringContainsString("'psd_company_project_type_idx'", $migration);
        $this->assertLessThanOrEqual(64, strlen('psd_company_project_type_idx'));
    }

    public function test_closed_project_from_purchase_order_creates_milestone_and_consumes_token(): void
    {
        [$company, $admin, $client, $currency, $term, $hourly, $status, $billing] = $this->fixtures();
        $closed = ContractType::query()->create(['company_id' => $company->id, 'domain' => 'commercial', 'code' => 'PROYECTO_CERRADO', 'name' => 'Proyecto cerrado', 'active' => true]);
        Storage::fake('local');
        config()->set('assistant.api_key', 'test-key');
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->responsePayload(), 200)]);
        $file = UploadedFile::fake()->createWithContent('closed.pdf', "%PDF-1.4\nOC CLOSED");
        $this->actingAs($admin)->post(route('projects.from-purchase-order.analyze'), ['purchase_order' => $file])->assertRedirect();
        $token = array_key_first(session('project_oc_imports'));

        $response = $this->actingAs($admin)->post(route('operational.store', 'projects'), [
            'oc_import_token' => $token, 'client_id' => $client->id, 'sales_currency_id' => $currency->id,
            'name' => 'Proyecto cerrado OC', 'contract_type_id' => $closed->id, 'payment_term_id' => $term->id,
            'sale_net' => 125000, 'project_status_id' => $status->id, 'billing_status_id' => $billing->id,
            'billing_milestones' => [['sequence' => 1, 'name' => 'Entrega final', 'planned_invoice_date' => '2026-10-01', 'percentage' => 100, 'notes' => '']],
        ]);

        $response->assertRedirect(route('operational.index', 'projects'));
        $project = Project::query()->where('name', 'Proyecto cerrado OC')->sole();
        $this->assertDatabaseHas('project_billing_milestones', ['project_id' => $project->id, 'percentage' => 100]);
        $this->assertDatabaseHas('project_source_documents', ['project_id' => $project->id]);
        $this->assertArrayNotHasKey($token, (array) session('project_oc_imports', []));
    }

    public function test_closed_project_without_milestones_keeps_token_and_temporary_pdf(): void
    {
        [$company, $admin, $client, $currency, $term] = $this->fixtures();
        $closed = ContractType::query()->create(['company_id' => $company->id, 'domain' => 'commercial', 'code' => 'PROYECTO_CERRADO', 'name' => 'Proyecto cerrado', 'active' => true]);
        Storage::fake('local');
        config()->set('assistant.api_key', 'test-key');
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->responsePayload(), 200)]);
        $file = UploadedFile::fake()->createWithContent('closed-invalid.pdf', "%PDF-1.4\nOC CLOSED INVALID");
        $this->actingAs($admin)->post(route('projects.from-purchase-order.analyze'), ['purchase_order' => $file])->assertRedirect();
        $imports = (array) session('project_oc_imports');
        $token = array_key_first($imports);
        $temporaryPath = $imports[$token]['temporary_path'];

        $response = $this->actingAs($admin)->post(route('operational.store', 'projects'), [
            'oc_import_token' => $token, 'client_id' => $client->id, 'sales_currency_id' => $currency->id,
            'name' => 'Proyecto cerrado inválido', 'contract_type_id' => $closed->id, 'payment_term_id' => $term->id,
            'sale_net' => 125000, 'project_status_id' => $this->statusId($company), 'billing_status_id' => $this->billingStatusId($company),
        ]);

        $response->assertRedirect()->assertSessionHasErrors('project_billing_plan');
        $this->assertDatabaseMissing('projects', ['name' => 'Proyecto cerrado inválido']);
        $this->assertDatabaseMissing('project_source_documents', ['sha256' => $imports[$token]['sha256']]);
        $this->assertArrayHasKey($token, (array) session('project_oc_imports', []));
        Storage::disk('local')->assertExists($temporaryPath);
    }

    public function test_operational_assignment_still_blocks_project_delete_without_listing_source_document(): void
    {
        [$company, $admin, $client] = $this->fixtures();
        Storage::fake('local');
        $project = Project::query()->create(['company_id' => $company->id, 'client_id' => $client->id, 'code' => 'PRJ-BLOCK', 'name' => 'Proyecto bloqueado']);
        Storage::disk('local')->put('project-source-documents/1/1/block.pdf', '%PDF-1.4');
        $document = ProjectSourceDocument::query()->forceCreate(['company_id' => $company->id, 'project_id' => $project->id, 'document_type' => 'PURCHASE_ORDER', 'document_number' => 'OC-BLOCK', 'original_filename' => 'block.pdf', 'storage_path' => 'project-source-documents/1/1/block.pdf', 'mime_type' => 'application/pdf', 'file_size' => 8, 'sha256' => hash('sha256', 'block'), 'created_by' => $admin->id]);
        $person = Person::query()->create(['company_id' => $company->id, 'code' => 'PER-BLOCK', 'name' => 'Persona bloqueada', 'modality' => 'HONORARIOS']);
        $assignment = ProjectAssignment::query()->create(['company_id' => $company->id, 'person_id' => $person->id, 'client_id' => $client->id, 'project_id' => $project->id, 'code' => 'ASI-BLOCK']);

        $response = $this->actingAs($admin)->delete(route('operational.destroy', ['projects', $project->id]));

        $response->assertRedirect(route('operational.show', ['projects', $project->id]))->assertSessionHasErrors('dependencies');
        $dependencyError = (string) $response->getSession()->get('errors')->first('dependencies');
        $this->assertStringContainsString('asignaciones', $dependencyError);
        $this->assertStringNotContainsString('documentos origen', $dependencyError);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('project_source_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('project_assignments', ['id' => $assignment->id]);
        Storage::disk('local')->assertExists('project-source-documents/1/1/block.pdf');
    }

    private function statusId(Company $company): int
    {
        return (int) RecordStatus::query()->where('company_id', $company->id)->where('domain', 'project')->value('id');
    }

    private function billingStatusId(Company $company): int
    {
        return (int) RecordStatus::query()->where('company_id', $company->id)->where('domain', 'billing')->value('id');
    }

    private function fixtures(): array
    {
        $company = Company::query()->create(['code' => 'CMP-OC', 'name' => 'Empresa OC', 'status' => 'active']);
        $admin = User::query()->create(['company_id' => $company->id, 'name' => 'Admin OC', 'email' => 'admin@oc.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $client = Client::query()->create(['company_id' => $company->id, 'code' => 'CLI-OC', 'legal_name' => 'Cliente OC', 'tax_id' => '76.123.456-7']);
        $currency = Currency::query()->create(['company_id' => $company->id, 'code' => 'CLP', 'name' => 'Peso chileno', 'symbol' => '$', 'minor_units' => 0, 'active' => true]);
        $term = PaymentTerm::query()->create(['company_id' => $company->id, 'code' => '30D', 'name' => '30 días', 'days' => 30, 'active' => true]);
        $hourly = ContractType::query()->create(['company_id' => $company->id, 'domain' => 'commercial', 'code' => 'POR_HORA', 'name' => 'Por Hora', 'active' => true]);
        $status = RecordStatus::query()->create(['company_id' => $company->id, 'domain' => 'project', 'code' => 'draft', 'name' => 'Borrador', 'active' => true]);
        $billing = RecordStatus::query()->create(['company_id' => $company->id, 'domain' => 'billing', 'code' => 'pending', 'name' => 'Pendiente', 'active' => true]);
        LegalParameter::query()->create(['company_id' => $company->id, 'parameter_code' => 'IVA', 'parameter_name' => 'IVA', 'valid_from' => '2026-01-01', 'value' => 0.19, 'unit' => '%', 'active' => true]);

        return [$company, $admin, $client, $currency, $term, $hourly, $status, $billing];
    }

    private function responsePayload(?array $milestones = null, array $overrides = []): array
    {
        $fields = array_merge(['document_type' => 'PURCHASE_ORDER', 'purchase_order_number' => 'OC-100', 'buyer_name' => 'Cliente OC', 'buyer_tax_id' => '76.123.456-7', 'issue_date' => '2026-09-27', 'service_description' => 'Servicio OC QA', 'currency_code' => 'CLP', 'net_amount' => 125000, 'vat_amount' => 23750, 'total_amount' => 148750, 'payment_terms_days' => 30, 'payment_terms_text' => '30 días', 'service_start_date' => null, 'service_end_date' => null], $overrides);
        $milestones ??= [['sequence' => 1, 'name' => 'Inicio', 'source' => 'EXPLICIT', 'percentage' => 20, 'amount' => null, 'planned_invoice_date' => null, 'evidence' => 'Inicio indicado', 'confidence' => 0.9], ['sequence' => 2, 'name' => 'Entrega', 'source' => 'EXPLICIT', 'percentage' => 60, 'amount' => null, 'planned_invoice_date' => null, 'evidence' => 'Entrega indicada', 'confidence' => 0.9], ['sequence' => 3, 'name' => 'Cierre', 'source' => 'SUGGESTED', 'percentage' => null, 'amount' => null, 'planned_invoice_date' => null, 'evidence' => 'Etapa de cierre', 'confidence' => 0.7]];
        return ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($fields + ['billing_milestones' => $milestones, 'warnings' => [], 'confidence' => array_fill_keys(array_keys($fields), 1), 'evidence' => array_fill_keys(array_keys($fields), null)], JSON_THROW_ON_ERROR)]]]]];
    }
}
