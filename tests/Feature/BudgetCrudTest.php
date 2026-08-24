<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Client;
use App\Models\Company;
use App\Models\Project;
use App\Models\Scenario;
use App\Models\User;
use App\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_can_be_created_updated_and_displayed_through_operational_crud(): void
    {
        $company = Company::query()->create([
            'code' => 'CMP-BGT-CRUD',
            'name' => 'Empresa Budget CRUD',
            'status' => 'active',
        ]);

        app(CatalogService::class)->seedDefaultsForCompany($company->id);

        $admin = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Admin Budget',
            'email' => 'budget-crud@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-BGT-CRUD',
            'legal_name' => 'Cliente Budget',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-BGT-CRUD',
            'name' => 'Proyecto Budget',
            'sale_net' => 2000000,
            'sale_total' => 2380000,
            'project_status' => 'active',
            'billing_status' => 'pending',
        ]);

        $scenario = Scenario::query()->create([
            'company_id' => $company->id,
            'code' => 'BASE',
            'name' => 'Base',
            'sales_factor' => 1,
            'cost_factor' => 1,
            'collection_delay_days' => 0,
            'is_active' => true,
        ]);

        $payload = [
            'project_id' => $project->id,
            'scenario_id' => $scenario->id,
            'period_date' => '2026-08-01',
            'revenue_budget' => 1500000,
            'personnel_budget' => 900000,
            'other_direct_budget' => 120000,
            'legal_budget' => 40000,
            'other_indirect_budget' => 30000,
            'notes' => 'Plan agosto',
        ];

        $store = $this->actingAs($admin)->post(route('operational.store', 'budgets'), $payload);
        $store->assertRedirect(route('operational.index', 'budgets'));

        $budget = Budget::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame('2026-08-01', $budget->period_date->toDateString());
        $this->assertSame('900000.00', $budget->personnel_budget);
        $this->assertSame('30000.00', $budget->other_indirect_budget);

        $update = $this->actingAs($admin)->put(route('operational.update', ['budgets', $budget->id]), array_merge($payload, [
            'personnel_budget' => 950000,
            'notes' => 'Plan agosto ajustado',
        ]));

        $update->assertRedirect(route('operational.show', ['budgets', $budget->id]));

        $show = $this->actingAs($admin)->get(route('operational.show', ['budgets', $budget->id]));
        $show->assertOk();
        $show->assertSee('Plan agosto ajustado');
        $show->assertSee('950.000', false);
    }

    public function test_budget_rejects_exact_duplicates_and_values_that_exceed_decimal_limits(): void
    {
        $company = Company::query()->create([
            'code' => 'CMP-BGT-VAL',
            'name' => 'Empresa Budget Validaciones',
            'status' => 'active',
        ]);

        app(CatalogService::class)->seedDefaultsForCompany($company->id);

        $admin = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Admin Budget Validaciones',
            'email' => 'budget-validaciones@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-BGT-VAL',
            'legal_name' => 'Cliente Budget Validaciones',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-BGT-VAL',
            'name' => 'Proyecto Budget Validaciones',
            'sale_net' => 3000000,
            'sale_total' => 3570000,
            'project_status' => 'active',
            'billing_status' => 'pending',
        ]);

        $payload = [
            'project_id' => $project->id,
            'scenario_id' => null,
            'period_date' => '2026-09-01',
            'revenue_budget' => 1200000,
            'personnel_budget' => 800000,
            'other_direct_budget' => 100000,
            'legal_budget' => 40000,
            'other_indirect_budget' => 30000,
            'notes' => null,
        ];

        $this->actingAs($admin)->post(route('operational.store', 'budgets'), $payload)
            ->assertRedirect(route('operational.index', 'budgets'));

        $duplicate = $this->actingAs($admin)->from(route('operational.create', 'budgets'))
            ->post(route('operational.store', 'budgets'), $payload);

        $duplicate->assertSessionHasErrors('period_date');

        $overflow = $this->actingAs($admin)->from(route('operational.create', 'budgets'))
            ->post(route('operational.store', 'budgets'), array_merge($payload, [
                'period_date' => '2026-10-01',
                'revenue_budget' => '1234567890123456789.99',
            ]));

        $overflow->assertSessionHasErrors('revenue_budget');
    }
}
