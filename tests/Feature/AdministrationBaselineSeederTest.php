<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ExpenseDocument;
use App\Models\HealthSystem;
use App\Models\LegalParameter;
use App\Models\PaymentMethod;
use App\Models\ProjectManager;
use App\Models\UtmValue;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\SystemCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdministrationBaselineSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_and_operational_seeders_are_idempotent(): void
    {
        $company = Company::query()->create(['code' => 'CMP-ADM', 'name' => 'Empresa ADM', 'status' => 'active']);

        $this->seed(SystemCatalogSeeder::class);
        $this->seed(OperationalCatalogSeeder::class);

        $baseline = [
            'afps' => \App\Models\Afp::count(),
            'payment_methods' => PaymentMethod::query()->where('company_id', $company->id)->count(),
            'health_systems' => HealthSystem::query()->where('company_id', $company->id)->count(),
            'scenarios' => \App\Models\Scenario::query()->where('company_id', $company->id)->count(),
            'utm_values' => UtmValue::query()->where('company_id', $company->id)->count(),
            'exchange_rates' => ExchangeRate::query()->where('company_id', $company->id)->count(),
        ];

        $this->seed(SystemCatalogSeeder::class);
        $this->seed(OperationalCatalogSeeder::class);

        $this->assertSame($baseline['afps'], \App\Models\Afp::count());
        $this->assertSame($baseline['payment_methods'], PaymentMethod::query()->where('company_id', $company->id)->count());
        $this->assertSame($baseline['health_systems'], HealthSystem::query()->where('company_id', $company->id)->count());
        $this->assertSame($baseline['scenarios'], \App\Models\Scenario::query()->where('company_id', $company->id)->count());
        $this->assertSame($baseline['utm_values'], UtmValue::query()->where('company_id', $company->id)->count());
        $this->assertSame($baseline['exchange_rates'], ExchangeRate::query()->where('company_id', $company->id)->count());
    }

    public function test_operational_catalog_seeder_does_not_overwrite_existing_customization(): void
    {
        $company = Company::query()->create(['code' => 'CMP-CUSTOM', 'name' => 'Empresa Custom', 'status' => 'active']);

        $this->seed(OperationalCatalogSeeder::class);

        $method = PaymentMethod::query()->where('company_id', $company->id)->where('code', 'TRANSFERENCIA')->firstOrFail();
        $method->update(['name' => 'Transferencia personalizada', 'active' => false]);

        $this->seed(OperationalCatalogSeeder::class);

        $method->refresh();
        $this->assertSame('Transferencia personalizada', $method->name);
        $this->assertFalse($method->active);
    }

    public function test_operational_catalog_seeder_does_not_overwrite_company_parameter_customization(): void
    {
        $company = Company::query()->create(['code' => 'CMP-PARAM', 'name' => 'Empresa Param', 'status' => 'active']);

        $this->seed(OperationalCatalogSeeder::class);

        $ppm = CompanySetting::query()->where('company_id', $company->id)->where('setting_key', 'ppm_rate')->firstOrFail();
        $ppm->update(['setting_value' => '0.003500', 'active' => false]);

        $this->seed(OperationalCatalogSeeder::class);

        $ppm->refresh();
        $this->assertSame('0.003500', $ppm->setting_value);
        $this->assertFalse($ppm->active);
    }

    public function test_demo_data_is_separate_from_system_seeders(): void
    {
        Company::query()->create(['code' => 'CMP-NODEMO', 'name' => 'Empresa Sin Demo', 'status' => 'active']);

        $this->seed(SystemCatalogSeeder::class);
        $this->seed(OperationalCatalogSeeder::class);

        $this->assertDatabaseMissing('project_managers', ['name' => 'Jaime']);
        $this->assertDatabaseMissing('positions', ['name' => 'IRIS Consultor Senior']);

        $this->seed(DemoDataSeeder::class);

        $this->assertDatabaseHas('project_managers', ['name' => 'Jaime']);
        $this->assertDatabaseHas('positions', ['name' => 'IRIS Consultor Senior']);
    }

    public function test_catalog_seeders_do_not_modify_derived_financial_statuses(): void
    {
        $company = Company::query()->create(['code' => 'CMP-FIN', 'name' => 'Empresa Fin', 'status' => 'active']);

        $expense = ExpenseDocument::query()->create([
            'company_id' => $company->id,
            'code' => 'EGR-STATUS',
            'issue_date' => '2026-08-01',
            'net_amount' => 1000,
            'gross_amount' => 1000,
            'payment_status' => 'Pendiente',
        ]);

        $this->seed(SystemCatalogSeeder::class);
        $this->seed(OperationalCatalogSeeder::class);

        $this->assertSame('Pendiente', $expense->refresh()->payment_status);
    }

    public function test_operational_catalog_seeder_creates_parameter_indicator_and_exchange_baseline(): void
    {
        $company = Company::query()->create(['code' => 'CMP-BASE', 'name' => 'Empresa Base', 'status' => 'active']);

        $this->seed(SystemCatalogSeeder::class);
        $this->seed(OperationalCatalogSeeder::class);

        $this->assertTrue(
            LegalParameter::query()
                ->where('company_id', $company->id)
                ->where('parameter_code', 'RETENCION_HONORARIOS')
                ->whereDate('valid_from', '2028-01-01')
                ->where('unit', 'PERCENT')
                ->exists()
        );
        $this->assertDatabaseHas('utm_values', [
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]);

        $usd = Currency::query()->where('company_id', $company->id)->where('code', 'USD')->firstOrFail();
        $this->assertTrue(
            ExchangeRate::query()
                ->where('company_id', $company->id)
                ->where('currency_id', $usd->id)
                ->whereDate('rate_date', '2026-08-01')
                ->exists()
        );
    }

    public function test_admin_can_access_parameter_and_indicator_maintainers(): void
    {
        $company = Company::query()->create(['code' => 'CMP-UI-ADM', 'name' => 'Empresa UI ADM', 'status' => 'active']);
        $admin = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => 'admin-params@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        $this->seed(SystemCatalogSeeder::class);
        $this->seed(OperationalCatalogSeeder::class);

        foreach (['legal-parameters', 'uf-values', 'utm-values', 'exchange-rates', 'afp-rates', 'income-tax-brackets', 'company-settings'] as $resource) {
            $this->actingAs($admin)->get(route('operational.index', $resource))->assertOk();
        }
    }
}
