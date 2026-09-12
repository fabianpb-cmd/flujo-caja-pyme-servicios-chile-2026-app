<?php

namespace App\Http\Controllers;

use App\Models\BankReconciliation;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Services\BankReconciliationService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BankReconciliationController extends Controller
{
    public function __construct(private readonly BankReconciliationService $reconciliations)
    {
    }

    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;
        $accounts = CashAccount::query()->forCompany($companyId)->with('currencyCatalog')->where('is_active', true)->whereNotNull('opening_balance_date')->get()->filter(fn (CashAccount $account): bool => strtoupper($account->currencyCatalog?->code ?: $account->currency ?: 'CLP') === 'CLP')->values();
        $selected = $accounts->firstWhere('id', (int) $request->input('cash_account_id')) ?: $accounts->first();
        $date = $request->input('reconciliation_date', now()->toDateString());
        $systemBalance = null;
        $movements = collect();
        if ($selected) {
            try {
                $systemBalance = $this->reconciliations->balanceAt($selected, $date);
            } catch (DomainException) {
                $systemBalance = null;
            }
            $movements = CashMovement::query()->forCompany($companyId)->where('cash_account_id', $selected->id)->where('status', 'posted')->whereDate('movement_date', '<=', $date)->latest('movement_date')->latest('id')->limit(20)->get();
        }
        return view('treasury.bank-reconciliation', ['accounts' => $accounts, 'selected' => $selected, 'date' => $date, 'systemBalance' => $systemBalance, 'movements' => $movements, 'reconciliations' => BankReconciliation::query()->forCompany($companyId)->with(['cashAccount', 'reconciledBy'])->latest('reconciliation_date')->limit(30)->get(), 'unassigned' => $this->reconciliations->unassignedSummary($companyId)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['cash_account_id' => ['required', 'integer'], 'reconciliation_date' => ['required', 'date'], 'bank_balance' => ['required', 'numeric'], 'notes' => ['nullable', 'string', 'max:2000']]);
        try {
            $this->reconciliations->saveDraft((int) $request->user()->company_id, (int) $validated['cash_account_id'], $validated['reconciliation_date'], $validated['bank_balance'], $validated['notes'] ?? null, $request->user());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['reconciliation' => $exception->getMessage()]);
        }
        return back()->with('status', 'Conciliación guardada como borrador.');
    }

    public function reconcile(Request $request, int $reconciliation): RedirectResponse
    {
        $item = BankReconciliation::query()->forCompany((int) $request->user()->company_id)->findOrFail($reconciliation);
        try {
            $this->reconciliations->reconcile($item, (int) $request->user()->company_id, $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['reconciliation' => $exception->getMessage()]);
        }
        return back()->with('status', 'Conciliación cerrada.');
    }
}
