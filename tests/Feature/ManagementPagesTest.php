<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\LegalParameter;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\Budget;
use App\Models\Scenario;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_pages_render_for_authenticated_user(): void
    {
        $company = Company::query()->create([
            'code' => 'CMP-MGT',
            'name' => 'Empresa Gestión',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Admin Gestión',
            'email' => 'gestion@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        CashAccount::query()->create([
            'company_id' => $company->id,
            'code' => 'BANK-MGT',
            'name' => 'Banco Gestión',
            'currency' => 'CLP',
            'opening_balance' => 1000000,
            'is_active' => true,
        ]);

        foreach ([
            ['margin_minimum', '0.15'],
            ['active_scenario', 'BASE'],
            ['obligation_due_day', '13'],
        ] as [$key, $value]) {
            CompanySetting::query()->create([
                'company_id' => $company->id,
                'setting_key' => $key,
                'setting_value' => $value,
            ]);
        }

        foreach ([
            ['IVA', 0.19, '2026-01-01', '2027-12-31'],
            ['RETENCION_HONORARIOS', 0.1525, '2026-01-01', '2026-12-31'],
            ['RETENCION_HONORARIOS', 0.16, '2027-01-01', '2027-12-31'],
            ['PROVISION_VACACIONES', 0.0833, '2026-01-01', '2027-12-31'],
            ['PPM_RATE', 0.01, '2026-01-01', '2027-12-31'],
            ['COTIZACION_EMPLEADOR', 0.01, '2026-01-01', '2027-12-31'],
            ['SIS_RATE', 0.0154, '2026-01-01', '2027-12-31'],
            ['AFC_RATE', 0.006, '2026-01-01', '2027-12-31'],
            ['IMPUESTO_SEGUNDA_CATEGORIA_RATE', 0.0, '2026-01-01', '2027-12-31'],
        ] as [$code, $value, $from, $to]) {
            LegalParameter::query()->create([
                'company_id' => $company->id,
                'parameter_code' => $code,
                'parameter_name' => $code,
                'valid_from' => $from,
                'valid_to' => $to,
                'value' => $value,
                'unit' => '%',
            ]);
        }

        Scenario::query()->create([
            'company_id' => $company->id,
            'code' => 'BASE',
            'name' => 'Base',
            'sales_factor' => 1,
            'cost_factor' => 1,
            'collection_delay_days' => 0,
            'is_active' => true,
        ]);

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-MGT',
            'legal_name' => 'Cliente Gestión',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-001',
            'name' => 'Proyecto Gestión',
            'sale_net' => 5000000,
            'sale_total' => 5950000,
            'contracted_hourly_rate' => 50000,
            'project_status' => 'active',
            'billing_status' => 'pending',
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-MGT',
            'name' => 'Persona Gestión',
            'modality' => 'Dependiente mensual',
            'monthly_value' => 1000000,
            'status' => 'active',
        ]);

        PayrollRecord::query()->create([
            'company_id' => $company->id,
            'code' => 'REM-MGT',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'base_salary' => 1000000,
            'taxable_amount' => 1000000,
            'employer_cost' => 1100000,
            'vacation_provision' => 83300,
            'net_pay' => 900000,
            'status' => 'Pendiente',
        ]);

        SalesDocument::query()->create([
            'company_id' => $company->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'code' => 'ING-MGT',
            'document_type' => 'Factura',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'projected_collection_date' => '2026-08-31',
            'net_amount' => 1000000,
            'vat_amount' => 190000,
            'gross_amount' => 1190000,
            'payment_probability' => null,
            'status' => 'Pendiente',
            'is_voided' => false,
        ]);

        Budget::query()->create([
            'company_id' => $company->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'revenue_budget' => 1200000,
            'personnel_budget' => 800000,
            'other_direct_budget' => 150000,
            'legal_budget' => 50000,
            'other_indirect_budget' => 25000,
        ]);

        $this->actingAs($user);

        $this->get(route('dashboard'))->assertOk()->assertSee('Dashboard ejecutivo');
        $this->get(route('management.flows'))->assertOk()->assertSee('Flujo mensual y semanal');
        $this->get(route('management.obligations'))->assertOk()->assertSee('Obligaciones');
        $this->get(route('management.profitability'))->assertOk()->assertSee('Rentabilidad por proyecto');
        $this->get(route('management.budgets'))
            ->assertOk()
            ->assertSee('Presupuesto')
            ->assertSee('real reconocido')
            ->assertSee('Indirectos Ppto');
    }
}
