<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Support\UiFormatter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;

class CashAccountBalanceService
{
    public function balanceAt(CashAccount $account, CarbonInterface|string $date): float
    {
        $date = Carbon::parse($date)->startOfDay();
        $cutover = $account->opening_balance_date?->copy()->startOfDay();
        if (! $cutover) {
            throw new DomainException('La cuenta no tiene fecha de saldo inicial y no puede conciliarse.');
        }
        if ($date->lt($cutover)) {
            throw new DomainException('La fecha consultada es anterior a la fecha de saldo inicial.');
        }

        $net = CashMovement::query()
            ->forCompany($account->company_id)
            ->where('cash_account_id', $account->id)
            ->where('status', 'posted')
            ->whereDate('movement_date', '>', $cutover->toDateString())
            ->whereDate('movement_date', '<=', $date->toDateString())
            ->selectRaw('COALESCE(SUM(income - expense), 0) as net')
            ->value('net');

        $currency = $account->currencyCatalog?->code ?: $account->currency ?: 'CLP';
        return UiFormatter::roundAmount((float) $account->opening_balance + (float) $net, $currency);
    }

    public function companyBalanceAt(int $companyId, CarbonInterface|string $date): float
    {
        return CashAccount::query()
            ->forCompany($companyId)
            ->whereNotNull('opening_balance_date')
            ->get()
            ->sum(fn (CashAccount $account): float => $this->balanceAt($account, $date));
    }
}
