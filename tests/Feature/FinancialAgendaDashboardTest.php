<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\CashAccount;
use App\Models\CompanySetting;
use App\Models\Scenario;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialAgendaDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_surfaces_financial_agenda_summary_and_maximum_eight_rows(): void
    {
        Carbon::setTestNow('2026-09-12');
        $company = Company::query()->create(['code' => 'DASH-AGENDA', 'name' => 'Dashboard Agenda', 'status' => 'active']);
        $client = Client::query()->create(['company_id' => $company->id, 'code' => 'DASH-CLI', 'legal_name' => 'Cliente Dashboard', 'status' => 'active']);
        $user = User::query()->create(['company_id' => $company->id, 'name' => 'Dashboard QA', 'email' => 'dashboard-agenda@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        CashAccount::query()->create(['company_id' => $company->id, 'code' => 'DASH-BANK', 'name' => 'Banco Dashboard', 'currency' => 'CLP', 'opening_balance' => 0, 'is_active' => true]);
        CompanySetting::query()->create(['company_id' => $company->id, 'setting_key' => 'active_scenario', 'setting_value' => 'BASE']);
        Scenario::query()->create(['company_id' => $company->id, 'code' => 'BASE', 'name' => 'Base', 'sales_factor' => 1, 'cost_factor' => 1, 'collection_delay_days' => 0, 'is_active' => true]);

        foreach (range(1, 9) as $index) {
            SalesDocument::query()->forceCreate([
                'company_id' => $company->id,
                'code' => 'DASH-ING-' . $index,
                'client_id' => $client->id,
                'document_type' => 'Factura',
                'issue_date' => '2026-09-01',
                'due_date' => $index === 1 ? '2026-09-01' : '2026-09-15',
                'net_amount' => 1000000,
                'vat_amount' => 0,
                'gross_amount' => 1000000,
                'collected_amount' => 0,
                'status' => 'Pendiente',
                'is_voided' => false,
            ]);
        }

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Qué requiere atención')
            ->assertSee('Por cobrar vencido')
            ->assertSee('Por pagar próximos 7 días')
            ->assertSee('Ver agenda completa')
            ->assertSee('DASH-ING-1')
            ->assertSee('1.000.000')
            ->assertSee(route('management.financial-agenda'), false);

        $this->assertSame(8, substr_count($response->getContent(), 'DASH-ING-'));
    }
}
