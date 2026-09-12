<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Company;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\FinancialAgendaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialAgendaTest extends TestCase
{
    use RefreshDatabase;

    public function test_agenda_includes_open_items_excludes_closed_items_and_keeps_buckets_disjoint(): void
    {
        [$company, $client] = $this->companyAndClient('AGENDA-A');
        $other = Company::query()->create(['code' => 'AGENDA-B', 'name' => 'Otra empresa', 'status' => 'active']);

        $overdue = $this->sales($company, $client, 'ING-OVERDUE', '2026-09-01', 1000000);
        $this->sales($company, $client, 'ING-PARTIAL', '2026-09-14', 1000000);
        $this->sales($company, $client, 'ING-DRAFT', '2026-09-15', 1000000, 'Borrador');
        $this->sales($company, $client, 'ING-PAID', '2026-09-16', 1000000, 'Pagado');
        $this->sales($other, $client, 'ING-OTHER', '2026-09-15', 1000000);

        CashMovement::query()->create([
            'company_id' => $company->id,
            'code' => 'MOV-AGENDA-PARTIAL',
            'movement_type' => 'Ingreso',
            'source_document_type' => 'sales_document',
            'source_document_code' => 'ING-PARTIAL',
            'movement_date' => '2026-09-12',
            'income' => 400000,
            'status' => 'posted',
        ]);

        $this->expense($company, 'GST-NEXT', '2026-09-16', 700000);
        $this->expense($company, 'GST-PAID', '2026-09-17', 700000, 'Pagado');
        LegalObligation::query()->create([
            'company_id' => $company->id,
            'code' => 'OBL-NEXT',
            'obligation_type' => 'IVA',
            'period_date' => '2026-08-01',
            'due_date' => '2026-09-20',
            'estimated_amount' => 300000,
            'pending_amount' => 300000,
            'status' => 'Pendiente',
        ]);

        $agenda = app(FinancialAgendaService::class)->forCompany($company->id, Carbon::parse('2026-09-12'));
        $codes = $agenda['items']->pluck('code')->all();

        $this->assertSame(['ING-OVERDUE', 'ING-PARTIAL', 'GST-NEXT', 'OBL-NEXT'], $codes);
        $this->assertSame(600000.0, $agenda['items']->firstWhere('code', 'ING-PARTIAL')['balance']);
        $this->assertSame('Vencido', $agenda['items']->firstWhere('code', 'ING-OVERDUE')['priority']);
        $this->assertSame('7 días', $agenda['items']->firstWhere('code', 'GST-NEXT')['priority']);
        $this->assertSame('30 días', $agenda['items']->firstWhere('code', 'OBL-NEXT')['priority']);
        $this->assertCount(4, $agenda['items']);
        $this->assertSame(1000000.0, $agenda['summary']['receivable_overdue']);
        $this->assertSame(600000.0, $agenda['summary']['receivable_next_7']);
        $this->assertSame(700000.0, $agenda['summary']['payable_next_7']);
        $this->assertNotContains('ING-OTHER', $codes);
    }

    public function test_agenda_route_renders_clp_amounts_and_actionable_rows(): void
    {
        [$company, $client] = $this->companyAndClient('AGENDA-HTTP');
        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Agenda QA',
            'email' => 'agenda-qa@example.test',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);
        $this->sales($company, $client, 'ING-HTTP', '2026-09-12', 1234567);

        $response = $this->actingAs($user)->get(route('management.financial-agenda'));

        $response->assertOk()
            ->assertSee('Agenda financiera')
            ->assertSee('ING-HTTP')
            ->assertSee('1.234.567')
            ->assertSee('Hoy');
    }

    private function companyAndClient(string $code): array
    {
        $company = Company::query()->create(['code' => $code, 'name' => $code, 'status' => 'active']);
        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => $code . '-CLI',
            'legal_name' => 'Cliente Agenda',
            'status' => 'active',
        ]);

        return [$company, $client];
    }

    private function sales(Company $company, Client $client, string $code, string $dueDate, int $gross, string $status = 'Pendiente'): SalesDocument
    {
        return SalesDocument::query()->forceCreate([
            'company_id' => $company->id,
            'code' => $code,
            'client_id' => $client->id,
            'document_type' => 'Factura',
            'issue_date' => '2026-09-01',
            'due_date' => $dueDate,
            'projected_collection_date' => $dueDate,
            'net_amount' => $gross,
            'vat_amount' => 0,
            'gross_amount' => $gross,
            'collected_amount' => 0,
            'status' => $status,
            'is_voided' => false,
        ]);
    }

    private function expense(Company $company, string $code, string $dueDate, int $gross, string $status = 'Pendiente'): ExpenseDocument
    {
        return ExpenseDocument::query()->forceCreate([
            'company_id' => $company->id,
            'code' => $code,
            'vendor_name' => 'Proveedor Agenda',
            'issue_date' => '2026-09-01',
            'due_date' => $dueDate,
            'gross_amount' => $gross,
            'paid_amount' => 0,
            'payment_status' => $status,
        ]);
    }
}
