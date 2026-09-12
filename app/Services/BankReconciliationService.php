<?php

namespace App\Services;

use App\Models\BankReconciliation;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\User;
use App\Support\UiFormatter;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class BankReconciliationService
{
    public function __construct(private readonly CashAccountBalanceService $balances)
    {
    }

    public function saveDraft(int $companyId, int $accountId, string $date, float|int|string $bankBalance, ?string $notes, ?User $user = null): BankReconciliation
    {
        return DB::transaction(function () use ($companyId, $accountId, $date, $bankBalance, $notes, $user): BankReconciliation {
            $account = $this->eligibleAccount($companyId, $accountId, $date);
            $reconciliationDate = Carbon::parse($date)->toDateString();
            $systemBalance = $this->balances->balanceAt($account, $reconciliationDate);
            $bank = UiFormatter::roundAmount($bankBalance, 'CLP');
            $existing = BankReconciliation::query()->forCompany($companyId)->where('cash_account_id', $account->id)->whereDate('reconciliation_date', $reconciliationDate)->first();
            if ($existing && $existing->status === 'reconciled') {
                throw new DomainException('La conciliación ya está cerrada y no puede editarse.');
            }
            $payload = ['company_id' => $companyId, 'cash_account_id' => $account->id, 'reconciliation_date' => $reconciliationDate, 'bank_balance' => $bank, 'system_balance_snapshot' => $systemBalance, 'difference' => UiFormatter::roundAmount($bank - $systemBalance, 'CLP'), 'status' => 'draft', 'notes' => $notes, 'created_by_user_id' => $user?->id];
            if ($existing) {
                $existing->forceFill($payload)->save();
                return $existing->refresh();
            }
            return BankReconciliation::query()->forceCreate($payload);
        });
    }

    public function balanceAt(CashAccount $account, string $date): float
    {
        return $this->balances->balanceAt($account, $date);
    }

    public function reconcile(BankReconciliation $reconciliation, int $companyId, ?User $user = null): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation, $companyId, $user): BankReconciliation {
            $locked = BankReconciliation::query()->forCompany($companyId)->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw new DomainException('La conciliación ya no está en borrador.');
            }
            $account = $this->eligibleAccount($companyId, (int) $locked->cash_account_id, $locked->reconciliation_date->toDateString());
            $systemBalance = $this->balances->balanceAt($account, $locked->reconciliation_date);
            $difference = UiFormatter::roundAmount((float) $locked->bank_balance - $systemBalance, 'CLP');
            if ($difference !== 0.0) {
                throw new DomainException('La conciliación requiere diferencia cero para cerrarse.');
            }
            $locked->forceFill(['system_balance_snapshot' => $systemBalance, 'difference' => 0, 'status' => 'reconciled', 'reconciled_by_user_id' => $user?->id, 'reconciled_at' => now()])->save();
            return $locked->refresh();
        });
    }

    public function unassignedSummary(int $companyId): array
    {
        $query = CashMovement::query()->forCompany($companyId)->where('status', 'posted')->whereNull('cash_account_id');
        return ['count' => (clone $query)->count(), 'income' => (float) (clone $query)->sum('income'), 'expense' => (float) (clone $query)->sum('expense'), 'net' => (float) (clone $query)->sum(DB::raw('income - expense'))];
    }

    private function eligibleAccount(int $companyId, int $accountId, string $date): CashAccount
    {
        $account = CashAccount::query()->forCompany($companyId)->with('currencyCatalog')->find($accountId);
        if (! $account || ! $account->is_active) {
            throw new DomainException('Seleccione una cuenta CLP activa de la empresa.');
        }
        $currency = $account->currencyCatalog?->code ?: $account->currency ?: 'CLP';
        if (strtoupper($currency) !== 'CLP') {
            throw new DomainException('La conciliación V1 solo admite cuentas CLP.');
        }
        if (! $account->opening_balance_date) {
            throw new DomainException('La cuenta requiere una fecha de saldo inicial.');
        }
        if (Carbon::parse($date)->lt($account->opening_balance_date)) {
            throw new DomainException('La fecha de conciliación no puede ser anterior al saldo inicial.');
        }
        return $account;
    }
}
