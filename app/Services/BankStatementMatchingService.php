<?php

namespace App\Services;

use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Models\BankStatementMatch;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\User;
use App\Support\MassAssignment;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BankStatementMatchingService
{
    public function __construct(
        private readonly CashMovementBankRegularizationService $regularizations,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, CashMovement>
     */
    public function suggestions(BankStatementLine $line, int $companyId, int $limit = 5): Collection
    {
        if ($line->company_id !== $companyId || $line->status !== 'unmatched') {
            return new Collection();
        }
        $account = CashAccount::query()->forCompany($companyId)->with('currencyCatalog')->find($line->cash_account_id);
        if (! $account || ! $this->isEligibleAccount($account)) {
            return new Collection();
        }
        $from = $line->transaction_date->copy()->subDays(3)->toDateString();
        $to = $line->transaction_date->copy()->addDays(3)->toDateString();
        $amountColumn = $line->direction === 'income' ? 'income' : 'expense';

        return $this->regularizations->forEffectiveAccount(
            CashMovement::query()
                ->forCompany($companyId)
                ->where('status', 'posted')
                ->whereDate('movement_date', '>', $account->opening_balance_date->toDateString())
                ->whereBetween('movement_date', [$from, $to])
                ->where($amountColumn, (float) $line->amount)
                ->whereDoesntHave('activeBankStatementMatch'),
            $account
        )
            ->get()
            ->map(function (CashMovement $movement) use ($line): CashMovement {
                $movement->setAttribute('bank_statement_score', $this->score($line, $movement));
                return $movement;
            })
            ->sortByDesc('bank_statement_score')
            ->take(max(1, min(5, $limit)))
            ->values();
    }

    public function match(int $companyId, int $lineId, int $movementId, ?User $user = null, ?int $score = null): BankStatementMatch
    {
        // Read only enough to establish the prescribed account-first lock order.
        $seed = BankStatementLine::query()->forCompany($companyId)->findOrFail($lineId);

        return DB::transaction(function () use ($companyId, $lineId, $movementId, $user, $score, $seed): BankStatementMatch {
            $account = CashAccount::query()->forCompany($companyId)->with('currencyCatalog')->lockForUpdate()->findOrFail($seed->cash_account_id);
            $this->assertEligibleAccount($account);
            $line = BankStatementLine::query()->forCompany($companyId)->whereKey($lineId)->lockForUpdate()->firstOrFail();
            if ($line->cash_account_id !== $account->id || $line->status !== 'unmatched') {
                throw new DomainException('La línea de cartola ya no está disponible para matching.');
            }
            $movement = CashMovement::query()->forCompany($companyId)->whereKey($movementId)->lockForUpdate()->firstOrFail();
            $existing = BankStatementMatch::query()
                ->forCompany($companyId)
                ->where('status', 'active')
                ->where(function ($query) use ($line, $movement): void {
                    $query->where('bank_statement_line_id', $line->id)
                        ->orWhere('cash_movement_id', $movement->id);
                })
                ->lockForUpdate()
                ->get();
            if ($existing->isNotEmpty()) {
                throw new DomainException('La línea o el movimiento ya tiene un matching bancario activo.');
            }

            $this->assertCompatible($account, $line, $movement);
            $match = MassAssignment::create(BankStatementMatch::class, [
                'company_id' => $companyId,
                'bank_statement_line_id' => $line->id,
                'cash_movement_id' => $movement->id,
                'status' => 'active',
                'match_type' => 'manual',
                'score' => $score,
                'matched_by_user_id' => $user?->id,
                'matched_at' => now(),
            ]);
            $line->forceFill(['status' => 'matched'])->save();
            $this->audit->record('bank_statement_line.matched', $match->refresh(), $user, null, [
                'bank_statement_line_id' => $line->id,
                'cash_movement_id' => $movement->id,
                'cash_account_id' => $account->id,
                'score' => $score,
            ]);

            return $match->refresh();
        });
    }

    public function reverse(int $companyId, int $matchId, ?string $reason, ?User $user = null): BankStatementMatch
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new DomainException('El motivo de reversión es obligatorio.');
        }
        $seed = BankStatementMatch::query()->forCompany($companyId)->with('bankStatementLine')->findOrFail($matchId);

        return DB::transaction(function () use ($companyId, $matchId, $reason, $user, $seed): BankStatementMatch {
            $account = CashAccount::query()->forCompany($companyId)->with('currencyCatalog')->lockForUpdate()->findOrFail($seed->bankStatementLine->cash_account_id);
            $this->assertEligibleAccount($account);
            $line = BankStatementLine::query()->forCompany($companyId)->whereKey($seed->bank_statement_line_id)->lockForUpdate()->firstOrFail();
            $movement = CashMovement::query()->forCompany($companyId)->whereKey($seed->cash_movement_id)->lockForUpdate()->firstOrFail();
            $match = BankStatementMatch::query()->forCompany($companyId)->whereKey($matchId)->lockForUpdate()->firstOrFail();
            if ($match->status !== 'active' || $line->status !== 'matched') {
                throw new DomainException('El matching bancario ya no está activo.');
            }
            $this->assertOpenPeriods($account, $line, $movement);
            $before = $match->toArray();
            $match->forceFill([
                'status' => 'reversed',
                'reversed_by_user_id' => $user?->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();
            $line->forceFill(['status' => 'unmatched'])->save();
            $this->audit->record('bank_statement_line.match_reversed', $match->refresh(), $user, $before);

            return $match->refresh();
        });
    }

    public function ignore(int $companyId, int $lineId, ?string $reason, ?User $user = null): BankStatementLine
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new DomainException('El motivo para ignorar la línea es obligatorio.');
        }
        $seed = BankStatementLine::query()->forCompany($companyId)->findOrFail($lineId);

        return DB::transaction(function () use ($companyId, $lineId, $reason, $user, $seed): BankStatementLine {
            $account = CashAccount::query()->forCompany($companyId)->with('currencyCatalog')->lockForUpdate()->findOrFail($seed->cash_account_id);
            $this->assertEligibleAccount($account);
            $line = BankStatementLine::query()->forCompany($companyId)->whereKey($lineId)->lockForUpdate()->firstOrFail();
            if ($line->status !== 'unmatched') {
                throw new DomainException('Solo se pueden ignorar líneas de cartola sin matching.');
            }
            $this->assertOpenPeriod($account, $line->transaction_date->toDateString());
            $before = $line->toArray();
            $line->forceFill([
                'status' => 'ignored',
                'ignored_by_user_id' => $user?->id,
                'ignored_at' => now(),
                'ignore_reason' => $reason,
            ])->save();
            $this->audit->record('bank_statement_line.ignored', $line->refresh(), $user, $before);

            return $line->refresh();
        });
    }

    /** @return array{system: int, lines: int, matched: int, unmatched_system: int, unmatched_bank: int, ignored: int, bank_total: float} */
    public function summary(CashAccount $account, int $companyId, ?string $through = null): array
    {
        if ($account->company_id !== $companyId || ! $this->isEligibleAccount($account)) {
            return ['system' => 0, 'lines' => 0, 'matched' => 0, 'unmatched_system' => 0, 'unmatched_bank' => 0, 'ignored' => 0, 'bank_total' => 0.0];
        }
        $through ??= now()->toDateString();
        $lineQuery = BankStatementLine::query()
            ->forCompany($companyId)
            ->where('cash_account_id', $account->id)
            ->whereDate('transaction_date', '>', $account->opening_balance_date->toDateString())
            ->whereDate('transaction_date', '<=', $through);
        $movementQuery = $this->regularizations->forEffectiveAccount(
            CashMovement::query()
                ->forCompany($companyId)
                ->where('status', 'posted')
                ->whereDate('movement_date', '>', $account->opening_balance_date->toDateString())
                ->whereDate('movement_date', '<=', $through),
            $account
        );

        return [
            'system' => (clone $movementQuery)->count(),
            'lines' => (clone $lineQuery)->count(),
            'matched' => (clone $lineQuery)->where('status', 'matched')->count(),
            'unmatched_system' => (clone $movementQuery)->whereDoesntHave('activeBankStatementMatch')->count(),
            'unmatched_bank' => (clone $lineQuery)->where('status', 'unmatched')->count(),
            'ignored' => (clone $lineQuery)->where('status', 'ignored')->count(),
            'bank_total' => (float) (clone $lineQuery)->selectRaw("COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE -amount END), 0) as net")->value('net'),
        ];
    }

    private function assertCompatible(CashAccount $account, BankStatementLine $line, CashMovement $movement): void
    {
        if ($movement->status !== 'posted') {
            throw new DomainException('Solo se pueden vincular movimientos de caja contabilizados.');
        }
        if (! $this->regularizations->forEffectiveAccount(CashMovement::query()->forCompany($movement->company_id)->whereKey($movement->id), $account)->exists()) {
            throw new DomainException('El movimiento no pertenece efectivamente a la cuenta bancaria seleccionada.');
        }
        $amount = $line->direction === 'income' ? (float) $movement->income : (float) $movement->expense;
        $opposite = $line->direction === 'income' ? (float) $movement->expense : (float) $movement->income;
        if ($amount !== (float) $line->amount || $opposite !== 0.0) {
            throw new DomainException('El monto o el sentido del movimiento no coincide con la línea de cartola.');
        }
        $this->assertOpenPeriods($account, $line, $movement);
        if ($line->transaction_date->diffInDays($movement->movement_date) > 3) {
            throw new DomainException('La fecha del movimiento está fuera de la ventana permitida para matching.');
        }
    }

    private function assertOpenPeriods(CashAccount $account, BankStatementLine $line, CashMovement $movement): void
    {
        $this->assertOpenPeriod($account, $line->transaction_date->toDateString());
        $this->assertOpenPeriod($account, $movement->movement_date->toDateString());
    }

    private function assertOpenPeriod(CashAccount $account, string $date): void
    {
        if (Carbon::parse($date)->lte($account->opening_balance_date)) {
            throw new DomainException('La fecha de la cartola o del movimiento debe ser posterior al saldo inicial.');
        }
        if (BankReconciliation::query()->forCompany($account->company_id)->where('cash_account_id', $account->id)->where('status', 'reconciled')->whereDate('reconciliation_date', '>=', $date)->exists()) {
            throw new DomainException('No se puede modificar matching de un período bancario ya conciliado.');
        }
    }

    private function assertEligibleAccount(CashAccount $account): void
    {
        if (! $this->isEligibleAccount($account)) {
            throw new DomainException('Seleccione una cuenta CLP activa con fecha de saldo inicial.');
        }
    }

    private function isEligibleAccount(CashAccount $account): bool
    {
        return $account->is_active
            && strtoupper((string) ($account->currencyCatalog?->code ?: $account->currency ?: 'CLP')) === 'CLP'
            && $account->opening_balance_date !== null;
    }

    private function score(BankStatementLine $line, CashMovement $movement): int
    {
        $days = (int) $line->transaction_date->diffInDays($movement->movement_date);
        $score = match ($days) {
            0 => 70,
            1 => 50,
            default => 30,
        };
        $reference = mb_strtolower(trim((string) $line->reference));
        $movementText = mb_strtolower(implode(' ', array_filter([
            $movement->code,
            $movement->reference,
            $movement->source_document_code,
            $movement->counterparty_name,
        ])));
        if ($reference !== '' && str_contains($movementText, $reference)) {
            $score += 20;
        }
        $description = mb_strtolower(trim($line->description));
        if ($description !== '' && (str_contains($movementText, $description) || str_contains($description, $movement->code))) {
            $score += 10;
        }

        return $score;
    }
}
