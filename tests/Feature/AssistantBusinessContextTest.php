<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ContractType;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\LegalParameter;
use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\SalesDocument;
use App\Models\UfValue;
use App\Models\User;
use App\Services\AssistantBusinessContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantBusinessContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_same_tenant_project_and_preview_without_creating_documents(): void
    {
        [$user, $project, $milestone] = $this->fixture();
        $before = SalesDocument::query()->count();
        $context = app(AssistantBusinessContextService::class)->forUser($user, ['project_id' => $project->id, 'milestone_id' => $milestone->id, 'issue_date' => '2026-08-31']);
        $this->assertSame('CLOSED_PROJECT', data_get($context, 'project.strategy'));
        $this->assertSame(72.0, (float) data_get($context, 'milestone_preview.contractual_amount'));
        $this->assertSame(2880000.0, (float) data_get($context, 'milestone_preview.converted_amount'));
        $this->assertSame($before, SalesDocument::query()->count());
    }

    public function test_it_excludes_other_tenant_projects_and_milestones(): void
    {
        [$user, $project, $milestone] = $this->fixture();
        $other = Company::query()->create(['code' => 'CMP-AI-OTHER', 'name' => 'Other', 'status' => 'active']);
        $otherProject = Project::query()->create(['company_id' => $other->id, 'client_id' => Client::query()->create(['company_id' => $other->id, 'code' => 'CLI-OTHER', 'legal_name' => 'Other'])->id, 'code' => 'PRY-OTHER', 'name' => 'Other']);
        $this->assertSame([], app(AssistantBusinessContextService::class)->forUser($user, ['project_id' => $otherProject->id]));
        $this->assertArrayNotHasKey('milestone_preview', app(AssistantBusinessContextService::class)->forUser($user, ['project_id' => $project->id, 'milestone_id' => 99999, 'issue_date' => '2026-08-31']));
    }

    private function fixture(): array
    {
        $company = Company::query()->create(['code' => 'CMP-AI-BUS', 'name' => 'AI Business', 'status' => 'active']);
        $user = User::query()->create(['company_id' => $company->id, 'name' => 'Admin', 'email' => 'ai-business@test.local', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $client = Client::query()->create(['company_id' => $company->id, 'code' => 'CLI-AI', 'legal_name' => 'Cliente']);
        $currency = Currency::query()->create(['company_id' => $company->id, 'code' => 'UF', 'name' => 'UF', 'symbol' => 'UF', 'minor_units' => 2, 'active' => true]);
        $contract = ContractType::query()->create(['company_id' => $company->id, 'domain' => 'commercial', 'code' => 'PROYECTO_CERRADO', 'name' => 'Proyecto cerrado', 'active' => true]);
        $project = Project::query()->create(['company_id' => $company->id, 'client_id' => $client->id, 'code' => 'PRY-AI', 'name' => 'Proyecto', 'contract_type_id' => $contract->id, 'sales_currency_id' => $currency->id, 'sale_net' => 180]);
        LegalParameter::query()->create(['company_id' => $company->id, 'parameter_code' => 'IVA', 'parameter_name' => 'IVA', 'valid_from' => '2026-01-01', 'value' => .19, 'unit' => '%', 'active' => true]);
        UfValue::query()->create(['company_id' => $company->id, 'value_date' => '2026-08-31', 'value' => 40000, 'active' => true]);
        DocumentType::query()->create(['company_id' => $company->id, 'domain' => 'sales', 'code' => 'FACTURA', 'name' => 'Factura', 'active' => true]);
        $milestone = ProjectBillingMilestone::query()->forceCreate(['company_id' => $company->id, 'project_id' => $project->id, 'sequence' => 1, 'name' => 'Hito', 'percentage' => 40]);
        return [$user, $project, $milestone];
    }
}
