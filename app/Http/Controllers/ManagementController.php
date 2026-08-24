<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\PayrollRecord;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Services\BudgetService;
use App\Services\CashFlowService;
use App\Services\DashboardService;
use App\Services\LegalObligationService;
use App\Services\LegalParameterService;
use App\Services\ProfitabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ManagementController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly CashFlowService $cashFlow,
        private readonly ProfitabilityService $profitability,
        private readonly BudgetService $budgets,
        private readonly LegalObligationService $obligations,
        private readonly LegalParameterService $legalParameters,
    ) {
    }

    public function dashboard(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;
        $scenario = $request->string('scenario')->toString() ?: null;

        $this->obligations->syncMonthlyObligations($companyId, now()->startOfMonth(), 12);

        $data = $this->dashboard->data($companyId, $scenario);
        $flows = collect($data['flows']);
        $profitability = collect($data['profitability']);
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $ufInfo = $this->legalParameters->latestOfficialUfOnOrBefore($companyId, now());

        $expenseDocuments = ExpenseDocument::query()
            ->forCompany($companyId)
            ->whereNotNull('code')
            ->pluck('category', 'code');

        $expenseBreakdown = CashMovement::query()
            ->forCompany($companyId)
            ->whereBetween('movement_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->where('expense', '>', 0)
            ->get(['source_document_type', 'source_document_code', 'expense'])
            ->map(function (CashMovement $movement) use ($expenseDocuments): array {
                $label = match ($movement->source_document_type) {
                    'expense_document' => $expenseDocuments[$movement->source_document_code] ?? 'Egresos operacionales',
                    'payroll_record' => 'Remuneraciones',
                    'legal_obligation' => 'Obligaciones',
                    default => 'Otros',
                };

                return [
                    'label' => $label !== '' ? $label : 'Otros',
                    'amount' => (float) $movement->expense,
                ];
            })
            ->groupBy('label')
            ->map(fn (Collection $rows, string $label): array => [
                'label' => $label,
                'amount' => round($rows->sum('amount'), 2),
            ])
            ->sortByDesc('amount')
            ->values();

        $upcomingObligations = LegalObligation::query()
            ->forCompany($companyId)
            ->whereIn('status', ['Pendiente', 'Parcial'])
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->orderBy('due_date')
            ->limit(5)
            ->get(['code', 'obligation_type', 'period_date', 'due_date', 'pending_amount']);

        $paymentsWithoutDate = PayrollRecord::query()
            ->forCompany($companyId)
            ->whereNull('payment_date')
            ->count();

        $receivableDocuments = SalesDocument::query()
            ->forCompany($companyId)
            ->whereNotIn('status', ['Pagado', 'Anulado'])
            ->count();

        $payableDocuments = ExpenseDocument::query()
            ->forCompany($companyId)
            ->whereNotIn('payment_status', ['Pagado', 'Anulado'])
            ->count();

        $actions = collect([
            ['label' => 'Nueva Factura', 'icon' => 'bi bi-receipt', 'route' => 'operational.create', 'params' => ['sales-documents'], 'tone' => 'success'],
            ['label' => 'Nuevo Gasto', 'icon' => 'bi bi-cart3', 'route' => 'operational.create', 'params' => ['expense-documents'], 'tone' => 'danger'],
            ['label' => 'Registro de Horas', 'icon' => 'bi bi-clock-history', 'route' => 'operational.create', 'params' => ['time-entries'], 'tone' => 'info'],
            ['label' => 'Pago a Proveedor', 'icon' => 'bi bi-wallet2', 'route' => 'operational.create', 'params' => ['cash-movements'], 'tone' => 'warning'],
            ['label' => 'Cobro a Cliente', 'icon' => 'bi bi-currency-dollar', 'route' => 'operational.create', 'params' => ['cash-movements'], 'tone' => 'success'],
            ['label' => 'Nueva Obligación', 'icon' => 'bi bi-clipboard2-check', 'route' => 'operational.create', 'params' => ['legal-obligations'], 'tone' => 'warning'],
            ['label' => 'Ver Flujo de Caja', 'icon' => 'bi bi-graph-up-arrow', 'route' => 'management.flows', 'params' => [], 'tone' => 'info'],
        ])->filter(fn (array $action): bool => route($action['route'], $action['params']) !== '');

        return view('management.dashboard', [
            'data' => $data,
            'scenario' => $scenario,
            'dashboardMeta' => [
                'updated_at' => now(),
                'from' => $flows->first()['period'] ?? $monthStart->toDateString(),
                'to' => $flows->last()['period'] ?? $monthEnd->toDateString(),
                'receivable_documents' => $receivableDocuments,
                'payable_documents' => $payableDocuments,
                'payments_without_date' => $paymentsWithoutDate,
            ],
            'expenseBreakdown' => $expenseBreakdown,
            'upcomingObligations' => $upcomingObligations,
            'ufInfo' => $ufInfo,
            'priorityProjects' => $profitability
                ->sortBy(fn (array $row) => match ($row['status']) {
                    'Pérdida' => 0,
                    'Bajo mínimo' => 1,
                    default => 2,
                })
                ->take(5)
                ->values(),
            'actions' => $actions->values(),
        ]);
    }

    public function obligations(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;
        $period = Carbon::parse($request->input('period', now()->toDateString()))->startOfMonth();

        $this->obligations->syncMonthlyObligations($companyId, $period, 12);

        $rows = LegalObligation::query()
            ->forCompany($companyId)
            ->whereBetween('period_date', [$period->copy()->startOfMonth(), $period->copy()->addMonths(11)->endOfMonth()])
            ->orderBy('period_date')
            ->orderBy('obligation_type')
            ->paginate(24)
            ->withQueryString();

        return view('management.obligations', compact('rows', 'period'));
    }

    public function budgets(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;
        $period = Carbon::parse($request->input('period', now()->toDateString()))->startOfMonth();
        $projectId = $request->integer('project_id') ?: null;

        $budgetRows = Budget::query()
            ->forCompany($companyId)
            ->with('project')
            ->whereBetween('period_date', [$period->copy()->startOfMonth(), $period->copy()->addMonths(11)->endOfMonth()])
            ->orderBy('period_date')
            ->paginate(24)
            ->through(function (Budget $budget) {
                return array_merge($budget->toArray(), $this->budgets->varianceForBudget($budget));
            })
            ->withQueryString();

        $companyVariance = $this->budgets->variance($companyId, $period, $projectId);

        return view('management.budgets', compact('budgetRows', 'companyVariance', 'period', 'projectId'));
    }

    public function flows(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;
        $period = Carbon::parse($request->input('period', now()->toDateString()))->startOfMonth();
        $scenario = $request->string('scenario')->toString() ?: null;

        $this->obligations->syncMonthlyObligations($companyId, $period, 12);

        return view('management.flows', [
            'monthly' => $this->cashFlow->monthly($companyId, $period, 12, $scenario),
            'weekly' => $this->cashFlow->weekly($companyId, $period, 12, $scenario),
            'period' => $period,
            'scenario' => $scenario,
        ]);
    }

    public function profitability(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;
        $query = trim((string) $request->input('q'));
        $status = trim((string) $request->input('status'));
        $period = trim((string) $request->input('period'));
        $clientId = $request->integer('client_id') ?: null;
        $projectId = $request->integer('project_id') ?: null;
        $projectStatus = trim((string) $request->input('project_status'));

        $rows = collect($this->profitability->byProject($companyId, [
            'period' => $period !== '' ? $period.'-01' : null,
            'client_id' => $clientId,
            'project_id' => $projectId,
            'project_status' => $projectStatus,
        ]))
            ->when($query !== '', function ($collection) use ($query) {
                return $collection->filter(fn (array $row): bool => str_contains(mb_strtolower($row['project_name'].' '.$row['project_code'].' '.$row['client_name']), mb_strtolower($query)));
            })
            ->when($status !== '', fn ($collection) => $collection->where('status', $status))
            ->values();
        $summary = $this->profitability->costSummary($companyId, [
            'period' => $period !== '' ? $period.'-01' : null,
        ]);

        $clients = Client::query()
            ->forCompany($companyId)
            ->orderBy('legal_name')
            ->get(['id', 'legal_name']);

        $projects = Project::query()
            ->forCompany($companyId)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $projectStatuses = Project::query()
            ->forCompany($companyId)
            ->with('projectStatus')
            ->get()
            ->map(fn (Project $project) => [
                'code' => $project->projectStatus?->code ?: $project->project_status,
                'name' => $project->projectStatus?->name ?: $project->project_status,
            ])
            ->filter(fn (array $row) => filled($row['code']))
            ->unique('code')
            ->values();

        return view('management.profitability', compact(
            'rows',
            'query',
            'status',
            'summary',
            'period',
            'clientId',
            'projectId',
            'projectStatus',
            'clients',
            'projects',
            'projectStatuses',
        ));
    }
}
