<?php
namespace Tests\Feature;
use App\Models\Client;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\LegalParameter;
use App\Models\ContractType;
use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\RecordStatus;
use App\Models\SalesDocument;
use App\Models\SalesSourceDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
class SalesDocumentPdfImportTest extends TestCase
{
    use RefreshDatabase;
    public function test_pdf_analysis_is_single_request_and_preview_is_available(): void
    {
        [, $admin] = $this->fixtures(); Storage::fake('local'); config()->set('assistant.api_key','test-key'); Http::fake(['https://api.openai.com/v1/responses'=>Http::response($this->payload(),200)]);
        $this->actingAs($admin)->get(route('sales-documents.index'))->assertOk()->assertSee('Crear desde PDF');
        $this->actingAs($admin)->post(route('sales-documents.from-pdf.analyze'), ['sales_pdf'=>UploadedFile::fake()->createWithContent('factura.pdf', "%PDF-1.4\nFACTURA")])->assertRedirect();
        Http::assertSentCount(1); $token=array_key_first((array)session('sales_pdf_imports')); $this->actingAs($admin)->get(route('sales-documents.from-pdf',['token'=>$token]))->assertOk()->assertSee('Crear Factura desde PDF')->assertSee('Cliente Venta');
    }
    public function test_duplicate_hash_is_rejected_before_openai(): void
    {
        [$company,$admin]=$this->fixtures(); Storage::fake('local'); $content="%PDF-1.4\nDUP"; $expense=SalesDocument::query()->forceCreate(['company_id'=>$company->id,'client_id'=>Client::query()->where('company_id',$company->id)->value('id'),'document_type_id'=>DocumentType::query()->where('company_id',$company->id)->value('id'),'document_type'=>'Factura','document_number'=>'F-1','issue_date'=>'2026-09-28','net_amount'=>100,'vat_amount'=>19,'gross_amount'=>119,'status'=>'Pendiente']); SalesSourceDocument::query()->forceCreate(['company_id'=>$company->id,'sales_document_id'=>$expense->id,'sha256'=>hash('sha256',$content),'storage_path'=>'sales-source-documents/old.pdf','original_filename'=>'old.pdf','mime_type'=>'application/pdf','file_size'=>strlen($content)]); Http::fake();
        $this->actingAs($admin)->post(route('sales-documents.from-pdf.analyze'), ['sales_pdf'=>UploadedFile::fake()->createWithContent('dup.pdf',$content)])->assertSessionHasErrors('sales_pdf'); Http::assertNothingSent();
    }
    public function test_contractual_draft_comparison_rejects_amount_difference_without_mutating_snapshot(): void
    {
        [$company, $admin] = $this->fixtures(); $client=Client::query()->where('company_id',$company->id)->first(); $type=DocumentType::query()->where('company_id',$company->id)->first(); $document=SalesDocument::query()->forceCreate(['company_id'=>$company->id,'client_id'=>$client->id,'document_type_id'=>$type->id,'document_type'=>'Factura','document_number'=>'F-DRAFT','issue_date'=>'2026-09-28','net_amount'=>1000,'vat_amount'=>190,'gross_amount'=>1190,'status'=>'Borrador','billing_source'=>'PROJECT_MILESTONE','billing_snapshot'=>['source'=>'PROJECT_MILESTONE'],'is_voided'=>false]); $state=['extracted'=>array_merge($this->payload()['output'][0]['content'][0] ? json_decode($this->payload()['output'][0]['content'][0]['text'],true) : [], ['net_amount'=>900]), 'sha256'=>'x','timestamp'=>now()->toIso8601String(),'temporary_path'=>'x','user_id'=>$admin->id,'company_id'=>$company->id]; $comparison=app(\App\Services\SalesDocumentPdfImportService::class)->compare($state,$document); $this->assertFalse($comparison['ok']); $this->assertSame(['source'=>'PROJECT_MILESTONE'],$document->billing_snapshot);
    }

    public function test_closed_project_milestone_pdf_uses_contractual_pipeline_and_does_not_create_cash(): void
    {
        [$company, $admin] = $this->fixtures(); Storage::fake('local'); config()->set('assistant.api_key', 'test-key'); Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->payload(), 200)]);
        $client = Client::query()->where('company_id', $company->id)->first();
        $contract = ContractType::query()->create(['company_id' => $company->id, 'domain' => 'commercial', 'code' => 'PROYECTO_CERRADO', 'name' => 'Proyecto cerrado', 'active' => true]);
        $status = RecordStatus::query()->create(['company_id' => $company->id, 'domain' => 'project', 'code' => 'active', 'name' => 'Activo', 'active' => true]);
        $project = Project::query()->create(['company_id' => $company->id, 'client_id' => $client->id, 'name' => 'Proyecto cerrado', 'contract_type_id' => $contract->id, 'project_status_id' => $status->id, 'sale_net' => 1000]);
        $milestone = ProjectBillingMilestone::query()->forceCreate(['company_id' => $company->id, 'project_id' => $project->id, 'sequence' => 1, 'name' => 'Entrega', 'percentage' => 100, 'planned_invoice_date' => '2026-09-28']);
        $this->actingAs($admin)->post(route('sales-documents.from-pdf.analyze'), ['sales_pdf' => UploadedFile::fake()->createWithContent('factura.pdf', "%PDF-1.4\nFACTURA")])->assertRedirect();
        $token = array_key_first((array) session('sales_pdf_imports'));
        $cashBefore = \App\Models\CashMovement::query()->count();
        $this->actingAs($admin)->post(route('operational.store', 'sales-documents'), ['sales_pdf_import_token' => $token, 'client_id' => $client->id, 'project_id' => $project->id, 'project_billing_milestone_id' => $milestone->id, 'document_type_id' => DocumentType::query()->where('company_id', $company->id)->value('id'), 'document_number' => 'F-101', 'issue_date' => '2026-09-28', 'net_amount' => 1000])->assertRedirect();
        $document = SalesDocument::query()->where('project_billing_milestone_id', $milestone->id)->sole();
        $this->assertSame($project->id, $document->project_id);
        $this->assertSame('PROJECT_MILESTONE', $document->billing_source);
        $this->assertSame('PROJECT_MILESTONE', $document->billing_snapshot['source']);
        $this->assertSame('F-101', $document->document_number);
        $this->assertSame($cashBefore, \App\Models\CashMovement::query()->count());
        $this->assertDatabaseHas('sales_source_documents', ['sales_document_id' => $document->id, 'document_number' => 'F-100']);
    }

    public function test_pdf_client_match_cannot_be_replaced_by_another_client_project(): void
    {
        [$company, $admin] = $this->fixtures(); Storage::fake('local'); config()->set('assistant.api_key', 'test-key'); Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->payload(), 200)]);
        $other = Client::query()->create(['company_id' => $company->id, 'code' => 'CLI-OTHER', 'legal_name' => 'Otro cliente']);
        $status = RecordStatus::query()->create(['company_id' => $company->id, 'domain' => 'project', 'code' => 'active', 'name' => 'Activo', 'active' => true]);
        $project = Project::query()->create(['company_id' => $company->id, 'client_id' => $other->id, 'name' => 'Proyecto ajeno', 'project_status_id' => $status->id]);
        $this->actingAs($admin)->post(route('sales-documents.from-pdf.analyze'), ['sales_pdf' => UploadedFile::fake()->createWithContent('factura.pdf', "%PDF-1.4\nFACTURA")])->assertRedirect();
        $token = array_key_first((array) session('sales_pdf_imports'));
        $response = $this->actingAs($admin)->post(route('operational.store', 'sales-documents'), ['sales_pdf_import_token' => $token, 'client_id' => $other->id, 'project_id' => $project->id, 'document_type_id' => DocumentType::query()->where('company_id', $company->id)->value('id'), 'document_number' => 'F-101', 'issue_date' => '2026-09-28', 'net_amount' => 1000]);
        $response->assertSessionHasErrors('client_id');
        $this->assertSame(0, SalesDocument::query()->count());
        $this->assertSame(0, SalesSourceDocument::query()->count());
    }
    private function fixtures(): array { $company=Company::query()->create(['code'=>'CMP-SALES-PDF','name'=>'Ventas PDF','status'=>'active']); $admin=User::query()->create(['company_id'=>$company->id,'name'=>'Admin','email'=>'sales-pdf-'.$company->id.'@test.local','password'=>'password','role'=>'admin','active'=>true]); Client::query()->create(['company_id'=>$company->id,'code'=>'CLI-PDF','legal_name'=>'Cliente Venta','tax_id'=>'76.123.456-7']); DocumentType::query()->create(['company_id'=>$company->id,'domain'=>'sales','code'=>'FACTURA','name'=>'Factura','active'=>true]); LegalParameter::query()->create(['company_id'=>$company->id,'parameter_code'=>'IVA','parameter_name'=>'IVA','valid_from'=>'2026-01-01','value'=>0.19,'unit'=>'%','active'=>true]); return [$company,$admin]; }
    private function payload(): array { $fields=['document_type'=>'Factura','document_number'=>'F-100','issuer_name'=>'Mi Empresa','issuer_tax_id'=>null,'customer_name'=>'Cliente Venta','customer_tax_id'=>'76.123.456-7','issue_date'=>'2026-09-28','due_date'=>'2026-10-28','payment_terms_days'=>30,'payment_terms_text'=>'30 días','currency_code'=>'CLP','net_amount'=>1000,'vat_rate'=>19,'vat_amount'=>190,'exempt_amount'=>0,'total_amount'=>1190,'description'=>'Servicio']; return ['output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($fields+['warnings'=>[],'confidence'=>array_fill_keys(array_keys($fields),1),'evidence'=>array_fill_keys(array_keys($fields),null)],JSON_THROW_ON_ERROR)]]]]]; }
}
