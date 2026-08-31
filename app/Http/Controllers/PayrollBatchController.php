<?php

namespace App\Http\Controllers;

use App\Services\PayrollBatchService;
use App\Support\PeriodInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PayrollBatchController extends Controller
{
    public function __construct(private readonly PayrollBatchService $batch)
    {
    }

    public function generate(Request $request): RedirectResponse
    {
        $period = $this->validatedPeriod($request);
        $summary = $this->batch->generate($request->user()->company_id, $period);

        return redirect()
            ->route('operational.index', ['resource' => 'payroll-records', 'period' => $period->format('m/Y')])
            ->with('payroll_batch_summary', $summary)
            ->with('status', 'Generación de remuneraciones ejecutada.');
    }

    public function recalculateDrafts(Request $request): RedirectResponse
    {
        $period = $this->validatedPeriod($request);
        $summary = $this->batch->generate($request->user()->company_id, $period, true);

        return redirect()
            ->route('operational.index', ['resource' => 'payroll-records', 'period' => $period->format('m/Y')])
            ->with('payroll_batch_summary', $summary)
            ->with('status', 'Borradores recalculados.');
    }

    private function validatedPeriod(Request $request): Carbon
    {
        $request->validate([
            'period' => ['required', 'string', 'max:10'],
        ]);

        $period = PeriodInput::parse($request->input('period'));
        if ($period) {
            return $period;
        }

        abort(422, 'Período inválido. Use mm/aaaa.');
    }
}
