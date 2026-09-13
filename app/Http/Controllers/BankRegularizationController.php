<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashMovementBankAssignment;
use App\Services\CashMovementBankRegularizationService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BankRegularizationController extends Controller
{
    public function __construct(private readonly CashMovementBankRegularizationService $regularizations)
    {
    }

    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;
        $accounts = CashAccount::query()
            ->forCompany($companyId)
            ->with('currencyCatalog')
            ->where('is_active', true)
            ->whereNotNull('opening_balance_date')
            ->get()
            ->filter(fn (CashAccount $account): bool => strtoupper($account->currencyCatalog?->code ?: $account->currency ?: 'CLP') === 'CLP')
            ->values();

        $movements = CashMovement::query()
            ->forCompany($companyId)
            ->where('status', 'posted')
            ->whereNull('cash_account_id')
            ->with(['project', 'activeBankAssignment.cashAccount', 'bankAssignments' => fn ($query) => $query->with(['cashAccount', 'assignedBy', 'reversedBy'])->latest('assigned_at')]);

        if ($request->filled('date_from')) {
            $movements->whereDate('movement_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $movements->whereDate('movement_date', '<=', $request->input('date_to'));
        }
        if (in_array($request->input('direction'), ['income', 'expense'], true)) {
            $movements->where($request->input('direction'), '>', 0);
        }
        if ($request->filled('code')) {
            $movements->where('code', 'like', '%'.$request->input('code').'%');
        }
        if ($request->filled('document')) {
            $document = (string) $request->input('document');
            $movements->where(function ($query) use ($document): void {
                $query->where('source_document_code', 'like', '%'.$document.'%')
                    ->orWhere('reference', 'like', '%'.$document.'%');
            });
        }
        if ($request->filled('counterparty')) {
            $movements->where('counterparty_name', 'like', '%'.$request->input('counterparty').'%');
        }
        if ($request->filled('project_id')) {
            $movements->where('project_id', (int) $request->input('project_id'));
        }
        if ($request->input('regularization_status') === 'unregularized') {
            $movements->whereDoesntHave('activeBankAssignment');
        } elseif ($request->input('regularization_status') === 'pre_cutover') {
            $movements->whereHas('activeBankAssignment', fn ($query) => $query->where('classification', 'pre_cutover'));
        } elseif ($request->input('regularization_status') === 'post_cutover') {
            $movements->whereHas('activeBankAssignment', fn ($query) => $query->where('classification', 'post_cutover'));
        } elseif ($request->input('regularization_status') === 'reversed') {
            $movements->whereDoesntHave('activeBankAssignment')
                ->whereHas('bankAssignments', fn ($query) => $query->where('status', 'reversed'));
        }

        return view('treasury.bank-regularization', [
            'accounts' => $accounts,
            'movements' => $movements->latest('movement_date')->latest('id')->paginate(30)->withQueryString(),
            'history' => CashMovementBankAssignment::query()
                ->forCompany($companyId)
                ->with(['cashMovement', 'cashAccount', 'assignedBy', 'reversedBy'])
                ->latest('assigned_at')
                ->limit(50)
                ->get(),
        ]);
    }

    public function assign(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cash_movement_id' => ['required', 'integer'],
            'cash_account_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->regularizations->assign(
                (int) $request->user()->company_id,
                (int) $validated['cash_movement_id'],
                (int) $validated['cash_account_id'],
                $validated['reason'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['regularization' => $exception->getMessage()]);
        }

        return back()->with('status', 'Movimiento regularizado para conciliación bancaria.');
    }

    public function reverse(Request $request, int $assignment): RedirectResponse
    {
        $validated = $request->validate(['reversal_reason' => ['required', 'string', 'max:2000']]);

        try {
            $this->regularizations->reverse(
                (int) $request->user()->company_id,
                $assignment,
                $validated['reversal_reason'],
                $request->user()
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['regularization' => $exception->getMessage()]);
        }

        return back()->with('status', 'Regularización bancaria reversada.');
    }
}
