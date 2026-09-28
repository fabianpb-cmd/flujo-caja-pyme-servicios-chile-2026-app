<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ExpenseDocument;
use App\Models\ExpenseSourceDocument;
use App\Models\LegalParameter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseDocumentPdfImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_preview_uses_one_request_and_creates_expense_through_normal_pipeline(): void
    {
        [$company, $admin] = $this->fixtures(); Storage::fake('local'); config()->set('assistant.api_key', 'test-key');
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->responsePayload(), 200)]);
        $file = UploadedFile::fake()->createWithContent('factura.pdf', "%PDF-1.4\nFACTURA");

        $this->actingAs($admin)->get(route('expense-documents.index'))->assertOk()->assertSee('Crear desde PDF');
        $this->actingAs($admin)->post(route('expense-documents.from-pdf.analyze'), ['expense_pdf' => $file])->assertRedirect();
        Http::assertSentCount(1);
        $token = array_key_first((array) session('expense_pdf_imports'));
        $review = $this->actingAs($admin)->get(route('expense-documents.from-pdf', ['token' => $token]));
        $review->assertOk()->assertSee('Crear Gasto desde PDF')->assertSee('Proveedor PDF')->assertSee('Importes consistentes')->assertSee('name="document_number"', false)->assertSee('value="F-100"', false);

        $create = $this->actingAs($admin)->post(route('operational.store', 'expense-documents'), ['expense_pdf_import_token' => $token, 'vendor_name' => 'Proveedor PDF', 'document_number' => 'F-101', 'issue_date' => '2026-09-28', 'due_date' => '2026-10-28', 'net_amount' => 100000, 'deductible_vat' => '0']);
        $create->assertRedirect(route('operational.index', 'expense-documents'));
        $expense = ExpenseDocument::query()->where('company_id', $company->id)->sole();
        $source = ExpenseSourceDocument::query()->where('expense_document_id', $expense->id)->sole();
        $this->assertSame('Pendiente', $expense->payment_status);
        $this->assertSame('F-101', $expense->document_number);
        $this->assertSame('F-100', $source->document_number);
        Storage::disk('local')->assertExists($source->storage_path);
        $this->actingAs($admin)->get(route('expense-documents.source-documents.download', [$expense, $source]))->assertOk();
    }

    public function test_duplicate_hash_is_rejected_before_openai(): void
    {
        [$company, $admin] = $this->fixtures(); Storage::fake('local');
        $content = "%PDF-1.4\nDUP"; $hash = hash('sha256', $content);
        ExpenseSourceDocument::query()->forceCreate(['company_id' => $company->id, 'expense_document_id' => ExpenseDocument::query()->create(['company_id' => $company->id, 'vendor_name' => 'Previo', 'issue_date' => '2026-09-28', 'net_amount' => 10])->id, 'sha256' => $hash, 'original_filename' => 'old.pdf', 'storage_path' => 'expense-source-documents/old.pdf', 'mime_type' => 'application/pdf', 'file_size' => strlen($content)]);
        Http::fake();
        $this->actingAs($admin)->post(route('expense-documents.from-pdf.analyze'), ['expense_pdf' => UploadedFile::fake()->createWithContent('dup.pdf', $content)])->assertSessionHasErrors('expense_pdf');
        Http::assertNothingSent();
    }

    public function test_non_clp_document_is_visible_but_creation_is_blocked(): void
    {
        [, $admin] = $this->fixtures(); Storage::fake('local'); config()->set('assistant.api_key', 'test-key'); Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->responsePayload(['currency_code' => 'USD']), 200)]);
        $this->actingAs($admin)->post(route('expense-documents.from-pdf.analyze'), ['expense_pdf' => UploadedFile::fake()->createWithContent('usd.pdf', "%PDF-1.4\nUSD")])->assertRedirect();
        $token = array_key_first((array) session('expense_pdf_imports'));
        $this->actingAs($admin)->get(route('expense-documents.from-pdf', ['token' => $token]))->assertSee('solo crea documentos en CLP');
    }

    public function test_delete_rollback_preserves_expense_source_row_and_pdf(): void
    {
        [$company, $admin] = $this->fixtures(); Storage::fake('local');
        $expense = ExpenseDocument::query()->create(['company_id' => $company->id, 'vendor_name' => 'Rollback', 'issue_date' => '2026-09-28', 'net_amount' => 100]);
        Storage::disk('local')->put('expense-source-documents/rollback.pdf', '%PDF-1.4');
        $source = ExpenseSourceDocument::query()->forceCreate(['company_id' => $company->id, 'expense_document_id' => $expense->id, 'storage_path' => 'expense-source-documents/rollback.pdf', 'original_filename' => 'rollback.pdf', 'mime_type' => 'application/pdf', 'file_size' => 8, 'sha256' => hash('sha256', 'rollback')]);
        ExpenseDocument::deleting(fn (): never => throw new \RuntimeException('forced delete failure'));
        try {
            $this->withoutExceptionHandling();
            try {
                $this->actingAs($admin)->delete(route('operational.destroy', ['expense-documents', $expense->id]));
                $this->fail('Expected forced delete failure.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('forced delete failure', $exception->getMessage());
            }
        } finally {
            ExpenseDocument::flushEventListeners();
        }
        $this->assertDatabaseHas('expense_documents', ['id' => $expense->id]);
        $this->assertDatabaseHas('expense_source_documents', ['id' => $source->id]);
        Storage::disk('local')->assertExists('expense-source-documents/rollback.pdf');
    }

    public function test_delete_never_removes_source_path_outside_owned_prefix(): void
    {
        [$company, $admin] = $this->fixtures(); Storage::fake('local');
        $expense = ExpenseDocument::query()->create(['company_id' => $company->id, 'vendor_name' => 'Path safety', 'issue_date' => '2026-09-28', 'net_amount' => 100]);
        Storage::disk('local')->put('expense-pdf/tmp/not-owned.pdf', '%PDF-1.4');
        ExpenseSourceDocument::query()->forceCreate(['company_id' => $company->id, 'expense_document_id' => $expense->id, 'storage_path' => 'expense-pdf/tmp/not-owned.pdf', 'original_filename' => 'not-owned.pdf', 'mime_type' => 'application/pdf', 'file_size' => 8, 'sha256' => hash('sha256', 'not-owned')]);
        $this->actingAs($admin)->delete(route('operational.destroy', ['expense-documents', $expense->id]))->assertRedirect();
        Storage::disk('local')->assertExists('expense-pdf/tmp/not-owned.pdf');
    }

    private function fixtures(): array
    {
        $company = Company::query()->create(['code' => 'CMP-EXP-PDF', 'name' => 'Empresa gastos PDF', 'status' => 'active']);
        $admin = User::query()->create(['company_id' => $company->id, 'name' => 'Admin', 'email' => 'expense-pdf-'.$company->id.'@test.local', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        LegalParameter::query()->create(['company_id' => $company->id, 'parameter_code' => 'IVA', 'parameter_name' => 'IVA', 'valid_from' => '2026-01-01', 'value' => 0.19, 'unit' => '%', 'active' => true]);
        return [$company, $admin];
    }

    private function responsePayload(array $overrides = []): array
    {
        $fields = array_merge(['document_type' => 'Factura', 'document_number' => 'F-100', 'supplier_name' => 'Proveedor PDF', 'supplier_tax_id' => '76.123.456-7', 'issue_date' => '2026-09-28', 'due_date' => null, 'payment_terms_days' => 30, 'payment_terms_text' => '30 días', 'currency_code' => 'CLP', 'net_amount' => 100000, 'vat_rate' => 19, 'vat_amount' => 19000, 'exempt_amount' => 0, 'total_amount' => 119000, 'description' => 'Servicio'], $overrides);
        return ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($fields + ['warnings' => [], 'confidence' => array_fill_keys(array_keys($fields), 1), 'evidence' => array_fill_keys(array_keys($fields), null)], JSON_THROW_ON_ERROR)]]]]];
    }
}
