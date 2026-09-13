<?php

namespace App\Http\Controllers;

use App\Models\BankStatementLine;
use App\Models\BankStatementMatch;
use App\Models\CashAccount;
use App\Services\BankStatementImportService;
use App\Services\BankStatementMatchingService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BankStatementController extends Controller
{
    public function __construct(
        private readonly BankStatementImportService $imports,
        private readonly BankStatementMatchingService $matching,
    ) {
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
            ->filter(fn (CashAccount $account): bool => strtoupper((string) ($account->currencyCatalog?->code ?: $account->currency ?: 'CLP')) === 'CLP')
            ->values();
        $selected = $accounts->firstWhere('id', (int) $request->input('cash_account_id')) ?: $accounts->first();
        $lines = BankStatementLine::query()->forCompany($companyId)->with(['bankStatementImport', 'activeMatch.cashMovement'])->where('cash_account_id', $selected?->id);
        if (in_array($request->input('status'), ['unmatched', 'matched', 'ignored'], true)) {
            $lines->where('status', $request->input('status'));
        }
        $selectedLine = null;
        $suggestions = collect();
        if ($request->filled('line_id') && $selected) {
            $selectedLine = BankStatementLine::query()->forCompany($companyId)->where('cash_account_id', $selected->id)->findOrFail((int) $request->input('line_id'));
            $suggestions = $this->matching->suggestions($selectedLine, $companyId);
        }

        return view('treasury.bank-statements', [
            'accounts' => $accounts,
            'selected' => $selected,
            'imports' => $selected ? $selected->bankStatementImports()->with('importedBy')->latest('imported_at')->limit(20)->get() : collect(),
            'lines' => $lines->latest('transaction_date')->latest('id')->paginate(30)->withQueryString(),
            'selectedLine' => $selectedLine,
            'suggestions' => $suggestions,
            'summary' => $selected ? $this->matching->summary($selected, $companyId, $request->input('through')) : null,
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cash_account_id' => ['required', 'integer'],
            'statement' => ['required', 'file', 'max:2048'],
        ]);
        try {
            $import = $this->imports->import((int) $request->user()->company_id, (int) $validated['cash_account_id'], $request->file('statement'), $request->user());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['statement' => $exception->getMessage()]);
        }

        return redirect()->route('bank-statements.index', ['cash_account_id' => $import->cash_account_id])
            ->with('status', "Cartola importada: {$import->row_count} líneas.");
    }

    public function match(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bank_statement_line_id' => ['required', 'integer'],
            'cash_movement_id' => ['required', 'integer'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
        try {
            $match = $this->matching->match((int) $request->user()->company_id, (int) $validated['bank_statement_line_id'], (int) $validated['cash_movement_id'], $request->user(), $validated['score'] ?? null);
        } catch (DomainException $exception) {
            return back()->withErrors(['matching' => $exception->getMessage()]);
        }

        return redirect()->route('bank-statements.index', ['cash_account_id' => $match->bankStatementLine->cash_account_id])
            ->with('status', 'Matching bancario confirmado.');
    }

    public function reverse(Request $request, int $match): RedirectResponse
    {
        $validated = $request->validate(['reversal_reason' => ['required', 'string', 'max:2000']]);
        try {
            $reversed = $this->matching->reverse((int) $request->user()->company_id, $match, $validated['reversal_reason'], $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['matching' => $exception->getMessage()]);
        }

        return redirect()->route('bank-statements.index', ['cash_account_id' => $reversed->bankStatementLine->cash_account_id])
            ->with('status', 'Matching bancario reversado.');
    }

    public function ignore(Request $request, int $line): RedirectResponse
    {
        $validated = $request->validate(['ignore_reason' => ['required', 'string', 'max:2000']]);
        try {
            $ignored = $this->matching->ignore((int) $request->user()->company_id, $line, $validated['ignore_reason'], $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['matching' => $exception->getMessage()]);
        }

        return redirect()->route('bank-statements.index', ['cash_account_id' => $ignored->cash_account_id])
            ->with('status', 'Línea de cartola ignorada con trazabilidad.');
    }
}
