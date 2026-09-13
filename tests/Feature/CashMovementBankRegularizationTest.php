<?php

namespace Tests\Feature;

use App\Models\BankReconciliation;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashMovementBankAssignment;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Scenario;
use App\Models\User;
use App\Services\CashAccountBalanceService;
use App\Services\CashFlowService;
use App\Services\CashMovementBankRegularizationService;
use App\Services\OperationalDependencyService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashMovementBankRegularizationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private Currency $clp;
    private CashAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['code' => 'REG-CO', 'name' => 'Empresa regularización', 'status' => 'active']);
        $this->admin = User::query()->create(['company_id' => $this->company->id, 'name' => 'Admin Regularización', 'email' => 'regularization@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->clp = Currency::query()->create(['company_id' => $this->company->id, 'code' => 'CLP', 'name' => 'Peso chileno', 'symbol' => '$', 'minor_units' => 0, 'active' => true, 'is_base_currency' => true, 'sort_order' => 1]);
        $this->account = $this->account($this->company, 'REG-BANK', 'Cuenta conciliable', '2026-09-10');
        CompanySetting::query()->create(['company_id' => $this->company->id, 'setting_key' => 'active_scenario', 'setting_value' => 'BASE']);
        Scenario::query()->create(['company_id' => $this->company->id, 'code' => 'BASE', 'name' => 'Base', 'sales_factor' => 1, 'cost_factor' => 1, 'collection_delay_days' => 0, 'new_hires_monthly' => 0, 'is_active' => true]);
    }

    public function test_post_cutover_regularization_updates_balance_reconciliation_cash_flow_and_audit_once(): void
    {
        $movement = $this->movement($this->company, 'REG-POST', '2026-09-11', 5000);
        $assignment = $this->service()->assign($this->company->id, $movement->id, $this->account->id, 'Cartola histórica identificada', $this->admin);

        $this->assertSame('post_cutover', $assignment->classification);
        $this->assertSame('active', $assignment->status);
        $this->assertNull($movement->fresh()->cash_account_id);
        $this->assertSame(105000.0, app(CashAccountBalanceService::class)->balanceAt($this->account, '2026-09-11'));
        $this->actingAs($this->admin)->get(route('bank-reconciliation.index', ['cash_account_id' => $this->account->id, 'reconciliation_date' => '2026-09-11']))->assertOk()->assertSee('REG-POST');
        $row = app(CashFlowService::class)->monthly($this->company->id, '2026-09-01', 1)[0];
        $this->assertSame(5000.0, $row['income_real']);
        $this->assertSame(105000.0, $row['closing_real']);
        $summary = $this->service()->regularizationSummary($this->company->id);
        $this->assertSame(0, $summary['unregularized']['count']);
        $this->assertSame(1, $summary['regularized_post_cutover']['count']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'action' => 'cash_movement_bank_assignment.assigned', 'auditable_id' => $assignment->id]);
    }

    public function test_pre_cutover_regularization_is_historical_and_does_not_enter_conciliable_balance_or_list(): void
    {
        $movement = $this->movement($this->company, 'REG-PRE', '2026-09-10', 7000);
        $assignment = $this->service()->assign($this->company->id, $movement->id, $this->account->id, 'Incluido en saldo inicial', $this->admin);

        $this->assertSame('pre_cutover', $assignment->classification);
        $this->assertSame(100000.0, app(CashAccountBalanceService::class)->balanceAt($this->account, '2026-09-11'));
        $this->actingAs($this->admin)->get(route('bank-reconciliation.index', ['cash_account_id' => $this->account->id, 'reconciliation_date' => '2026-09-11']))->assertOk()->assertDontSee('REG-PRE');
        $summary = $this->service()->regularizationSummary($this->company->id);
        $this->assertSame(0, $summary['unregularized']['count']);
        $this->assertSame(1, $summary['regularized_pre_cutover']['count']);
    }

    public function test_regularization_rejects_draft_direct_account_foreign_inactive_non_clp_and_missing_cutover(): void
    {
        $draft = $this->movement($this->company, 'REG-DRAFT', '2026-09-11', 1, null, 'draft');
        $direct = $this->movement($this->company, 'REG-DIRECT', '2026-09-11', 1, $this->account);
        $other = Company::query()->create(['code' => 'REG-OTHER', 'name' => 'Otra empresa', 'status' => 'active']);
        $foreign = $this->movement($other, 'REG-FOREIGN', '2026-09-11', 1);
        $inactive = $this->account($this->company, 'REG-INACTIVE', 'Inactiva', '2026-09-10', false);
        $usd = Currency::query()->create(['company_id' => $this->company->id, 'code' => 'USD', 'name' => 'Dólar', 'symbol' => 'US$', 'minor_units' => 2, 'active' => true, 'is_base_currency' => false, 'sort_order' => 2]);
        $nonClp = CashAccount::query()->create(['company_id' => $this->company->id, 'code' => 'REG-USD', 'name' => 'USD', 'currency_id' => $usd->id, 'currency' => 'USD', 'opening_balance' => 0, 'opening_balance_date' => '2026-09-10', 'is_active' => true]);
        $missingCutover = CashAccount::query()->create(['company_id' => $this->company->id, 'code' => 'REG-NODATE', 'name' => 'Sin corte', 'currency_id' => $this->clp->id, 'currency' => 'CLP', 'opening_balance' => 0, 'is_active' => true]);
        $candidate = $this->movement($this->company, 'REG-CANDIDATE', '2026-09-11', 1);

        $this->assertDomain(fn () => $this->service()->assign($this->company->id, $draft->id, $this->account->id, 'x', $this->admin), 'contabilizados');
        $this->assertDomain(fn () => $this->service()->assign($this->company->id, $direct->id, $this->account->id, 'x', $this->admin), 'cuenta de caja directa');
        $this->assertDomain(fn () => $this->service()->assign($this->company->id, $candidate->id, $inactive->id, 'x', $this->admin), 'activa');
        $this->assertDomain(fn () => $this->service()->assign($this->company->id, $candidate->id, $nonClp->id, 'x', $this->admin), 'solo admite cuentas CLP');
        $this->assertDomain(fn () => $this->service()->assign($this->company->id, $candidate->id, $missingCutover->id, 'x', $this->admin), 'fecha de saldo inicial');
        $this->assertDomain(fn () => $this->service()->assign($this->company->id, $candidate->id, $this->account->id, '', $this->admin), 'motivo');
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service()->assign($this->company->id, $foreign->id, $this->account->id, 'x', $this->admin);
    }

    public function test_post_cutover_assignment_and_reversal_are_blocked_by_closed_period_but_pre_cutover_reversal_is_allowed(): void
    {
        $post = $this->movement($this->company, 'REG-CLOSED', '2026-09-11', 100);
        BankReconciliation::query()->forceCreate(['company_id' => $this->company->id, 'cash_account_id' => $this->account->id, 'reconciliation_date' => '2026-09-12', 'bank_balance' => 100000, 'system_balance_snapshot' => 100000, 'difference' => 0, 'status' => 'reconciled']);
        $this->assertDomain(fn () => $this->service()->assign($this->company->id, $post->id, $this->account->id, 'Tardío', $this->admin), 'período bancario');

        $pre = $this->movement($this->company, 'REG-PRE-REVERSE', '2026-09-10', 100);
        $assignment = $this->service()->assign($this->company->id, $pre->id, $this->account->id, 'Histórico', $this->admin);
        $reversed = $this->service()->reverse($this->company->id, $assignment->id, 'Clasificación incorrecta', $this->admin);
        $this->assertSame('reversed', $reversed->status);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'action' => 'cash_movement_bank_assignment.reversed', 'auditable_id' => $assignment->id]);
    }

    public function test_post_cutover_reversal_is_blocked_after_its_period_is_reconciled(): void
    {
        $movement = $this->movement($this->company, 'REG-POST-REVERSE', '2026-09-11', 100);
        $assignment = $this->service()->assign($this->company->id, $movement->id, $this->account->id, 'Regularización posterior', $this->admin);
        BankReconciliation::query()->forceCreate(['company_id' => $this->company->id, 'cash_account_id' => $this->account->id, 'reconciliation_date' => '2026-09-12', 'bank_balance' => 100100, 'system_balance_snapshot' => 100100, 'difference' => 0, 'status' => 'reconciled']);

        $this->assertDomain(fn () => $this->service()->reverse($this->company->id, $assignment->id, 'No permitido', $this->admin), 'período bancario');
        $this->assertSame('active', $assignment->fresh()->status);
    }

    public function test_reversal_reassignment_history_and_database_unique_active_guard_are_preserved(): void
    {
        $movement = $this->movement($this->company, 'REG-HISTORY', '2026-09-11', 100);
        $first = $this->service()->assign($this->company->id, $movement->id, $this->account->id, 'Primera asignación', $this->admin);
        $this->assertDomain(fn () => $this->service()->assign($this->company->id, $movement->id, $this->account->id, 'Duplicada', $this->admin), 'asignación bancaria activa');
        $this->service()->reverse($this->company->id, $first->id, 'Reasignación QA', $this->admin);
        $second = $this->service()->assign($this->company->id, $movement->id, $this->account->id, 'Segunda asignación', $this->admin);
        $this->assertSame(2, CashMovementBankAssignment::query()->where('cash_movement_id', $movement->id)->count());
        $this->assertSame('active', $second->status);
        $this->assertDomain(fn () => $this->service()->reverse($this->company->id, $second->id, '', $this->admin), 'motivo');

        $this->expectException(QueryException::class);
        CashMovementBankAssignment::query()->forceCreate([
            'company_id' => $this->company->id,
            'cash_movement_id' => $movement->id,
            'cash_account_id' => $this->account->id,
            'classification' => 'post_cutover',
            'status' => 'active',
            'reason' => 'Intento directo',
            'assigned_at' => now(),
        ]);
    }

    public function test_http_regularization_is_tenant_scoped_and_account_dependency_is_protected(): void
    {
        $movement = $this->movement($this->company, 'REG-HTTP', '2026-09-11', 100);
        $this->actingAs($this->admin)->post(route('bank-regularization.assign'), ['cash_movement_id' => $movement->id, 'cash_account_id' => $this->account->id, 'reason' => 'Formulario QA'])->assertRedirect();
        $this->actingAs($this->admin)->get(route('bank-regularization.index'))->assertOk()->assertSee('REG-HTTP')->assertSee('Regularización bancaria');
        $dependencies = app(OperationalDependencyService::class)->blockers($this->account);
        $this->assertTrue($dependencies->contains(fn (array $item): bool => $item['label'] === 'regularizaciones bancarias' && $item['count'] === 1));

        $other = Company::query()->create(['code' => 'REG-HTTP-OTHER', 'name' => 'Otra empresa', 'status' => 'active']);
        $otherAdmin = User::query()->create(['company_id' => $other->id, 'name' => 'Otra Admin', 'email' => 'other-regularization@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->actingAs($otherAdmin)->get(route('bank-regularization.index'))->assertOk()->assertDontSee('REG-HTTP');
        $this->actingAs($otherAdmin)
            ->post(route('bank-regularization.assign'), ['cash_movement_id' => $movement->id, 'cash_account_id' => $this->account->id, 'reason' => 'Cruce'])
            ->assertRedirect()
            ->assertSessionHasErrors('regularization');
    }

    private function service(): CashMovementBankRegularizationService
    {
        return app(CashMovementBankRegularizationService::class);
    }

    private function account(Company $company, string $code, string $name, ?string $cutover, bool $active = true): CashAccount
    {
        $currency = $company->id === $this->company->id ? $this->clp : Currency::query()->create(['company_id' => $company->id, 'code' => 'CLP', 'name' => 'Peso', 'symbol' => '$', 'minor_units' => 0, 'active' => true, 'is_base_currency' => true, 'sort_order' => 1]);
        return CashAccount::query()->create(['company_id' => $company->id, 'code' => $code, 'name' => $name, 'currency_id' => $currency->id, 'currency' => 'CLP', 'opening_balance' => 100000, 'opening_balance_date' => $cutover, 'is_active' => $active]);
    }

    private function movement(Company $company, string $code, string $date, float $income, ?CashAccount $account = null, string $status = 'posted'): CashMovement
    {
        return CashMovement::query()->forceCreate(['company_id' => $company->id, 'code' => $code, 'movement_type' => 'Otro', 'cash_account_id' => $account?->id, 'movement_date' => $date, 'income' => $income, 'expense' => 0, 'status' => $status]);
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
