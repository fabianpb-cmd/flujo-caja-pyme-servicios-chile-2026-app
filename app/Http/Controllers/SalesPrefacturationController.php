<?php

namespace App\Http\Controllers;

use App\Services\SalesPrefacturationService;
use App\Support\PeriodInput;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SalesPrefacturationController extends Controller
{
    public function __construct(private readonly SalesPrefacturationService $prefacturation)
    {
    }

    public function preview(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $preview = $this->prefacturation->calculate(
                companyId: $request->user()->company_id,
                projectId: (int) $data['project_id'],
                periodDate: $this->period($data['period']),
                issueDate: $data['issue_date'],
                taxable: (bool) $data['taxable'],
                adjustmentAmount: $data['adjustment_amount'] ?? 0,
                adjustmentReason: $data['adjustment_reason'] ?? null,
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['prefacturacion' => $exception->getMessage()]);
        }

        return redirect()
            ->route('operational.index', ['resource' => 'sales-documents'])
            ->withInput($data)
            ->with('sales_prefacturation_preview', $preview);
    }

    public function generateDraft(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['period'] = $this->period($data['period'])->toDateString();

        try {
            $document = $this->prefacturation->generateDraft($request->user()->company_id, $data);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['prefacturacion' => $exception->getMessage()]);
        }

        return redirect()
            ->route('operational.show', ['resource' => 'sales-documents', 'record' => $document->id])
            ->with('status', 'Borrador de prefacturación generado.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'period' => ['required', 'string', 'max:10'],
            'issue_date' => ['required', 'date'],
            'taxable' => ['required', 'boolean'],
            'adjustment_amount' => ['nullable', 'numeric'],
            'adjustment_reason' => ['nullable', 'string'],
        ]);
    }

    private function period(string $raw): Carbon
    {
        $period = PeriodInput::parse($raw);
        if ($period) {
            return $period;
        }

        abort(422, 'Período inválido. Use mm/aaaa.');
    }
}
