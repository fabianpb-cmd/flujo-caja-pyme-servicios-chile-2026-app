<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ExpenseDocument;
use App\Models\LegalParameter;
use App\Models\MonthlyClosure;
use App\Models\Person;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\LegalParameterService;
use App\Services\PayablesService;
use App\Services\PayrollService;
use App\Services\ReceivablesService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialCoreTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private CashAccount $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'code' => 'CMP-TST',
            'name' => 'Empresa Test',
            'status' => 'active',
        ]);

        $this->user = User::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Admin Test',
            'email' => 'admin@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        $this->cashAccount = CashAccount::query()->create([
            'company_id' => $this->company->id,
            'code' => 'BANK-TST',
            'name' => 'Banco Test',
            'currency' => 'CLP',
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        $this->seedLegalParameter('IVA', 'IVA', '2026-01-01', null, 0.19);
        $this->seedLegalParameter('RETENCION_HONORARIOS', 'Retencion honorarios', '2026-01-01', '2026-12-31', 0.1525);
        $this->seedLegalParameter('RETENCION_HONORARIOS', 'Retencion honorarios', '2027-01-01', '2027-12-31', 0.16);
        $this->seedLegalParameter('PROVISION_VACACIONES', 'Provision vacaciones', '2026-01-01', null, 0.0833);
    }

    public function test_iva_is_calculated_from_legal_parameter(): void
    {
        $amounts = app(ReceivablesService::class)->amountsWithVat($this->company->id, 1000, '2026-07-01');

        $this->assertSame(1000.0, $amounts['net_amount']);
        $this->assertSame(190.0, $amounts['vat_amount']);
        $this->assertSame(1190.0, $amounts['gross_amount']);
    }

    public function test_honorarios_use_historical_retention_rate(): void
    {
        $person = $this->person([
            'modality' => 'Honorarios mensual',
            'monthly_value' => 1000000,
        ]);

        $payroll = app(PayrollService::class)->calculate($person, '2026-07-01');

        $this->assertSame(152500.0, $payroll['employee_retention']);
        $this->assertSame(847500.0, $payroll['net_pay']);
        $this->assertSame(0.0, $payroll['vacation_provision']);
        $this->assertSame(0.0, $payroll['taxable_amount']);
    }

    public function test_hourly_non_dependent_payments_use_honorarios_retention_without_vacation_provision(): void
    {
        $person = $this->person([
            'modality' => 'Pago por hora',
            'hourly_value' => 32800,
        ]);

        $payroll = app(PayrollService::class)->calculate($person, '2026-07-01', ['hours_approved' => 10]);

        $this->assertSame(328000.0, $payroll['base_salary']);
        $this->assertSame(50020.0, $payroll['employee_retention']);
        $this->assertSame(277980.0, $payroll['net_pay']);
        $this->assertSame(0.0, $payroll['vacation_provision']);
        $this->assertSame(0.0, $payroll['taxable_amount']);
    }

    public function test_monthly_salary_is_proportional_to_worked_days(): void
    {
        $person = $this->person([
            'modality' => 'Dependiente mensual',
            'monthly_value' => 1000000,
            'start_date' => '2026-06-16',
        ]);

        $payroll = app(PayrollService::class)->calculate($person, '2026-06-01');

        $this->assertSame(15, $payroll['worked_days']);
        $this->assertSame(30, $payroll['month_days']);
        $this->assertSame(500000.0, $payroll['base_salary']);
        $this->assertSame(41650.0, $payroll['vacation_provision']);
    }

    public function test_partial_payments_update_invoice_balance_and_status(): void
    {
        $invoice = $this->invoice('ING-001', 3000000, '2026-08-01');
        $service = app(CashMovementService::class);

        $service->create($this->cashData('MOV-001', 'sales_document', 'ING-001', '2026-08-15', 1000000, 0), $this->user);
        $this->assertSame(2000000.0, app(ReceivablesService::class)->balance($invoice->refresh()));
        $this->assertSame('Parcial', $invoice->refresh()->status);

        $service->create($this->cashData('MOV-002', 'sales_document', 'ING-001', '2026-08-30', 2000000, 0), $this->user);
        $this->assertSame(0.0, app(ReceivablesService::class)->balance($invoice->refresh()));
        $this->assertSame('Pagado', $invoice->refresh()->status);
    }

    public function test_overpayment_is_rejected_inside_cash_transaction(): void
    {
        $this->invoice('ING-002', 1000000, '2026-08-01');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede el saldo');

        app(CashMovementService::class)->create($this->cashData('MOV-003', 'sales_document', 'ING-002', '2026-08-15', 1000001, 0), $this->user);
    }

    public function test_cash_movements_are_rejected_for_closed_periods(): void
    {
        $this->invoice('ING-CLSD', 1000000, '2026-08-01');

        MonthlyClosure::query()->create([
            'company_id' => $this->company->id,
            'period_date' => '2026-08-01',
            'opening_balance' => 0,
            'closing_balance' => 0,
            'status' => 'closed',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('esta cerrado');

        app(CashMovementService::class)->create($this->cashData('MOV-CLSD', 'sales_document', 'ING-CLSD', '2026-08-15', 100000, 0), $this->user);
    }

    public function test_cash_movements_create_audit_log(): void
    {
        $this->invoice('ING-AUD', 1000000, '2026-08-01');

        $movement = app(CashMovementService::class)->create($this->cashData('MOV-AUD', 'sales_document', 'ING-AUD', '2026-08-15', 100000, 0), $this->user);

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'action' => 'cash_movement.created',
            'auditable_id' => $movement->id,
        ]);

        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_historical_accounts_receivable_ignore_future_collections(): void
    {
        $this->invoice('ING-003', 3000000, '2026-08-01');
        $service = app(CashMovementService::class);

        $service->create($this->cashData('MOV-004', 'sales_document', 'ING-003', '2026-08-15', 1000000, 0), $this->user);
        $service->create($this->cashData('MOV-005', 'sales_document', 'ING-003', '2026-09-15', 2000000, 0), $this->user);

        $receivables = app(ReceivablesService::class);
        $this->assertSame(3000000.0, $receivables->accountsReceivable($this->company->id, '2026-08-14'));
        $this->assertSame(2000000.0, $receivables->accountsReceivable($this->company->id, '2026-08-31'));
        $this->assertSame(0.0, $receivables->accountsReceivable($this->company->id, '2026-09-30'));
    }

    public function test_historical_accounts_payable_ignore_future_payments(): void
    {
        $this->expense('EGR-001', 1200000, '2026-08-01');
        $service = app(CashMovementService::class);

        $service->create($this->cashData('MOV-006', 'expense_document', 'EGR-001', '2026-08-20', 0, 200000), $this->user);
        $service->create($this->cashData('MOV-007', 'expense_document', 'EGR-001', '2026-09-20', 0, 1000000), $this->user);

        $payables = app(PayablesService::class);
        $this->assertSame(1200000.0, $payables->accountsPayable($this->company->id, '2026-08-19'));
        $this->assertSame(1000000.0, $payables->accountsPayable($this->company->id, '2026-08-31'));
        $this->assertSame(0.0, $payables->accountsPayable($this->company->id, '2026-09-30'));
    }

    public function test_legal_parameter_is_selected_by_vigency(): void
    {
        $service = app(LegalParameterService::class);

        $this->assertSame('0.152500', $service->value($this->company->id, 'RETENCION_HONORARIOS', '2026-07-01'));
        $this->assertSame('0.160000', $service->value($this->company->id, 'RETENCION_HONORARIOS', '2027-07-01'));
    }

    public function test_null_probability_is_one_hundred_percent_in_forecast(): void
    {
        $invoice = $this->invoice('ING-004', 900000, '2026-08-01', null);

        $this->assertSame(900000.0, app(ReceivablesService::class)->forecastAmount($invoice));
    }

    private function seedLegalParameter(string $code, string $name, string $from, ?string $to, float $value): void
    {
        LegalParameter::query()->create([
            'company_id' => $this->company->id,
            'parameter_code' => $code,
            'parameter_name' => $name,
            'valid_from' => $from,
            'valid_to' => $to,
            'value' => $value,
            'unit' => '%',
        ]);
    }

    private function person(array $overrides = []): Person
    {
        return Person::query()->create(array_merge([
            'company_id' => $this->company->id,
            'code' => 'PER-'.uniqid(),
            'name' => 'Persona Test',
            'modality' => 'Dependiente mensual',
            'monthly_value' => 1000000,
            'hourly_value' => 0,
            'status' => 'active',
        ], $overrides));
    }

    private function invoice(string $code, float $grossAmount, string $issueDate, ?float $probability = 1.0): SalesDocument
    {
        return SalesDocument::query()->create([
            'company_id' => $this->company->id,
            'code' => $code,
            'client_id' => $this->clientId(),
            'document_type' => 'Factura',
            'issue_date' => $issueDate,
            'due_date' => $issueDate,
            'net_amount' => $grossAmount,
            'vat_amount' => 0,
            'gross_amount' => $grossAmount,
            'collected_amount' => 0,
            'payment_probability' => $probability,
            'status' => 'Pendiente',
            'is_voided' => false,
        ]);
    }

    private function expense(string $code, float $grossAmount, string $issueDate): ExpenseDocument
    {
        return ExpenseDocument::query()->create([
            'company_id' => $this->company->id,
            'code' => $code,
            'vendor_name' => 'Proveedor Test',
            'issue_date' => $issueDate,
            'due_date' => $issueDate,
            'net_amount' => $grossAmount,
            'vat_amount' => 0,
            'recoverable_vat_amount' => 0,
            'gross_amount' => $grossAmount,
            'paid_amount' => 0,
            'payment_status' => 'Pendiente',
            'tax_deductible' => true,
            'deductible_vat' => false,
        ]);
    }

    private function clientId(): int
    {
        return \App\Models\Client::query()->firstOrCreate([
            'company_id' => $this->company->id,
            'code' => 'CLI-TST',
        ], [
            'legal_name' => 'Cliente Test',
            'payment_term_days' => 30,
            'status' => 'active',
        ])->id;
    }

    private function cashData(string $code, string $sourceType, string $sourceCode, string $date, float $income, float $expense): array
    {
        return [
            'company_id' => $this->company->id,
            'code' => $code,
            'movement_type' => $income > 0 ? 'Ingreso' : 'Egreso',
            'source_document_type' => $sourceType,
            'source_document_code' => $sourceCode,
            'movement_date' => $date,
            'income' => $income,
            'expense' => $expense,
            'cash_account_id' => $this->cashAccount->id,
            'status' => 'posted',
        ];
    }
}
