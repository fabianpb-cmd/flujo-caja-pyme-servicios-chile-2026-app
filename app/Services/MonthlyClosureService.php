<?php

namespace App\Services;

use App\Models\MonthlyClosure;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class MonthlyClosureService
{
    public function __construct(
        private readonly CashFlowService $cashFlow,
        private readonly ReceivablesService $receivables,
        private readonly PayablesService $payables,
        private readonly AuditService $audit,
    ) {
    }

    public function close(int $companyId, CarbonInterface|string $period, ?User $user = null): MonthlyClosure
    {
        $period = Carbon::parse($period)->startOfMonth();
        $flow = $this->cashFlow->monthly($companyId, $period, 1)[0];

        $closure = MonthlyClosure::query()->updateOrCreate(
            ['company_id' => $companyId, 'period_date' => $period->toDateString()],
            [
                'opening_balance' => $flow['opening_real'],
                'closing_balance' => $flow['closing_real'],
                'cash_in' => $flow['income_real'],
                'cash_out' => round($flow['other_real'] + $flow['personnel_real'] + $flow['legal_real'], 2),
                'accounts_receivable' => $this->receivables->accountsReceivable($companyId, $period->copy()->endOfMonth()),
                'accounts_payable' => $this->payables->accountsPayable($companyId, $period->copy()->endOfMonth()),
                'status' => 'closed',
                'closed_by_user_id' => $user?->id,
                'closed_at' => now(),
            ]
        );

        $this->audit->record('monthly_closure.closed', $closure, $user);

        return $closure;
    }

    public function reopen(MonthlyClosure $closure, ?User $user = null): MonthlyClosure
    {
        $before = $closure->toArray();
        $closure->forceFill([
            'status' => 'open',
            'closed_by_user_id' => null,
            'closed_at' => null,
        ])->save();

        $this->audit->record('monthly_closure.reopened', $closure->refresh(), $user, $before);

        return $closure;
    }
}
