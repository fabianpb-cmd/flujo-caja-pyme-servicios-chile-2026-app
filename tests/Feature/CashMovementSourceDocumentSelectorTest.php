<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashMovementType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\PaymentMethod;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\ReceivablesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashMovementSourceDocumentSelectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_movement_form_renders_dependent_source_document_selector_with_usable_documents(): void
    {
        [$company, $admin] = $this->companyWithAdmin('CMP-CASH-A');
        [$client, $project] = $this->clientAndProject($company);

        $invoice = $this->salesDocument($company, $client, $project, 'ING-SEL-001', 388844);
        $expense = $this->expenseDocument($company, $project, 'EGR-SEL-001', 210000);
        $payroll = $this->payrollRecord($company, $project, 'REM-SEL-001', 150000);
        $obligation = $this->legalObligation($company, 'OBL-SEL-001', 99000);
        [$otherCompany] = $this->companyWithAdmin('CMP-CASH-B');
        $otherClient = Client::query()->create([
            'company_id' => $otherCompany->id,
            'code' => 'CLI-OTHER',
            'legal_name' => 'Otra Empresa',
        ]);
        SalesDocument::query()->create([
            'company_id' => $otherCompany->id,
            'code' => 'ING-OTHER-001',
            'client_id' => $otherClient->id,
            'document_type' => 'Factura',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'gross_amount' => 1000,
            'status' => 'Pendiente',
            'is_voided' => false,
        ]);

        $response = $this->actingAs($admin)->get(route('operational.create', 'cash-movements'));
        $html = $response->getContent();

        $response->assertOk();
        $response->assertSee('Tipo documento origen');
        $response->assertSee('data-source-document-select="true"', false);
        $response->assertSee('data-parent-field="source_document_type"', false);
        $response->assertSee('value="'.$invoice->code.'"', false);
        $response->assertSee('Clínica Los Andes');
        $response->assertSee('Kardex');
        $response->assertSee('$ 388.844');
        $response->assertSee('Pendiente');
        $this->assertStringContainsString('value="'.$invoice->code.'"', $html);
        $this->assertStringContainsString('data-source-document-type="sales_document"', $html);
        $this->assertStringContainsString('data-counterparty-name="Clínica Los Andes"', $html);
        $this->assertStringContainsString('data-project-id="'.$project->id.'"', $html);
        $this->assertStringContainsString('data-suggested-income="388844.00"', $html);
        $response->assertSee('value="'.$expense->code.'"', false);
        $this->assertStringContainsString('data-source-document-type="expense_document"', $html);
        $response->assertSee('value="'.$payroll->code.'"', false);
        $this->assertStringContainsString('data-source-document-type="payroll_record"', $html);
        $response->assertSee('value="'.$obligation->code.'"', false);
        $this->assertStringContainsString('data-source-document-type="legal_obligation"', $html);
        $response->assertDontSee('ING-OTHER-001');

        $this->assertStringContainsString("cashSourceTypeSelect.addEventListener('change'", $html);
        $this->assertStringContainsString('resetCashSourceDerivedFields', $html);
        $this->assertStringContainsString('option.dataset.suggestedIncome', $html);
        $this->assertStringContainsString('option.dataset.suggestedExpense', $html);
    }

    public function test_cash_movements_use_functional_codes_allow_partial_and_total_payments_and_validate_invalid_documents(): void
    {
        [$company, $admin] = $this->companyWithAdmin('CMP-CASH-C');
        [$client, $project] = $this->clientAndProject($company);
        $invoice = $this->salesDocument($company, $client, $project, 'ING-PAY-001', 1000);
        $movementTypeId = CashMovementType::query()->where('company_id', $company->id)->valueOrFail('id');
        $paymentMethodId = PaymentMethod::query()->where('company_id', $company->id)->valueOrFail('id');
        $cashAccount = $this->cashAccount($company);

        $partial = $this->actingAs($admin)->post(route('operational.store', 'cash-movements'), [
            'movement_type_id' => $movementTypeId,
            'source_document_type' => 'sales_document',
            'source_document_code' => $invoice->code,
            'counterparty_name' => 'Clínica Los Andes',
            'project_id' => $project->id,
            'movement_date' => '2026-09-15',
            'income' => 400,
            'expense' => 0,
            'payment_method_id' => $paymentMethodId,
            'cash_account_id' => $cashAccount->id,
            'status' => 'posted',
        ]);

        $partial->assertRedirect(route('operational.index', 'cash-movements'));
        $this->assertDatabaseHas('cash_movements', [
            'source_document_type' => 'sales_document',
            'source_document_code' => 'ING-PAY-001',
            'income' => 400,
        ]);
        $this->assertSame(600.0, app(ReceivablesService::class)->balance($invoice->refresh()));
        $this->assertSame('Parcial', $invoice->refresh()->status);

        $total = $this->actingAs($admin)->post(route('operational.store', 'cash-movements'), [
            'movement_type_id' => $movementTypeId,
            'source_document_type' => 'sales_document',
            'source_document_code' => $invoice->code,
            'counterparty_name' => 'Clínica Los Andes',
            'project_id' => $project->id,
            'movement_date' => '2026-09-20',
            'income' => 600,
            'expense' => 0,
            'payment_method_id' => $paymentMethodId,
            'cash_account_id' => $cashAccount->id,
            'status' => 'posted',
        ]);

        $total->assertRedirect(route('operational.index', 'cash-movements'));
        $this->assertSame(0.0, app(ReceivablesService::class)->balance($invoice->refresh()));
        $this->assertSame('Pagado', $invoice->refresh()->status);

        $invalid = $this->actingAs($admin)->from(route('operational.create', 'cash-movements'))->post(route('operational.store', 'cash-movements'), [
            'movement_type_id' => $movementTypeId,
            'source_document_type' => 'sales_document',
            'source_document_code' => '1',
            'counterparty_name' => 'Dato inválido',
            'movement_date' => '2026-09-21',
            'income' => 1,
            'expense' => 0,
            'payment_method_id' => $paymentMethodId,
            'cash_account_id' => $cashAccount->id,
            'status' => 'posted',
        ]);

        $invalid
            ->assertRedirect(route('operational.create', 'cash-movements'))
            ->assertSessionHasErrors([
                'cash_movement' => 'Seleccione una factura/ingreso vigente con saldo pendiente.',
            ]);
        $this->assertSame(2, CashMovement::query()->where('source_document_code', 'ING-PAY-001')->count());
        $this->assertSame(0, CashMovement::query()->where('source_document_code', '1')->count());
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

        app(CatalogService::class)->seedDefaultsForCompany($company->id);

        return [$company, $admin];
    }

    private function clientAndProject(Company $company): array
    {
        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-'.$company->id,
            'legal_name' => 'Clínica Los Andes',
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-'.$company->id,
            'name' => 'Kardex',
            'sales_currency_id' => Currency::query()->where('company_id', $company->id)->where('code', 'CLP')->value('id'),
        ]);

        return [$client, $project];
    }

    private function salesDocument(Company $company, Client $client, Project $project, string $code, float $gross): SalesDocument
    {
        return SalesDocument::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'document_type' => 'Factura',
            'document_number' => '1',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'net_amount' => $gross,
            'vat_amount' => 0,
            'gross_amount' => $gross,
            'collected_amount' => 0,
            'status' => 'Pendiente',
            'is_voided' => false,
        ]);
    }

    private function expenseDocument(Company $company, Project $project, string $code, float $gross): ExpenseDocument
    {
        return ExpenseDocument::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'vendor_name' => 'Proveedor Uno',
            'project_id' => $project->id,
            'document_type' => 'Factura compra',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'net_amount' => $gross,
            'vat_amount' => 0,
            'recoverable_vat_amount' => 0,
            'gross_amount' => $gross,
            'paid_amount' => 0,
            'payment_status' => 'Pendiente',
        ]);
    }

    private function payrollRecord(Company $company, Project $project, string $code, float $netPay): PayrollRecord
    {
        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-'.$company->id,
            'name' => 'Persona Caja',
            'modality' => 'Honorarios mensual',
        ]);

        return PayrollRecord::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-09-01',
            'payment_date' => '2026-09-30',
            'net_pay' => $netPay,
            'calculation_status' => 'OK',
            'status' => 'Confirmado',
        ]);
    }

    private function legalObligation(Company $company, string $code, float $amount): LegalObligation
    {
        return LegalObligation::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'obligation_type' => 'IVA',
            'period_date' => '2026-09-01',
            'due_date' => '2026-10-13',
            'estimated_amount' => $amount,
            'pending_amount' => $amount,
            'status' => 'Pendiente',
        ]);
    }

    private function cashAccount(Company $company): CashAccount
    {
        return CashAccount::query()->create([
            'company_id' => $company->id,
            'name' => 'Banco Caja',
            'currency_id' => Currency::query()->where('company_id', $company->id)->where('code', 'CLP')->value('id'),
            'opening_balance' => 0,
            'is_active' => true,
        ]);
    }
}
