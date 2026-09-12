<?php

namespace Tests\Feature;

use App\Models\BankReconciliation;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Services\BankReconciliationService;
use App\Services\CashAccountBalanceService;
use App\Services\CashMovementService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_uses_cutover_and_only_posted_movements_of_same_account(): void
    {
        [$company, $account] = $this->account('BANK-A', 5000000, '2026-09-30');
        [$otherCompany, $other] = $this->account('BANK-B', 9000000, '2026-09-30');
        $this->movement($company, $account, 'M-A1', '2026-10-01', 1000000, 0, 'posted');
        $this->movement($company, $account, 'M-A2', '2026-10-02', 0, 250000, 'posted');
        $this->movement($company, $account, 'M-A-DRAFT', '2026-10-03', 999999, 0, 'draft');
        $this->movement($company, $other, 'M-OTHER', '2026-10-03', 800000, 0, 'posted');
        $this->movement($company, null, 'M-UNASSIGNED', '2026-10-03', 700000, 0, 'posted');
        $this->movement($company, $account, 'M-BEFORE', '2026-09-30', 700000, 0, 'posted');

        $this->assertSame(5750000.0, app(CashAccountBalanceService::class)->balanceAt($account, '2026-10-03'));
        $this->assertSame(5000000.0, app(CashAccountBalanceService::class)->balanceAt($account, '2026-09-30'));
        $this->assertSame(5000000.0, app(CashAccountBalanceService::class)->companyBalanceAt($company->id, '2026-09-30'));
        $this->assertNotSame($company->id, $otherCompany->id);
    }

    public function test_balance_before_cutover_is_not_conciliable(): void
    {
        [$company, $account] = $this->account('BANK-C', 1000000, '2026-09-30');
        $this->expectException(DomainException::class);
        app(CashAccountBalanceService::class)->balanceAt($account, '2026-09-29');
    }

    public function test_posted_requires_active_same_company_clp_account_and_valid_date(): void
    {
        [$company, $account] = $this->account('BANK-D', 1000000, '2026-09-30');
        $service = app(CashMovementService::class);
        try {
            $service->create(['company_id' => $company->id, 'code' => 'M-NO-ACCOUNT', 'movement_date' => '2026-10-01', 'income' => 1, 'expense' => 0, 'status' => 'posted']);
            $this->fail('Expected account requirement.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('requiere una cuenta', $exception->getMessage());
        }
        $draft = $service->create(['company_id' => $company->id, 'code' => 'M-DRAFT', 'movement_type' => 'Otro', 'movement_date' => '2026-09-01', 'income' => 1, 'expense' => 0, 'status' => 'draft']);
        $this->assertSame('draft', $draft->status);
        $account->update(['is_active' => false]);
        $this->expectException(DomainException::class);
        $service->create(['company_id' => $company->id, 'code' => 'M-INACTIVE', 'cash_account_id' => $account->id, 'movement_date' => '2026-10-01', 'income' => 1, 'expense' => 0, 'status' => 'posted']);
    }

    public function test_posted_rejects_foreign_non_clp_missing_cutover_and_pre_cutover_accounts(): void
    {
        [$company, $account] = $this->account('BANK-I', 1000000, '2026-09-30');
        [, $foreign] = $this->account('BANK-J', 1000000, '2026-09-30');
        $missingDate = CashAccount::query()->create(['company_id' => $company->id, 'code' => 'BANK-K', 'name' => 'Sin fecha', 'currency' => 'CLP', 'opening_balance' => 0, 'is_active' => true]);
        $usd = Currency::query()->create(['company_id' => $company->id, 'code' => 'USD', 'name' => 'Dólar', 'symbol' => 'US$', 'minor_units' => 2, 'active' => true, 'is_base_currency' => false, 'sort_order' => 2]);
        $nonClp = CashAccount::query()->create(['company_id' => $company->id, 'code' => 'BANK-L', 'name' => 'USD', 'currency_id' => $usd->id, 'currency' => 'USD', 'opening_balance' => 0, 'opening_balance_date' => '2026-09-30', 'is_active' => true]);
        $service = app(CashMovementService::class);
        $payload = fn (CashAccount $item, string $code, string $date = '2026-10-01'): array => ['company_id' => $company->id, 'code' => $code, 'movement_type' => 'Otro', 'cash_account_id' => $item->id, 'movement_date' => $date, 'income' => 1, 'expense' => 0, 'status' => 'posted'];
        $this->assertDomain(fn () => $service->create($payload($foreign, 'M-FOREIGN')), 'empresa activa');
        $this->assertDomain(fn () => $service->create($payload($nonClp, 'M-USD')), 'solo admite cuentas CLP');
        $this->assertDomain(fn () => $service->create($payload($missingDate, 'M-NODATE')), 'fecha de saldo inicial');
        $this->assertDomain(fn () => $service->create($payload($account, 'M-BEFORE', '2026-09-30')), 'posterior a la fecha');
    }

    public function test_http_store_creates_draft_snapshot_for_current_tenant(): void
    {
        [$company, $account] = $this->account('BANK-M', 2000000, '2026-09-30');
        $admin = User::query()->create(['company_id' => $company->id, 'name' => 'Admin Recon 4', 'email' => 'recon4@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $response = $this->actingAs($admin)->post(route('bank-reconciliation.store'), ['cash_account_id' => $account->id, 'reconciliation_date' => '2026-10-01', 'bank_balance' => 2000000, 'notes' => 'Borrador']);
        $response->assertRedirect();
        $this->assertDatabaseHas('bank_reconciliations', ['company_id' => $company->id, 'cash_account_id' => $account->id, 'status' => 'draft', 'difference' => 0]);
    }

    public function test_reconciliation_snapshots_difference_recalculates_and_becomes_immutable(): void
    {
        [$company, $account] = $this->account('BANK-E', 5000000, '2026-09-30');
        $this->movement($company, $account, 'M-E1', '2026-10-01', 1000000, 0, 'posted');
        $admin = User::query()->create(['company_id' => $company->id, 'name' => 'Admin Recon', 'email' => 'recon@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $service = app(BankReconciliationService::class);
        $draft = $service->saveDraft($company->id, $account->id, '2026-10-01', 5900000, 'QA', $admin);
        $this->assertSame('6000000.00', (string) $draft->system_balance_snapshot);
        $this->assertSame('-100000.00', (string) $draft->difference);
        $this->expectException(DomainException::class);
        $service->reconcile($draft, $company->id, $admin);
    }

    public function test_zero_difference_reconciles_and_retroactive_posting_is_rejected(): void
    {
        [$company, $account] = $this->account('BANK-F', 5000000, '2026-09-30');
        $admin = User::query()->create(['company_id' => $company->id, 'name' => 'Admin Recon 2', 'email' => 'recon2@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $service = app(BankReconciliationService::class);
        $draft = $service->saveDraft($company->id, $account->id, '2026-10-01', 5000000, null, $admin);
        $closed = $service->reconcile($draft, $company->id, $admin);
        $this->assertSame('reconciled', $closed->status);
        $this->assertSame(0.0, (float) $closed->difference);
        try {
            app(CashMovementService::class)->create(['company_id' => $company->id, 'code' => 'M-RETRO', 'cash_account_id' => $account->id, 'movement_date' => '2026-10-01', 'income' => 1, 'expense' => 0, 'status' => 'posted']);
            $this->fail('Expected reconciled period rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('período bancario', $exception->getMessage());
        }
    }

    public function test_http_screen_is_tenant_scoped_and_exposes_reconciliation_form(): void
    {
        [$company, $account] = $this->account('BANK-G', 1000000, '2026-09-30');
        $admin = User::query()->create(['company_id' => $company->id, 'name' => 'Admin Recon 3', 'email' => 'recon3@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $response = $this->actingAs($admin)->get(route('bank-reconciliation.index'));
        $response->assertOk()->assertSee('Conciliación bancaria')->assertSee($account->name)->assertSee('Guardar borrador');
        $otherCompany = Company::query()->create(['code' => 'BANK-H', 'name' => 'Otra empresa', 'status' => 'active']);
        $this->assertFalse($response->getContent() !== '' && str_contains($response->getContent(), $otherCompany->name));
    }

    private function account(string $code, int $opening, string $date): array
    {
        $company = Company::query()->create(['code' => $code, 'name' => $code, 'status' => 'active']);
        $currency = Currency::query()->create(['company_id' => $company->id, 'code' => 'CLP', 'name' => 'Peso chileno', 'symbol' => '$', 'minor_units' => 0, 'active' => true, 'is_base_currency' => true, 'sort_order' => 1]);
        $account = CashAccount::query()->create(['company_id' => $company->id, 'code' => $code, 'name' => 'Cuenta '.$code, 'currency_id' => $currency->id, 'currency' => 'CLP', 'opening_balance' => $opening, 'opening_balance_date' => $date, 'is_active' => true]);
        return [$company, $account];
    }

    private function movement(Company $company, ?CashAccount $account, string $code, string $date, int $income, int $expense, string $status): CashMovement
    {
        return CashMovement::query()->forceCreate(['company_id' => $company->id, 'code' => $code, 'movement_type' => 'Otro', 'cash_account_id' => $account?->id, 'movement_date' => $date, 'income' => $income, 'expense' => $expense, 'status' => $status]);
    }

    private function assertDomain(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected DomainException.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
