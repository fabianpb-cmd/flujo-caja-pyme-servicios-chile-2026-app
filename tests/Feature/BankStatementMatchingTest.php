<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\BankStatementMatch;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashMovementBankAssignment;
use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Services\BankStatementImportService;
use App\Services\BankStatementMatchingService;
use App\Services\CashAccountBalanceService;
use App\Services\CashMovementBankRegularizationService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BankStatementMatchingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private Currency $clp;
    private CashAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['code' => 'STM-CO', 'name' => 'Empresa cartola', 'status' => 'active']);
        $this->admin = User::query()->create(['company_id' => $this->company->id, 'name' => 'Admin Cartola', 'email' => 'statement@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->clp = Currency::query()->create(['company_id' => $this->company->id, 'code' => 'CLP', 'name' => 'Peso chileno', 'symbol' => '$', 'minor_units' => 0, 'active' => true, 'is_base_currency' => true, 'sort_order' => 1]);
        $this->account = $this->account($this->company, 'STM-BANK', 'Cuenta cartola', '2026-09-10');
    }

    public function test_imports_canonical_csv_bom_and_semicolon_without_creating_cash_movements(): void
    {
        $import = $this->import("\xEF\xBB\xBFfecha;descripcion;referencia;ingreso;egreso\n11/09/2026;Abono QA;REF-1;5.000;\n12/09/2026;Cargo QA;REF-2;;1.250\n");

        $this->assertSame(2, $import->row_count);
        $this->assertSame('2026-09-11', $import->statement_from->toDateString());
        $this->assertSame('2026-09-12', $import->statement_to->toDateString());
        $this->assertDatabaseHas('bank_statement_lines', ['bank_statement_import_id' => $import->id, 'direction' => 'income', 'amount' => 5000]);
        $this->assertDatabaseHas('bank_statement_lines', ['bank_statement_import_id' => $import->id, 'direction' => 'expense', 'amount' => 1250]);
        $this->assertSame(0, CashMovement::query()->count());
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'action' => 'bank_statement.imported', 'auditable_id' => $import->id]);
    }

    public function test_imports_safe_signed_amount_csv_with_comma_delimiter(): void
    {
        $import = $this->import("transaction_date,description,amount,external_id\n2026-09-11,Abono firmado,5000,TX-IN\n2026-09-12,Cargo firmado,-1.250,TX-OUT\n", 'signed.csv');

        $this->assertSame(2, $import->row_count);
        $this->assertDatabaseHas('bank_statement_lines', ['bank_statement_import_id' => $import->id, 'external_id' => 'TX-IN', 'direction' => 'income', 'amount' => 5000]);
        $this->assertDatabaseHas('bank_statement_lines', ['bank_statement_import_id' => $import->id, 'external_id' => 'TX-OUT', 'direction' => 'expense', 'amount' => 1250]);
    }

    public function test_import_rejects_unsafe_headers_invalid_dates_and_invalid_amounts(): void
    {
        $this->assertDomain(fn () => $this->import("fecha,descripcion,monto,ingreso\n2026-09-11,QA,100,100\n"), 'no puede mezclar');
        $this->assertDomain(fn () => $this->import("fecha,descripcion,ingreso,egreso\n31/02/2026,QA,100,\n"), 'fecha');
        $this->assertDomain(fn () => $this->import("fecha,descripcion,ingreso,egreso\n2026-09-11,QA,abc,\n"), 'monto');
        $this->assertSame(0, BankStatementImport::query()->count());
    }

    public function test_duplicate_file_and_duplicate_line_are_rejected_while_reliable_ids_keep_legitimate_same_amount_rows(): void
    {
        $content = "fecha,descripcion,referencia,ingreso,egreso,id_transaccion\n2026-09-11,Abono,REF,5000,,TX-1\n";
        $this->import($content, 'one.csv');
        $this->assertDomain(fn () => $this->import($content, 'one-copy.csv'), 'ya fue importada');

        $this->assertDomain(fn () => $this->import("fecha,descripcion,referencia,ingreso,egreso,id_transaccion\n2026-09-12,Abono,REF,5000,,TX-1\n", 'line-copy.csv'), 'línea ya importada');
        $legitimate = $this->import("fecha,descripcion,referencia,ingreso,egreso,id_transaccion\n2026-09-12,Abono,REF,5000,,TX-2\n2026-09-12,Abono,REF,5000,,TX-3\n", 'distinct.csv');
        $this->assertSame(2, $legitimate->row_count);
        $this->assertSame(3, BankStatementLine::query()->count());
    }

    public function test_import_is_tenant_scoped_and_http_rejects_foreign_account(): void
    {
        [$otherCompany, $otherAccount, $otherAdmin] = $this->otherTenant();
        $file = UploadedFile::fake()->createWithContent('foreign.csv', "fecha,descripcion,ingreso,egreso\n2026-09-11,QA,100,\n");
        $this->actingAs($this->admin)->post(route('bank-statements.import'), ['cash_account_id' => $otherAccount->id, 'statement' => $file])->assertNotFound();
        $this->actingAs($otherAdmin)->get(route('bank-statements.index'))->assertOk()->assertDontSee($this->account->name);
        $this->assertNotSame($this->company->id, $otherCompany->id);
    }

    public function test_suggestions_require_exact_amount_direction_account_and_available_movement(): void
    {
        $line = $this->line('income', 5000, '2026-09-11', 'REF-EXACT');
        $exact = $this->movement('M-EXACT', '2026-09-11', 5000, 0, $this->account, 'REF-EXACT');
        $near = $this->movement('M-NEAR', '2026-09-12', 5000, 0, $this->account);
        $this->movement('M-AMOUNT', '2026-09-11', 4000, 0, $this->account);
        $this->movement('M-DIRECTION', '2026-09-11', 0, 5000, $this->account);
        $this->movement('M-TOO-FAR', '2026-09-15', 5000, 0, $this->account);
        $other = $this->account($this->company, 'STM-OTHER', 'Otra cuenta', '2026-09-10');
        $this->movement('M-ACCOUNT', '2026-09-11', 5000, 0, $other);
        $used = $this->movement('M-USED', '2026-09-11', 5000, 0, $this->account);
        $otherLine = $this->line('income', 5000, '2026-09-11', 'REF-USED');
        $this->matching()->match($this->company->id, $otherLine->id, $used->id, $this->admin);

        $suggestions = $this->matching()->suggestions($line, $this->company->id);
        $this->assertSame([$exact->id, $near->id], $suggestions->pluck('id')->all());
        $this->assertSame(90, $suggestions->first()->bank_statement_score);
        $this->assertSame(50, $suggestions->last()->bank_statement_score);
    }

    public function test_post_cutover_overlay_is_a_candidate_but_pre_cutover_overlay_is_not(): void
    {
        $post = $this->movement('M-POST-OVERLAY', '2026-09-11', 5000, 0, null);
        $pre = $this->movement('M-PRE-OVERLAY', '2026-09-10', 5000, 0, null);
        $regularizations = app(CashMovementBankRegularizationService::class);
        $regularizations->assign($this->company->id, $post->id, $this->account->id, 'Posterior', $this->admin);
        $regularizations->assign($this->company->id, $pre->id, $this->account->id, 'Histórico', $this->admin);

        $line = $this->line('income', 5000, '2026-09-11');
        $this->assertSame([$post->id], $this->matching()->suggestions($line, $this->company->id)->pluck('id')->all());
        $this->assertSame('pre_cutover', $pre->activeBankAssignment->classification);
    }

    public function test_manual_matching_is_audited_and_preserves_cash_and_balance(): void
    {
        $movement = $this->movement('M-MATCH', '2026-09-11', 5000, 0, $this->account, 'QA-REF');
        $line = $this->line('income', 5000, '2026-09-11', 'QA-REF');
        $balanceBefore = app(CashAccountBalanceService::class)->balanceAt($this->account, '2026-09-11');
        $match = $this->matching()->match($this->company->id, $line->id, $movement->id, $this->admin, 90);

        $this->assertSame('active', $match->status);
        $this->assertSame('matched', $line->fresh()->status);
        $this->assertSame('posted', $movement->fresh()->status);
        $this->assertSame($balanceBefore, app(CashAccountBalanceService::class)->balanceAt($this->account, '2026-09-11'));
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'action' => 'bank_statement_line.matched', 'auditable_id' => $match->id]);
        $this->assertSame(0, CashMovementBankAssignment::query()->count());
    }

    public function test_manual_matching_rejects_foreign_resources_double_active_matching_and_database_duplicates(): void
    {
        $line = $this->line('income', 5000, '2026-09-11');
        $movement = $this->movement('M-ONE', '2026-09-11', 5000, 0, $this->account);
        $match = $this->matching()->match($this->company->id, $line->id, $movement->id, $this->admin);
        $secondLine = $this->line('income', 5000, '2026-09-11', 'OTHER');
        $this->assertDomain(fn () => $this->matching()->match($this->company->id, $secondLine->id, $movement->id, $this->admin), 'ya tiene un matching');
        $this->assertDomain(fn () => $this->matching()->match($this->company->id, $line->id, $movement->id, $this->admin), 'ya no está disponible');
        $this->expectException(QueryException::class);
        BankStatementMatch::query()->forceCreate(['company_id' => $this->company->id, 'bank_statement_line_id' => $line->id, 'cash_movement_id' => $movement->id, 'status' => 'active', 'match_type' => 'manual', 'matched_at' => now()]);
    }

    public function test_manual_matching_rejects_incompatible_amount_direction_and_closed_period(): void
    {
        $line = $this->line('income', 5000, '2026-09-11');
        $wrongAmount = $this->movement('M-WRONG-AMOUNT', '2026-09-11', 4000, 0, $this->account);
        $wrongDirection = $this->movement('M-WRONG-DIRECTION', '2026-09-11', 0, 5000, $this->account);
        $this->assertDomain(fn () => $this->matching()->match($this->company->id, $line->id, $wrongAmount->id, $this->admin), 'monto o el sentido');
        $this->assertDomain(fn () => $this->matching()->match($this->company->id, $line->id, $wrongDirection->id, $this->admin), 'monto o el sentido');

        $valid = $this->movement('M-CLOSED', '2026-09-11', 5000, 0, $this->account);
        $match = $this->matching()->match($this->company->id, $line->id, $valid->id, $this->admin);
        BankReconciliation::query()->forceCreate(['company_id' => $this->company->id, 'cash_account_id' => $this->account->id, 'reconciliation_date' => '2026-09-12', 'bank_balance' => 100000, 'system_balance_snapshot' => 100000, 'difference' => 0, 'status' => 'reconciled']);
        $this->assertDomain(fn () => $this->matching()->reverse($this->company->id, $match->id, 'Fuera de período', $this->admin), 'período bancario');
        $this->assertSame('active', $match->fresh()->status);
    }

    public function test_matching_http_is_tenant_scoped_and_idor_safe(): void
    {
        $line = $this->line('income', 5000, '2026-09-11');
        $movement = $this->movement('M-HTTP', '2026-09-11', 5000, 0, $this->account);
        [, , $otherAdmin] = $this->otherTenant();
        $this->actingAs($otherAdmin)->post(route('bank-statements.match'), ['bank_statement_line_id' => $line->id, 'cash_movement_id' => $movement->id])->assertNotFound();
        $this->actingAs($this->admin)->post(route('bank-statements.match'), ['bank_statement_line_id' => $line->id, 'cash_movement_id' => $movement->id])->assertRedirect();
        $this->assertSame('matched', $line->fresh()->status);
    }

    public function test_reverse_and_ignore_require_reasons_and_are_blocked_by_closed_periods(): void
    {
        $movement = $this->movement('M-REVERSE', '2026-09-11', 5000, 0, $this->account);
        $line = $this->line('income', 5000, '2026-09-11');
        $match = $this->matching()->match($this->company->id, $line->id, $movement->id, $this->admin);
        $this->assertDomain(fn () => $this->matching()->reverse($this->company->id, $match->id, '', $this->admin), 'motivo');
        $reversed = $this->matching()->reverse($this->company->id, $match->id, 'Referencia incorrecta', $this->admin);
        $this->assertSame('reversed', $reversed->status);
        $this->assertSame('unmatched', $line->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bank_statement_line.match_reversed', 'auditable_id' => $match->id]);
        $ignored = $this->matching()->ignore($this->company->id, $line->id, 'Comisión pendiente', $this->admin);
        $this->assertSame('ignored', $ignored->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bank_statement_line.ignored', 'auditable_id' => $line->id]);

        $closedLine = $this->line('income', 1000, '2026-09-11', 'CLOSED');
        BankReconciliation::query()->forceCreate(['company_id' => $this->company->id, 'cash_account_id' => $this->account->id, 'reconciliation_date' => '2026-09-12', 'bank_balance' => 100000, 'system_balance_snapshot' => 100000, 'difference' => 0, 'status' => 'reconciled']);
        $this->assertDomain(fn () => $this->matching()->ignore($this->company->id, $closedLine->id, 'Tardío', $this->admin), 'período bancario');
        $this->assertSame('unmatched', $closedLine->fresh()->status);
    }

    public function test_summary_and_reconciliation_screen_show_matching_without_changing_close_rule(): void
    {
        $movement = $this->movement('M-SUMMARY', '2026-09-11', 5000, 0, $this->account);
        $line = $this->line('income', 5000, '2026-09-11');
        $ignored = $this->line('expense', 1000, '2026-09-11', 'FEE');
        $this->matching()->match($this->company->id, $line->id, $movement->id, $this->admin);
        $this->matching()->ignore($this->company->id, $ignored->id, 'Comisión', $this->admin);
        $summary = $this->matching()->summary($this->account, $this->company->id, '2026-09-11');
        $this->assertSame(1, $summary['system']);
        $this->assertSame(2, $summary['lines']);
        $this->assertSame(1, $summary['matched']);
        $this->assertSame(0, $summary['unmatched_system']);
        $this->assertSame(0, $summary['unmatched_bank']);
        $this->assertSame(1, $summary['ignored']);
        $this->actingAs($this->admin)->get(route('bank-reconciliation.index', ['cash_account_id' => $this->account->id, 'reconciliation_date' => '2026-09-11']))->assertOk()->assertSee('Cobertura de cartola')->assertSee('Matched');
    }

    public function test_cartola_ui_renders_upload_suggestions_and_sidebar_route(): void
    {
        $line = $this->line('income', 5000, '2026-09-11');
        $this->actingAs($this->admin)->get(route('bank-statements.index', ['cash_account_id' => $this->account->id, 'line_id' => $line->id]))
            ->assertOk()
            ->assertSee('Cartolas bancarias')
            ->assertSee('Importar CSV')
            ->assertSee('Ver sugerencias')
            ->assertSee('Cartolas bancarias');
    }

    private function import(string $content, string $filename = 'statement.csv'): BankStatementImport
    {
        return app(BankStatementImportService::class)->import($this->company->id, $this->account->id, UploadedFile::fake()->createWithContent($filename, $content), $this->admin);
    }

    private function line(string $direction, float $amount, string $date, ?string $reference = null): BankStatementLine
    {
        $import = BankStatementImport::query()->forceCreate(['company_id' => $this->company->id, 'cash_account_id' => $this->account->id, 'original_filename' => 'fixture.csv', 'file_hash' => hash('sha256', uniqid('', true)), 'statement_from' => $date, 'statement_to' => $date, 'imported_by_user_id' => $this->admin->id, 'imported_at' => now(), 'status' => 'imported', 'row_count' => 1]);
        return BankStatementLine::query()->forceCreate(['company_id' => $this->company->id, 'bank_statement_import_id' => $import->id, 'cash_account_id' => $this->account->id, 'transaction_date' => $date, 'description' => 'Movimiento QA '.($reference ?: uniqid()), 'reference' => $reference, 'amount' => $amount, 'direction' => $direction, 'row_hash' => hash('sha256', uniqid('', true)), 'status' => 'unmatched']);
    }

    private function movement(string $code, string $date, float $income, float $expense, ?CashAccount $account, ?string $reference = null): CashMovement
    {
        return CashMovement::query()->forceCreate(['company_id' => $this->company->id, 'code' => $code, 'movement_type' => 'Otro', 'cash_account_id' => $account?->id, 'movement_date' => $date, 'income' => $income, 'expense' => $expense, 'status' => 'posted', 'reference' => $reference]);
    }

    /** @return array{Company, CashAccount, User} */
    private function otherTenant(): array
    {
        $company = Company::query()->create(['code' => 'STM-OTHER', 'name' => 'Empresa externa', 'status' => 'active']);
        $currency = Currency::query()->create(['company_id' => $company->id, 'code' => 'CLP', 'name' => 'Peso', 'symbol' => '$', 'minor_units' => 0, 'active' => true, 'is_base_currency' => true, 'sort_order' => 1]);
        $account = CashAccount::query()->create(['company_id' => $company->id, 'code' => 'STM-OTHER-ACCOUNT', 'name' => 'Cuenta externa', 'currency_id' => $currency->id, 'currency' => 'CLP', 'opening_balance' => 0, 'opening_balance_date' => '2026-09-10', 'is_active' => true]);
        $user = User::query()->create(['company_id' => $company->id, 'name' => 'Admin externo', 'email' => 'other-statement@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        return [$company, $account, $user];
    }

    private function account(Company $company, string $code, string $name, string $cutover): CashAccount
    {
        return CashAccount::query()->create(['company_id' => $company->id, 'code' => $code, 'name' => $name, 'currency_id' => $this->clp->id, 'currency' => 'CLP', 'opening_balance' => 100000, 'opening_balance_date' => $cutover, 'is_active' => true]);
    }

    private function matching(): BankStatementMatchingService
    {
        return app(BankStatementMatchingService::class);
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
