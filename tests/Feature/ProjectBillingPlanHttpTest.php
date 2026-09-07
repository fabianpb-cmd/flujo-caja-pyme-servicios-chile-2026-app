<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ContractType;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\LegalParameter;
use App\Models\RecordStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectBillingPlanHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_closed_project_with_milestones_is_atomic_and_invalid_plan_rolls_back(): void
    {
        [$company, $admin, $client, $currency, $closed, $active, $billing] = $this->fixtures();
        $payload = $this->projectPayload($client, $currency, $closed, $active, $billing, 'PRY-HTTP-CREATE');
        $payload['billing_milestones'] = [
            ['sequence' => 1, 'name' => 'Inicio', 'planned_invoice_date' => null, 'percentage' => 30, 'notes' => null],
            ['sequence' => 2, 'name' => 'Avance', 'planned_invoice_date' => null, 'percentage' => 40, 'notes' => null],
            ['sequence' => 3, 'name' => 'Cierre', 'planned_invoice_date' => null, 'percentage' => 30, 'notes' => null],
        ];

        $response = $this->actingAs($admin)->post(route('operational.store', 'projects'), $payload);

        $response->assertRedirect(route('operational.index', 'projects'));
        $project = Project::query()->where('company_id', $company->id)->where('code', 'PRY-HTTP-CREATE')->firstOrFail();
        $this->assertSame(3, $project->billingMilestones()->count());
        $this->assertSame([30.0, 40.0, 30.0], array_map(fn ($value) => (float) $value, $project->billingMilestones()->pluck('percentage')->toArray()));
        $this->assertSame($company->id, $project->company_id);
        $this->assertTrue($project->billingMilestones()->where('company_id', $company->id)->where('project_id', $project->id)->count() === 3);

        $invalid = $this->projectPayload($client, $currency, $closed, $active, $billing, 'PRY-HTTP-ROLLBACK');
        $invalid['billing_milestones'] = [
            ['sequence' => 1, 'name' => 'Uno', 'percentage' => 60],
            ['sequence' => 2, 'name' => 'Dos', 'percentage' => 41],
        ];
        $failed = $this->actingAs($admin)->post(route('operational.store', 'projects'), $invalid);

        $failed->assertStatus(302);
        $this->assertDatabaseMissing('projects', ['company_id' => $company->id, 'code' => 'PRY-HTTP-ROLLBACK']);
        $this->assertDatabaseCount('project_billing_milestones', 3);
    }

    public function test_update_closed_project_syncs_unbilled_milestones_through_http(): void
    {
        [$company, $admin, $client, $currency, $closed, $active, $billing] = $this->fixtures();
        $project = Project::query()->create(['company_id' => $company->id] + $this->projectPayload($client, $currency, $closed, $active, $billing, 'PRY-HTTP-UPDATE'));
        $first = ProjectBillingMilestone::query()->forceCreate(['company_id' => $company->id, 'project_id' => $project->id, 'sequence' => 1, 'name' => 'Inicial', 'percentage' => 40]);
        $second = ProjectBillingMilestone::query()->forceCreate(['company_id' => $company->id, 'project_id' => $project->id, 'sequence' => 2, 'name' => 'Eliminar', 'percentage' => 20]);

        $payload = $this->projectPayload($client, $currency, $closed, $active, $billing, $project->code);
        $payload['billing_milestones'] = [
            ['id' => $first->id, 'sequence' => 1, 'name' => 'Inicial actualizado', 'percentage' => 30, 'notes' => 'editado'],
            ['sequence' => 3, 'name' => 'Nuevo', 'percentage' => 70, 'notes' => 'agregado'],
        ];

        $response = $this->actingAs($admin)->put(route('operational.update', ['projects', $project->id]), $payload);

        $response->assertStatus(302);
        $this->assertDatabaseHas('project_billing_milestones', ['id' => $first->id, 'name' => 'Inicial actualizado', 'percentage' => 30]);
        $this->assertDatabaseMissing('project_billing_milestones', ['id' => $second->id]);
        $this->assertDatabaseHas('project_billing_milestones', ['project_id' => $project->id, 'sequence' => 3, 'name' => 'Nuevo', 'percentage' => 70]);
        $this->assertSame(2, ProjectBillingMilestone::query()->where('project_id', $project->id)->count());
    }

    public function test_billing_plan_percentage_script_has_csp_nonce(): void
    {
        [$company, $admin, $client, $currency, $closed, $active, $billing] = $this->fixtures();
        $project = Project::query()->create(['company_id' => $company->id] + $this->projectPayload($client, $currency, $closed, $active, $billing, 'PRY-HTTP-CSP'));

        $response = $this->actingAs($admin)->get(route('operational.edit', ['projects', $project->id]));

        $response->assertOk();
        $response->assertSee('data-project-billing-plan', false);
        $this->assertMatchesRegularExpression('/data-project-billing-plan[\s\S]*<script nonce="[^"]+"[\s\S]*syncPercentages/', $response->getContent());
    }

    private function fixtures(): array
    {
        $company = Company::query()->create(['code' => 'CMP-HTTP-PLAN', 'name' => 'Empresa HTTP Plan', 'status' => 'active']);
        $admin = User::query()->create(['company_id' => $company->id, 'name' => 'Admin Plan', 'email' => 'plan-'.$company->id.'@test.local', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $client = Client::query()->create(['company_id' => $company->id, 'code' => 'CLI-HTTP-PLAN', 'legal_name' => 'Cliente HTTP Plan']);
        $currency = Currency::query()->create(['company_id' => $company->id, 'code' => 'UF', 'name' => 'Unidad de fomento', 'symbol' => 'UF', 'minor_units' => 2, 'active' => true]);
        $closed = ContractType::query()->create(['company_id' => $company->id, 'domain' => 'commercial', 'code' => 'PROYECTO_CERRADO', 'name' => 'Proyecto cerrado', 'active' => true]);
        $active = RecordStatus::query()->create(['company_id' => $company->id, 'domain' => 'project', 'code' => 'active', 'name' => 'Activo', 'active' => true]);
        $billing = RecordStatus::query()->create(['company_id' => $company->id, 'domain' => 'billing', 'code' => 'pending', 'name' => 'Pendiente', 'active' => true]);
        LegalParameter::query()->create(['company_id' => $company->id, 'parameter_code' => 'IVA', 'parameter_name' => 'IVA', 'valid_from' => '2026-01-01', 'value' => 0.19, 'unit' => '%', 'active' => true]);

        return [$company, $admin, $client, $currency, $closed, $active, $billing];
    }

    private function projectPayload(Client $client, Currency $currency, ContractType $closed, RecordStatus $active, RecordStatus $billing, string $code): array
    {
        return ['code' => $code, 'client_id' => $client->id, 'sales_currency_id' => $currency->id, 'name' => 'Proyecto HTTP', 'contract_type_id' => $closed->id, 'sale_net' => 180, 'project_status_id' => $active->id, 'billing_status_id' => $billing->id];
    }
}
