<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Company;
use App\Models\ExpenseDocument;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_receivables_only_lists_open_documents_from_the_active_company(): void
    {
        [$company, $admin] = $this->companyWithAdmin('NAV-CXC');
        $client = $this->client($company, 'Cliente CxC');

        $open = $this->salesDocument($company, $client, 'ING-OPEN', 'Pendiente', 1000);
        $partial = $this->salesDocument($company, $client, 'ING-PARTIAL', 'Parcial', 1000);
        $this->postedMovement($company, 'sales_document', $partial->code, 400, 0);
        $paid = $this->salesDocument($company, $client, 'ING-PAID', 'Pendiente', 1000);
        $this->postedMovement($company, 'sales_document', $paid->code, 1000, 0);
        $this->salesDocument($company, $client, 'ING-DRAFT', 'Borrador', 1000);
        $voided = $this->salesDocument($company, $client, 'ING-VOIDED', 'Pendiente', 1000);
        $voided->forceFill(['is_voided' => true])->save();

        [$otherCompany] = $this->companyWithAdmin('NAV-CXC-OTHER');
        $this->salesDocument($otherCompany, $this->client($otherCompany, 'Cliente ajeno'), 'ING-OTHER', 'Pendiente', 1000);

        $response = $this->actingAs($admin)->get(route('receivables.index'));

        $response->assertOk()
            ->assertSee('Cuentas por cobrar')
            ->assertSee('Ventas / Cuentas por cobrar')
            ->assertSee($open->code)
            ->assertSee($partial->code)
            ->assertDontSee('ING-PAID')
            ->assertDontSee('ING-DRAFT')
            ->assertDontSee('ING-VOIDED')
            ->assertDontSee('ING-OTHER');
        $this->assertMatchesRegularExpression(
            '/data-sidebar-label="Cuentas por cobrar"[^>]*aria-current="page"/',
            $response->getContent(),
        );
    }

    public function test_payables_only_lists_open_documents_from_the_active_company(): void
    {
        [$company, $admin] = $this->companyWithAdmin('NAV-CXP');

        $open = $this->expenseDocument($company, 'EGR-OPEN', 'Pendiente', 1000);
        $partial = $this->expenseDocument($company, 'EGR-PARTIAL', 'Parcial', 1000);
        $this->postedMovement($company, 'expense_document', $partial->code, 0, 400);
        $paid = $this->expenseDocument($company, 'EGR-PAID', 'Pendiente', 1000);
        $this->postedMovement($company, 'expense_document', $paid->code, 0, 1000);
        $this->expenseDocument($company, 'EGR-DRAFT', 'Borrador', 1000);
        $this->expenseDocument($company, 'EGR-VOIDED', 'Anulado', 1000);

        [$otherCompany] = $this->companyWithAdmin('NAV-CXP-OTHER');
        $this->expenseDocument($otherCompany, 'EGR-OTHER', 'Pendiente', 1000);

        $response = $this->actingAs($admin)->get(route('payables.index'));

        $response->assertOk()
            ->assertSee('Cuentas por pagar')
            ->assertSee('Gastos / Cuentas por pagar')
            ->assertSee($open->code)
            ->assertSee($partial->code)
            ->assertDontSee('EGR-PAID')
            ->assertDontSee('EGR-DRAFT')
            ->assertDontSee('EGR-VOIDED')
            ->assertDontSee('EGR-OTHER');
        $this->assertMatchesRegularExpression(
            '/data-sidebar-label="Cuentas por pagar"[^>]*aria-current="page"/',
            $response->getContent(),
        );
    }

    public function test_treasury_sidebar_uses_the_operational_order(): void
    {
        [$company, $admin] = $this->companyWithAdmin('NAV-TREASURY');

        $html = $this->actingAs($admin)->get(route('dashboard'))->getContent();
        $labels = [
            'data-sidebar-label="Cuentas"',
            'data-sidebar-label="Movimientos de caja"',
            'data-sidebar-label="Cartolas bancarias"',
            'data-sidebar-label="Regularización bancaria"',
            'data-sidebar-label="Conciliación bancaria"',
        ];
        $positions = array_map(fn (string $label): int => strpos($html, $label), $labels);

        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
    }

    private function companyWithAdmin(string $code): array
    {
        $company = Company::query()->create([
            'code' => $code,
            'name' => 'Empresa '.$code,
            'status' => 'active',
        ]);
        $admin = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Admin '.$code,
            'email' => strtolower($code).'@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        return [$company, $admin];
    }

    private function client(Company $company, string $name): Client
    {
        return Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-'.$company->id.'-'.str_replace(' ', '-', $name),
            'legal_name' => $name,
            'status' => 'active',
        ]);
    }

    private function salesDocument(Company $company, Client $client, string $code, string $status, float $gross): SalesDocument
    {
        return SalesDocument::query()->forceCreate([
            'company_id' => $company->id,
            'code' => $code,
            'client_id' => $client->id,
            'document_type' => 'Factura',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'net_amount' => $gross,
            'vat_amount' => 0,
            'gross_amount' => $gross,
            'collected_amount' => 0,
            'status' => $status,
            'is_voided' => false,
        ]);
    }

    private function expenseDocument(Company $company, string $code, string $status, float $gross): ExpenseDocument
    {
        return ExpenseDocument::query()->forceCreate([
            'company_id' => $company->id,
            'code' => $code,
            'vendor_name' => 'Proveedor '.$code,
            'document_type' => 'Factura compra',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'net_amount' => $gross,
            'vat_amount' => 0,
            'recoverable_vat_amount' => 0,
            'gross_amount' => $gross,
            'paid_amount' => 0,
            'payment_status' => $status,
        ]);
    }

    private function postedMovement(Company $company, string $sourceType, string $sourceCode, float $income, float $expense): void
    {
        CashMovement::query()->forceCreate([
            'company_id' => $company->id,
            'code' => 'MOV-'.$sourceCode,
            'movement_type' => $income > 0 ? 'Ingreso' : 'Egreso',
            'source_document_type' => $sourceType,
            'source_document_code' => $sourceCode,
            'movement_date' => '2026-09-05',
            'income' => $income,
            'expense' => $expense,
            'status' => 'posted',
        ]);
    }
}
