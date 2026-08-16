<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Project;
use App\Models\Scenario;
use App\Models\UfValue;
use App\Models\User;
use App\Services\LegalParameterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardUfFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_responds_ok_with_exact_uf_for_today(): void
    {
        Carbon::setTestNow('2026-08-10 10:00:00');
        $user = $this->seedDashboardBase();
        $company = Company::query()->firstOrFail();

        UfValue::query()->create([
            'company_id' => $company->id,
            'value_date' => '2026-08-10',
            'value' => 40844.79,
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('UF oficial disponible para la fecha actual')
            ->assertSee('UF 40.844,79');
    }

    public function test_dashboard_responds_ok_with_latest_uf_when_exact_missing(): void
    {
        Carbon::setTestNow('2026-08-10 10:00:00');
        $user = $this->seedDashboardBase();
        $company = Company::query()->firstOrFail();

        UfValue::query()->create([
            'company_id' => $company->id,
            'value_date' => '2026-08-09',
            'value' => 40840.11,
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Última UF oficial disponible: 09/08/2026')
            ->assertSee('UF del 10/08/2026 aún no disponible');
    }

    public function test_dashboard_responds_ok_when_no_uf_exists(): void
    {
        Carbon::setTestNow('2026-08-10 10:00:00');
        $user = $this->seedDashboardBase();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('UF no disponible.');
    }

    public function test_uf_helpers_keep_exact_and_fallback_behaviour_separated(): void
    {
        $company = Company::query()->create([
            'code' => 'CMP-UF',
            'name' => 'Empresa UF',
            'status' => 'active',
        ]);

        UfValue::query()->create([
            'company_id' => $company->id,
            'value_date' => '2026-08-09',
            'value' => 40840.11,
            'active' => true,
        ]);

        $service = app(LegalParameterService::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Falta UF oficial para 2026-08-10.');
        $service->ufValueExact($company->id, '2026-08-10');
    }

    public function test_latest_uf_helper_returns_previous_day_without_lying_about_exactness(): void
    {
        $company = Company::query()->create([
            'code' => 'CMP-UF2',
            'name' => 'Empresa UF 2',
            'status' => 'active',
        ]);

        UfValue::query()->create([
            'company_id' => $company->id,
            'value_date' => '2026-08-09',
            'value' => 40840.11,
            'active' => true,
        ]);

        $service = app(LegalParameterService::class);
        $info = $service->latestOfficialUfOnOrBefore($company->id, '2026-08-10');

        $this->assertSame('40840.1100', $info['value']);
        $this->assertSame('2026-08-09', $info['value_date']);
        $this->assertFalse($info['is_exact']);
    }

    private function seedDashboardBase(): User
    {
        $company = Company::query()->create([
            'code' => 'CMP-DASH-UF',
            'name' => 'Empresa Dashboard UF',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Admin UF',
            'email' => 'uf@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        CashAccount::query()->create([
            'company_id' => $company->id,
            'code' => 'BANK-UF',
            'name' => 'Banco UF',
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
            'code' => 'CLI-UF',
            'legal_name' => 'Cliente UF',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        $ufCurrency = Currency::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'UF'],
            [
                'name' => 'Unidad de Fomento',
                'symbol' => 'UF',
                'minor_units' => 2,
                'active' => true,
                'is_base_currency' => false,
                'sort_order' => 20,
            ]
        );

        Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $ufCurrency->id,
            'code' => 'PRY-UF',
            'name' => 'Proyecto UF',
            'sale_net' => 10,
            'sale_total' => 10,
            'project_status' => 'active',
            'billing_status' => 'pending',
        ]);

        return $user;
    }
}
