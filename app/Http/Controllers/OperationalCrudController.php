<?php

namespace App\Http\Controllers;

use App\Http\Requests\CrudResourceRequest;
use App\Models\CashMovement;
use App\Models\ExpenseDocument;
use App\Models\Currency;
use App\Models\LegalObligation;
use App\Models\PayrollAdjustment;
use App\Models\Person;
use App\Models\PayrollRecord;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\SalesDocument;
use App\Models\TimeEntry;
use App\Policies\CompanyOwnedPolicy;
use App\Services\AuditService;
use App\Services\CashMovementService;
use App\Services\FinancialDocumentGuard;
use App\Services\CatalogService;
use App\Services\HourlyRateService;
use App\Services\HourlyCostService;
use App\Services\LegalObligationService;
use App\Services\OperationalDependencyService;
use App\Services\PayablesService;
use App\Services\PayrollService;
use App\Services\ProjectCommitmentService;
use App\Services\SalesPrefacturationService;
use App\Services\ReceivablesService;
use App\Services\TimeEntryPeriodService;
use App\Support\MassAssignment;
use App\Support\UiFormatter;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OperationalCrudController extends Controller
{
    public function __construct(
        private readonly CompanyOwnedPolicy $policy,
        private readonly CashMovementService $cashMovements,
        private readonly ReceivablesService $receivables,
        private readonly PayablesService $payables,
        private readonly PayrollService $payroll,
        private readonly LegalObligationService $obligations,
        private readonly ProjectCommitmentService $commitments,
        private readonly SalesPrefacturationService $salesPrefacturation,
        private readonly HourlyRateService $hourlyRates,
        private readonly HourlyCostService $hourlyCosts,
        private readonly CatalogService $catalogs,
        private readonly OperationalDependencyService $dependencies,
        private readonly TimeEntryPeriodService $timeEntryPeriods,
        private readonly AuditService $audit,
        private readonly FinancialDocumentGuard $financialDocuments,
    ) {
    }

    public function index(Request $request, string $resource): View
    {
        $config = $this->config($resource);
        $routeName = $request->route()?->getName();
        if ($routeName === 'receivables.index') {
            $config['title'] = 'Cuentas por cobrar';
        }
        if ($routeName === 'payables.index') {
            $config['title'] = 'Cuentas por pagar';
        }
        $this->authorizeResource($request, $config, 'viewAny');
        $search = trim((string) $request->input('q'));
        $sort = $request->string('sort')->toString();
        $direction = strtolower($request->string('direction')->toString()) === 'desc' ? 'desc' : 'asc';

        $query = $config['model']::query()
            ->with($this->relationNames($config));

        if (method_exists(new $config['model'], 'scopeForCompany')) {
            $query->forCompany($request->user()->company_id);
        }

        $sorts = $this->sortableFields($config);

        $items = $query
            ->when($search !== '', function ($query) use ($config, $search) {
                $searchableFields = collect($config['fields'])
                    ->reject(fn (array $definition): bool => in_array($definition['type'] ?? 'text', ['relation', 'checkbox', 'date'], true))
                    ->keys()
                    ->all();

                $query->where(function ($builder) use ($searchableFields, $search) {
                    foreach ($searchableFields as $index => $field) {
                        $method = $index === 0 ? 'where' : 'orWhere';
                        $builder->{$method}($field, 'like', '%'.$search.'%');
                    }
                });
            })
            ->when(isset($sorts[$sort]), function ($query) use ($sort, $direction, $sorts) {
                foreach ((array) $sorts[$sort] as $column) {
                    if ($column instanceof \Illuminate\Database\Query\Expression) {
                        $query->orderByRaw((string) $column.' '.$direction);
                    } else {
                        $query->orderBy($column, $direction);
                    }
                }
            }, fn ($query) => $query->latest('id'));

        if ($resource === 'time-entries') {
            $items = $this->paginateTimeEntryBlocks($this->presentTimeEntryBlocks($items->get()), 15, $request);
        } else {
            $items = $items->paginate(15)->withQueryString();
        }

        return view('operational.index', compact('resource', 'config', 'items', 'search', 'sort', 'direction', 'sorts'));
    }

    public function create(Request $request, string $resource): View
    {
        $config = $this->config($resource);
        $this->authorizeResource($request, $config, 'create');

        $item = new $config['model'];
        if ($resource === 'projects') {
            $item->sales_currency_id = $this->baseCurrencyId($request->user()->company_id);
        }

        return view('operational.form', [
            'resource' => $resource,
            'config' => $config,
            'item' => $item,
            'options' => $this->options($config, $request, $item),
            'codeMeta' => $this->codeMeta($config['model']),
            'payrollViewMeta' => $this->payrollViewMeta($resource),
            'payrollFormState' => [],
            'payrollHourlyCost' => null,
            'assignmentCommitmentPreview' => $resource === 'assignments' ? $this->assignmentCommitmentPreviewData($request, $item) : null,
        ]);
    }

    public function store(CrudResourceRequest $request, string $resource): RedirectResponse
    {
        $config = $this->config($resource);
        $this->authorizeResource($request, $config, 'create');

        if ($resource === 'time-entries') {
            $result = $this->timeEntryPeriods->create(
                $request->user()->company_id,
                $this->normalizeTimeEntryPeriodPayload($request->validated())
            );

            foreach ($result['created'] as $entry) {
                $this->refreshDerivedState($entry);
                $this->audit->record('operational.created', $entry->refresh(), $request->user());
            }

            return redirect()
                ->route('operational.index', $resource)
                ->with('status', sprintf(
                    'Se registró la carga: %d %s y %s.',
                    $result['days_count'],
                    $result['days_count'] === 1 ? 'día' : 'días',
                    UiFormatter::formatHours($result['total_hours'])
                ));
        }

        $validated = $request->validated();
        try {
            $this->financialDocuments->assertCreateAllowed($resource, (int) $request->user()->company_id, $validated);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['financial' => $exception->getMessage()]);
        }
        try {
            $data = $this->prepareData($request, $resource, $validated);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['payroll' => $exception->getMessage()]);
        }

        if ($resource === 'cash-movements') {
            try {
                $this->cashMovements->create($data, $request->user());
            } catch (DomainException $exception) {
                return back()->withInput()->withErrors(['cash_movement' => $exception->getMessage()]);
            }
        } else {
            try {
                $model = DB::transaction(function () use ($config, $data) {
                    $model = MassAssignment::create($config['model'], $data);

                    if ($model instanceof PayrollRecord) {
                        $this->payroll->syncHourlyTimeEntryTrace($model->refresh());
                    }

                    return $model;
                });
            } catch (DomainException $exception) {
                return back()->withInput()->withErrors(['payroll' => $exception->getMessage()]);
            }

            $this->refreshDerivedState($model);
            $this->audit->record('operational.created', $model->refresh(), $request->user());
        }

        return redirect()->route('operational.index', $resource)->with('status', 'Registro creado.');
    }

    public function show(Request $request, string $resource, int $record): View
    {
        $config = $this->config($resource);
        $item = $config['model']::query()->with($this->relationNames($config))->findOrFail($record);
        $this->authorizeResource($request, $config, 'view', $item);

        $payrollHourlyCost = $resource === 'payroll-records' ? $this->hourlyCosts->forPayroll($item) : null;
        $payrollCalculationBreakdown = $resource === 'payroll-records' ? $this->payroll->explain($item) : null;
        $salesCalculationBreakdown = $resource === 'sales-documents' ? $this->salesPrefacturation->documentBreakdown($item) : null;
        $payrollFormState = $resource === 'payroll-records' ? $this->payroll->formState($item) : [];
        if ($resource === 'time-entries' && $item instanceof TimeEntry) {
            if (filled($item->period_batch_id)) {
                $batchEntries = TimeEntry::query()
                    ->with($this->relationNames($config))
                    ->forCompany($request->user()->company_id)
                    ->where('period_batch_id', $item->period_batch_id)
                    ->orderBy('entry_date')
                    ->orderBy('id')
                    ->get();

                $item = $this->presentTimeEntryBlock($item, $batchEntries);
            } else {
                $item = $this->presentTimeEntryBlock($item);
            }
        }

        $projectCommitment = $resource === 'projects' ? $this->commitments->summarizeProject($item) : null;

        return view('operational.show', compact('resource', 'config', 'item', 'payrollHourlyCost', 'payrollCalculationBreakdown', 'salesCalculationBreakdown', 'payrollFormState', 'projectCommitment'));
    }

    public function edit(Request $request, string $resource, int $record): View|RedirectResponse
    {
        $config = $this->config($resource);
        $item = $config['model']::query()->with($this->relationNames($config))->findOrFail($record);
        $this->authorizeResource($request, $config, 'update', $item);

        if ($resource === 'time-entries' && $item instanceof TimeEntry && filled($item->period_batch_id)) {
            $batchEntries = $this->timeEntryBatchEntries($request->user()->company_id, (string) $item->period_batch_id, $this->relationNames($config));

            if ($message = $this->batchDependencyMessage($batchEntries, 'modificar')) {
                return redirect()
                    ->route('operational.show', [$resource, $item->id])
                    ->withErrors(['dependencies' => $message]);
            }

            $timeEntryBatchEditState = $this->timeEntryBatchEditState($batchEntries);

            return view('operational.form', [
                'resource' => $resource,
                'config' => $config,
                'item' => $item,
                'options' => $this->options($config, $request, $item),
                'codeMeta' => $this->codeMeta($config['model']),
                'payrollViewMeta' => $this->payrollViewMeta($resource),
                'payrollFormState' => [],
                'payrollHourlyCost' => null,
                'payrollCalculationBreakdown' => null,
                'salesCalculationBreakdown' => null,
                'assignmentCommitmentPreview' => null,
                'timeEntryBatchEditState' => $timeEntryBatchEditState,
                'timeEntryPeriodInitialPreview' => $this->timeEntryPeriods->preview($request->user()->company_id, $this->normalizeTimeEntryPeriodPayload([
                    'period_batch_id' => $timeEntryBatchEditState['period_batch_id'],
                    'person_id' => $item->person_id,
                    'project_id' => $item->project_id,
                    'activity_id' => $item->activity_id,
                    'cost_center_id' => $item->cost_center_id,
                    'approval_status_id' => $item->approval_status_id,
                    'payment_status' => $item->payment_status,
                    'period_start_date' => $timeEntryBatchEditState['period_start_date'],
                    'period_end_date' => $timeEntryBatchEditState['period_end_date'],
                    'period_total_hours' => $timeEntryBatchEditState['period_total_hours'],
                ])),
            ]);
        }

        if ($resource === 'time-entries' && $item instanceof TimeEntry) {
            if ($message = $this->dependencies->mutationMessage($item, 'modificar')) {
                return redirect()
                    ->route('operational.show', [$resource, $item->id])
                    ->withErrors(['dependencies' => $message]);
            }
        }

        return view('operational.form', [
            'resource' => $resource,
            'config' => $config,
            'item' => $item,
            'options' => $this->options($config, $request, $item),
            'codeMeta' => $this->codeMeta($config['model']),
            'payrollViewMeta' => $this->payrollViewMeta($resource),
            'payrollFormState' => $resource === 'payroll-records' ? $this->payroll->formState($item) : [],
            'payrollHourlyCost' => $resource === 'payroll-records' ? $this->hourlyCosts->forPayroll($item) : null,
            'payrollCalculationBreakdown' => $resource === 'payroll-records' ? $this->payroll->explain($item) : null,
            'salesCalculationBreakdown' => $resource === 'sales-documents' ? $this->salesPrefacturation->documentBreakdown($item) : null,
            'assignmentCommitmentPreview' => $resource === 'assignments' ? $this->assignmentCommitmentPreviewData($request, $item) : null,
        ]);
    }

    public function assignmentCommitmentPreview(Request $request): JsonResponse
    {
        abort_unless($request->route('resource') === 'assignments', 404);

        $config = $this->config('assignments');
        $this->authorizeResource($request, $config, 'create');

        $assignment = $this->assignmentDraftFromInput($request);
        $excludeAssignmentId = $request->integer('exclude_assignment_id') ?: null;

        return response()->json(
            $assignment?->project_id && $assignment->person_id
                ? $this->commitments->previewAssignment($assignment, $excludeAssignmentId)
                : [
                    'sale_net_clp' => null,
                    'sale_net_contractual' => null,
                    'sale_net_currency_code' => null,
                    'sale_net_currency_symbol' => null,
                    'sale_net_currency_minor_units' => null,
                    'current_personnel_committed_cost' => null,
                    'assignment_estimated_cost' => null,
                    'after_save_personnel_committed_cost' => null,
                    'projected_personnel_margin' => null,
                    'committed_percentage' => null,
                    'calculation_complete' => false,
                    'warnings' => ['Seleccione una persona y un proyecto para estimar el compromiso.'],
                    'negative_margin' => false,
                    'negative_margin_amount' => null,
                ]
        );
    }

    public function timeEntryPeriodPreview(Request $request): JsonResponse
    {
        abort_unless($request->route('resource') === 'time-entries', 404);

        $config = $this->config('time-entries');
        if (filled($request->input('period_batch_id'))) {
            $item = TimeEntry::query()
                ->forCompany($request->user()->company_id)
                ->where('period_batch_id', $request->input('period_batch_id'))
                ->firstOrFail();

            $this->authorizeResource($request, $config, 'update', $item);
        } else {
            $this->authorizeResource($request, $config, 'create');
        }

        return response()->json(
            $this->timeEntryPeriods->preview(
                $request->user()->company_id,
                $this->normalizeTimeEntryPeriodPayload($request->all())
            )
        );
    }

    public function confirmPayrollRecord(Request $request, string $resource, int $record): RedirectResponse
    {
        abort_unless($resource === 'payroll-records', 404);

        $config = $this->config($resource);
        $item = PayrollRecord::query()->findOrFail($record);
        $this->authorizeResource($request, $config, 'update', $item);

        try {
            $this->payroll->confirm($item, $request->user());
        } catch (DomainException $exception) {
            return redirect()
                ->route('operational.show', [$resource, $item->id])
                ->withErrors(['payroll_confirmation' => $exception->getMessage()]);
        }

        return redirect()
            ->route('operational.show', [$resource, $item->id])
            ->with('status', 'Remuneración confirmada.');
    }

    public function update(CrudResourceRequest $request, string $resource, int $record): RedirectResponse
    {
        $config = $this->config($resource);
        $item = $config['model']::query()->findOrFail($record);
        $this->authorizeResource($request, $config, 'update', $item);

        if ($resource === 'time-entries' && $item instanceof TimeEntry && filled($item->period_batch_id)) {
            $batchEntries = $this->timeEntryBatchEntries($request->user()->company_id, (string) $item->period_batch_id);

            if ($message = $this->batchDependencyMessage($batchEntries, 'modificar')) {
                return redirect()
                    ->route('operational.show', [$resource, $item->id])
                    ->withErrors(['dependencies' => $message]);
            }

            $result = $this->timeEntryPeriods->update(
                $request->user()->company_id,
                $batchEntries,
                $this->normalizeTimeEntryPeriodPayload($request->validated())
            );

            foreach ($result['created'] as $entry) {
                $this->refreshDerivedState($entry);
                $this->audit->record('operational.created', $entry->refresh(), $request->user());
            }

            foreach ($result['updated'] as $entry) {
                $this->refreshDerivedState($entry);
                $this->audit->record('operational.updated', $entry->refresh(), $request->user(), $result['updated_before'][$entry->id] ?? null);
            }

            foreach ($result['deleted'] as $entry) {
                $this->audit->record('operational.deleted', $entry, $request->user(), $result['deleted_before'][$entry->id] ?? null, null);
            }

            return redirect()
                ->route('operational.show', [$resource, $result['primary_entry']->id])
                ->with('status', sprintf(
                    'Se actualizó la carga: %d %s y %s.',
                    $result['days_count'],
                    $result['days_count'] === 1 ? 'día' : 'días',
                    UiFormatter::formatHours($result['total_hours'])
                ));
        }

        if ($resource === 'time-entries' && $item instanceof TimeEntry) {
            if ($message = $this->dependencies->mutationMessage($item, 'modificar')) {
                return redirect()
                    ->route('operational.show', [$resource, $item->id])
                    ->withErrors(['dependencies' => $message]);
            }
        }

        $validated = $request->validated();
        try {
            $this->financialDocuments->assertUpdateAllowed($item, $validated);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['financial' => $exception->getMessage()]);
        }
        try {
            $data = $this->prepareData($request, $resource, $validated);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['payroll' => $exception->getMessage()]);
        }
        if ($this->codeMeta($config['model'])['auto']) {
            unset($data['code']);
        }
        $before = $item->toArray();

        if ($item instanceof CashMovement) {
            try {
                $this->cashMovements->update($item, $data, $request->user());
            } catch (DomainException $exception) {
                return back()->withInput()->withErrors(['cash_movement' => $exception->getMessage()]);
            }

            return redirect()->route('operational.show', [$resource, $item->id])->with('status', 'Registro actualizado.');
        }

        try {
            DB::transaction(function () use ($item, $data): void {
                MassAssignment::fillAndSave($item, $data);

                if ($item instanceof PayrollRecord) {
                    $this->payroll->syncHourlyTimeEntryTrace($item->refresh());
                }
            });
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['payroll' => $exception->getMessage()]);
        }
        $this->refreshDerivedState($item->refresh());
        $this->audit->record('operational.updated', $item->refresh(), $request->user(), $before);

        return redirect()->route('operational.show', [$resource, $item->id])->with('status', 'Registro actualizado.');
    }

    public function destroy(Request $request, string $resource, int $record): RedirectResponse
    {
        $config = $this->config($resource);
        $item = $config['model']::query()->findOrFail($record);
        $this->authorizeResource($request, $config, 'delete', $item);

        if (($config['catalog'] ?? false) === true) {
            $usageCount = $this->usageCount($config, $item);
            $message = $usageCount > 0
                ? 'El mantenedor está en uso; desactívelo en lugar de eliminarlo.'
                : 'Los mantenedores no se eliminan físicamente; use activar o desactivar.';

            return redirect()->route('operational.show', [$resource, $item->id])->withErrors(['catalog' => $message]);
        }

        if ($item instanceof CashMovement) {
            try {
                $this->cashMovements->delete($item, $request->user());
            } catch (DomainException $exception) {
                return redirect()->route('operational.show', [$resource, $item->id])
                    ->withErrors(['cash_movement' => $exception->getMessage()]);
            }

            return redirect()->route('operational.index', $resource)->with('status', 'Registro eliminado.');
        }

        if ($resource === 'time-entries' && $item instanceof TimeEntry && filled($item->period_batch_id)) {
            $batchEntries = TimeEntry::query()
                ->forCompany($request->user()->company_id)
                ->where('period_batch_id', $item->period_batch_id)
                ->orderBy('entry_date')
                ->orderBy('id')
                ->get();

            if ($message = $this->batchDependencyMessage($batchEntries, 'eliminar')) {
                return redirect()->route('operational.show', [$resource, $item->id])->withErrors(['dependencies' => $message]);
            }

            DB::transaction(function () use ($batchEntries, $request): void {
                foreach ($batchEntries as $entry) {
                    $before = $entry->toArray();
                    $entry->delete();
                    $this->audit->record('operational.deleted', $entry, $request->user(), $before, null);
                }
            });

            return redirect()->route('operational.index', $resource)->with('status', 'Bloque eliminado.');
        }

        try {
            $this->financialDocuments->assertDeleteAllowed($item);
        } catch (DomainException $exception) {
            return redirect()->route('operational.show', [$resource, $item->id])
                ->withErrors(['financial' => $exception->getMessage()]);
        }

        if ($message = $this->dependencies->deletionMessage($item)) {
            return redirect()->route('operational.show', [$resource, $item->id])->withErrors(['dependencies' => $message]);
        }

        $before = $item->toArray();
        $item->delete();
        $this->audit->record('operational.deleted', $item, $request->user(), $before, null);

        return redirect()->route('operational.index', $resource)->with('status', 'Registro eliminado.');
    }

    public function toggleActive(Request $request, string $resource, int $record): RedirectResponse
    {
        $config = $this->config($resource);
        abort_unless(($config['catalog'] ?? false) === true, 404);

        $item = $config['model']::query()->findOrFail($record);
        $this->authorizeResource($request, $config, 'update', $item);

        $activeColumn = $config['active_column'] ?? 'active';
        $before = $item->toArray();
        $item->update([$activeColumn => ! $item->{$activeColumn}]);
        $item->refresh();
        $this->audit->record('operational.toggled', $item, $request->user(), $before);

        return redirect()->route('operational.index', $resource)->with('status', $item->{$activeColumn} ? 'Registro activado.' : 'Registro desactivado.');
    }

    private function config(string $resource): array
    {
        $config = config('operational.'.$resource);
        abort_unless($config, 404);

        return $config;
    }

    private function prepareData(Request $request, string $resource, array $data): array
    {
        if ($resource === 'time-entries') {
            unset(
                $data['entry_mode'],
                $data['period_start_date'],
                $data['period_end_date'],
                $data['period_distribution_mode'],
                $data['period_hours_per_day'],
                $data['period_total_hours'],
                $data['period_rows_payload'],
                $data['period_rows']
            );
        }

        if ($resource === 'payroll-records') {
            unset($data['status']);
        }

        $table = (new (config('operational.'.$resource.'.model')))->getTable();
        if (Schema::hasColumn($table, 'company_id')) {
            $data['company_id'] = $request->user()->company_id;
        }
        $data = $this->catalogs->syncLegacyFields($resource, $data);

        if ($resource === 'sales-documents') {
            $amounts = $this->receivables->amountsWithVat($data['company_id'], $data['net_amount'], $data['issue_date']);
            $data = array_merge($data, $amounts, ['status' => 'Pendiente', 'collected_amount' => $data['collected_amount'] ?? 0]);
        }

        if ($resource === 'expense-documents') {
            $amounts = $this->payables->amountsWithVat($data['company_id'], $data['net_amount'], $data['issue_date'], (bool) ($data['deductible_vat'] ?? false));
            $data = array_merge($data, $amounts, ['payment_status' => 'Pendiente', 'paid_amount' => $data['paid_amount'] ?? 0]);
        }

        if ($resource === 'time-entries') {
            $person = Person::query()->forCompany($data['company_id'])->findOrFail($data['person_id']);
            $project = \App\Models\Project::query()->forCompany($data['company_id'])->findOrFail($data['project_id']);
            $resolution = $this->hourlyRates->resolveCostingForTimeEntry($person, $project, $data['entry_date']);

            $data['client_id'] = (int) $project->client_id;
            $data['assignment_id'] = $resolution['assignment_id'] ?? $data['assignment_id'] ?? null;
            if (empty($data['cost_center_id']) && ! empty($data['assignment_id'])) {
                $data['cost_center_id'] = ProjectAssignment::query()
                    ->where('company_id', $data['company_id'])
                    ->whereKey($data['assignment_id'])
                    ->value('cost_center_id');
            }
            $data['hourly_value'] = $resolution['amount'] ?? null;
            $data['calculated_amount'] = round((float) $data['hours_approved'] * (float) ($data['hourly_value'] ?? 0), 2);
        }

        if ($resource === 'projects') {
            $data['sales_currency_id'] = (int) ($data['sales_currency_id'] ?? $this->baseCurrencyId($data['company_id']));
            $currency = Currency::query()->find($data['sales_currency_id']);
            $projectDate = $data['start_date'] ?? now()->toDateString();
            $vatRate = (float) $this->receivables->vatRate($data['company_id'], $projectDate);
            $saleNet = (float) ($data['sale_net'] ?? 0);
            if (! $this->moneyHasAllowedScale($saleNet, $currency ?: 'CLP')) {
                throw ValidationException::withMessages([
                    'sale_net' => $this->moneyScaleMessage($currency ?: 'CLP'),
                ]);
            }
            $saleNet = \App\Support\UiFormatter::roundAmount($saleNet, $currency ?: 'CLP');
            $data['vat_rate'] = $vatRate;
            $data['sale_total'] = \App\Support\UiFormatter::roundAmount($saleNet + ($saleNet * $vatRate), $currency ?: 'CLP');
            $data['sale_net'] = $saleNet;
        }

        if ($resource === 'payroll-records') {
            $period = Carbon::parse((string) $data['period_date'])->startOfMonth();
            $data['period_date'] = $period->toDateString();

            $person = Person::query()->forCompany($data['company_id'])->findOrFail($data['person_id']);
            $existingRecord = filled($request->route('record'))
                ? PayrollRecord::query()->forCompany($data['company_id'])->find((int) $request->route('record'))
                : null;
            if ($existingRecord) {
                foreach ($this->payroll->manualOverrideInputs($existingRecord) as $field => $value) {
                    if (! array_key_exists($field, $data)) {
                        $data[$field] = $value;
                    }
                }
            }
            $derived = $this->payrollRecordDefaults($data['company_id'], $person->id, $period, $data['project_id'] ?? null);

            foreach ($derived as $field => $value) {
                if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                    if ($value !== null) {
                        $data[$field] = $value;
                    }
                }
            }

            $data = array_merge($data, $this->payroll->calculate($person, $period, $data));
            $data['status'] = match (true) {
                $existingRecord && $existingRecord->status === 'Confirmado' => 'Confirmado',
                strtoupper((string) ($data['calculation_status'] ?? 'OK')) !== 'OK' => 'Requiere revisión',
                default => 'Borrador',
            };
        }

        return $data;
    }

    private function normalizeTimeEntryPeriodPayload(array $payload): array
    {
        unset(
            $payload['entry_mode'],
            $payload['period_distribution_mode'],
            $payload['period_hours_per_day'],
            $payload['period_rows_payload'],
            $payload['period_rows']
        );

        return $payload;
    }

    private function payrollRecordDefaults(int $companyId, int $personId, Carbon $period, mixed $projectId): array
    {
        $person = Person::query()->forCompany($companyId)->findOrFail($personId);
        $defaults = $this->payroll->payrollDefaultValues($person, $period, filled($projectId) ? (int) $projectId : null);

        $adjustments = PayrollAdjustment::query()
            ->forCompany($companyId)
            ->where('person_id', $personId)
            ->whereDate('period_date', $period->toDateString())
            ->where('active', true)
            ->get();

        $adjustments->each(function (PayrollAdjustment $adjustment) use (&$defaults): void {
            $amount = (float) ($adjustment->amount ?? 0);
            $quantity = (float) ($adjustment->quantity ?? 0);

            match (strtoupper((string) $adjustment->type)) {
                'BONUS_TAXABLE' => $defaults['bonuses'] = round((float) ($defaults['bonuses'] ?? 0) + $amount, 2),
                'NON_TAXABLE_ALLOWANCE' => $defaults['non_taxable_allowances'] = round((float) ($defaults['non_taxable_allowances'] ?? 0) + $amount, 2),
                'ADVANCE' => $defaults['advances'] = round((float) ($defaults['advances'] ?? 0) + $amount, 2),
                'OTHER_DEDUCTION' => $defaults['other_deductions'] = round((float) ($defaults['other_deductions'] ?? 0) + $amount, 2),
                'HEALTH_ADDITIONAL' => $defaults['health_additional'] = round((float) ($defaults['health_additional'] ?? 0) + $amount, 2),
                'HOURS_APPROVED' => $defaults['hours_approved'] = round((float) ($defaults['hours_approved'] ?? 0) + $quantity, 2),
                    'MONTHLY_VALUE' => $defaults['monthly_value'] = round($amount, 2),
                    'HOURLY_VALUE' => $defaults['hourly_value'] = round($amount, 2),
                    'PROJECT_VALUE' => $defaults['project_value'] = round($amount, 2),
                    default => null,
                };
            });

        return $defaults;
    }

    private function payrollAutoProjectId(int $companyId, int $personId, Carbon $period, mixed $projectId): ?int
    {
        if (filled($projectId)) {
            return (int) $projectId;
        }

        $assignments = $this->payrollAssignmentsForPeriod($companyId, $personId, $period, null);

        return $assignments->count() === 1 ? (int) $assignments->first()->project_id : null;
    }

    private function payrollAssignmentsForPeriod(int $companyId, int $personId, Carbon $period, ?int $projectId): \Illuminate\Support\Collection
    {
        $periodEnd = $period->copy()->endOfMonth();

        $query = ProjectAssignment::query()
            ->where('company_id', $companyId)
            ->where('person_id', $personId)
            ->whereHas('assignmentStatus', fn ($builder) => $builder->where('code', 'active'))
            ->where(function ($builder) use ($periodEnd) {
                $builder->whereNull('start_date')->orWhereDate('start_date', '<=', $periodEnd);
            })
            ->where(function ($builder) use ($period) {
                $builder->whereNull('end_date')->orWhereDate('end_date', '>=', $period->toDateString());
            })
            ->with(['project.client', 'assignmentStatus:id,code', 'hourlyRateCurrency:id,code,symbol,minor_units', 'costCenter:id,name']);

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        return $query->orderBy('start_date')->orderBy('id')->get();
    }

    private function baseCurrencyId(int $companyId): ?int
    {
        return Currency::query()
            ->forCompany($companyId)
            ->where(function ($query) {
                $query->where('code', 'CLP')->orWhere('is_base_currency', true);
            })
            ->orderByDesc('is_base_currency')
            ->value('id');
    }

    private function moneyHasAllowedScale(mixed $value, mixed $currency): bool
    {
        $string = trim((string) $value);
        if ($string === '') {
            return true;
        }

        $decimals = preg_match('/[.,](\d+)$/', $string, $matches) ? strlen($matches[1]) : 0;
        $minorUnits = (int) data_get($currency, 'minor_units', match (strtoupper((string) $currency)) {
            'CLP' => 0,
            default => 2,
        });

        return $decimals <= $minorUnits;
    }

    private function moneyScaleMessage(mixed $currency): string
    {
        return strtoupper((string) data_get($currency, 'code', $currency)) === 'CLP'
            ? 'Los montos en pesos chilenos deben ingresarse sin decimales.'
            : 'El monto supera la cantidad de decimales permitida para la moneda seleccionada.';
    }

    private function refreshDerivedState(object $model): void
    {
        if ($model instanceof SalesDocument) {
            $this->receivables->refreshDocumentState($model);
        }

        if ($model instanceof ExpenseDocument) {
            $this->payables->refreshDocumentState($model);
        }

        if ($model instanceof PayrollRecord) {
            $this->payroll->refreshStatus($model);
        }
    }

    private function presentTimeEntryBlocks(Collection $entries): Collection
    {
        $dailyRows = $entries
            ->filter(fn (TimeEntry $entry): bool => blank($entry->period_batch_id))
            ->map(fn (TimeEntry $entry): TimeEntry => $this->presentTimeEntryBlock($entry));

        $batchRows = $entries
            ->filter(fn (TimeEntry $entry): bool => filled($entry->period_batch_id))
            ->groupBy(fn (TimeEntry $entry): string => (string) $entry->period_batch_id)
            ->map(fn (Collection $group): TimeEntry => $this->presentTimeEntryBlock($group->first(), $group));

        return $dailyRows
            ->concat($batchRows)
            ->sortByDesc(fn (TimeEntry $entry): int => (int) ($entry->getAttribute('_presentation_sort_key') ?? $entry->id))
            ->values();
    }

    private function paginateTimeEntryBlocks(Collection $rows, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->integer('page', 1));
        $total = $rows->count();
        $items = $rows->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );
    }

    private function presentTimeEntryBlock(TimeEntry $entry, ?Collection $group = null): TimeEntry
    {
        $presented = clone $entry;
        $group = $group ?? collect([$entry]);
        $ordered = $group
            ->sortBy(fn (TimeEntry $row): string => sprintf(
                '%s-%09d',
                optional($row->entry_date)->format('Y-m-d') ?? '9999-12-31',
                (int) $row->id
            ))
            ->values();

        $isBatch = filled($presented->period_batch_id);
        $first = $ordered->first();
        $last = $ordered->last();
        $entryDates = $ordered->map(fn (TimeEntry $row): string => optional($row->entry_date)->format('d/m/Y') ?? '—');
        $dateDisplay = $entryDates->unique()->count() > 1
            ? $entryDates->first().' - '.$entryDates->last()
            : $entryDates->first();

        $presented->setAttribute('code', $isBatch ? $this->timeEntryBatchCodeDisplay($ordered) : $entry->code);
        $presented->setAttribute('hours_worked', $isBatch ? round((float) $ordered->sum('hours_worked'), 2) : $entry->hours_worked);
        $presented->setAttribute('hours_approved', $isBatch ? round((float) $ordered->sum('hours_approved'), 2) : $entry->hours_approved);
        $presented->setAttribute('period_batch_entry_count', $isBatch ? $ordered->count() : 1);
        $presented->setAttribute('period_batch_date_display', $isBatch ? $dateDisplay : null);
        $presented->setAttribute('period_batch_entry_date_display', $isBatch ? $dateDisplay : null);
        $presented->setAttribute('period_batch_code_display', $isBatch ? $this->timeEntryBatchCodeDisplay($ordered) : null);
        $presented->setAttribute('period_batch_hourly_value_display', $isBatch ? $this->timeEntryBatchRateDisplay($ordered) : null);
        $presented->setAttribute('period_batch_approval_status_display', $isBatch ? $this->timeEntryBatchApprovalDisplay($ordered) : null);
        $presented->setAttribute('period_batch_payment_status_display', $isBatch ? $this->timeEntryBatchPaymentDisplay($ordered) : null);
        $presented->setAttribute('time_entry_update_blocked_message', $isBatch
            ? $this->batchDependencyMessage($ordered, 'modificar')
            : $this->dependencies->mutationMessage($entry, 'modificar'));
        $presented->setAttribute('_presentation_sort_key', (int) $last->id);

        if ($isBatch) {
            $presented->setAttribute('period_batch_first_id', $first?->id);
            $presented->setAttribute('period_batch_last_id', $last?->id);
            $presented->setAttribute('period_batch_min_date', optional($ordered->first()->entry_date)->toDateString());
            $presented->setAttribute('period_batch_max_date', optional($ordered->last()->entry_date)->toDateString());
        }

        return $presented;
    }

    private function timeEntryBatchCodeDisplay(Collection $entries): string
    {
        $sorted = $entries->sortBy(fn (TimeEntry $entry): string => sprintf(
            '%s-%09d',
            optional($entry->entry_date)->format('Y-m-d') ?? '9999-12-31',
            (int) $entry->id
        ))->values();

        if ($sorted->count() <= 1) {
            return (string) $sorted->first()?->code;
        }

        if ($this->timeEntryBatchCodesAreConsecutive($sorted)) {
            return (string) $sorted->first()?->code.'–'.(string) $sorted->last()?->code;
        }

        return (string) $sorted->first()?->code.' + '.($sorted->count() - 1).' '.($sorted->count() === 2 ? 'registro' : 'registros');
    }

    private function timeEntryBatchCodesAreConsecutive(Collection $entries): bool
    {
        $previous = null;

        foreach ($entries as $entry) {
            $parsed = $this->parseTimeEntryCodeSequence($entry->code);

            if ($parsed === null) {
                return false;
            }

            if ($previous === null) {
                $previous = $parsed;

                continue;
            }

            if ($parsed['prefix'] !== $previous['prefix'] || $parsed['width'] !== $previous['width']) {
                return false;
            }

            if ($parsed['number'] !== $previous['number'] + 1) {
                return false;
            }

            $previous = $parsed;
        }

        return true;
    }

    /**
     * @return array{prefix: string, number: int, width: int}|null
     */
    private function parseTimeEntryCodeSequence(?string $code): ?array
    {
        $code = trim((string) $code);

        if ($code === '' || preg_match('/^(.*?)(\d+)$/', $code, $matches) !== 1) {
            return null;
        }

        return [
            'prefix' => $matches[1],
            'number' => (int) $matches[2],
            'width' => strlen($matches[2]),
        ];
    }

    private function timeEntryBatchRateDisplay(Collection $entries): string
    {
        $signatures = $entries->map(function (TimeEntry $entry): string {
            $currency = UiFormatter::currencyCode($entry->hourlyRateDisplayCurrency ?? 'CLP');
            $amount = number_format((float) ($entry->hourly_value ?? 0), 2, '.', '');

            return $amount.'|'.$currency;
        })->unique()->values();

        if ($signatures->count() !== 1) {
            return 'Variable';
        }

        $entry = $entries->first();

        return $entry?->hourly_value !== null
            ? trim(UiFormatter::formatMoney($entry->hourly_value, $entry->hourlyRateDisplayCurrency).' / HH')
            : '—';
    }

    private function timeEntryBatchApprovalDisplay(Collection $entries): string
    {
        $labels = $entries
            ->map(fn (TimeEntry $entry): string => (string) ($entry->approvalStatus?->name ?: $entry->approval_status ?: '—'))
            ->unique()
            ->values();

        return $labels->count() === 1 ? $labels->first() : 'Mixto';
    }

    private function timeEntryBatchPaymentDisplay(Collection $entries): string
    {
        $labels = $entries
            ->map(fn (TimeEntry $entry): string => match (strtolower((string) $entry->payment_status)) {
                'paid' => 'Pagado',
                default => 'Pendiente',
            })
            ->unique()
            ->values();

        return $labels->count() === 1 ? $labels->first() : 'Mixto';
    }

    private function batchDependencyMessage(Collection $entries, string $action): ?string
    {
        $blockers = $entries->flatMap(fn (TimeEntry $entry) => $this->dependencies->blockers($entry))
            ->groupBy('label')
            ->map(fn (Collection $group, string $label): array => [
                'label' => $label,
                'count' => $group->sum('count'),
            ])
            ->values();

        if ($blockers->isEmpty()) {
            return null;
        }

        $references = $blockers
            ->map(fn (array $dependency): string => $dependency['count'].' '.$dependency['label'])
            ->implode(', ');

        return sprintf(
            'No se puede %s la carga de horas porque está siendo utilizada por: %s. Desactívela o reasigne las dependencias antes de continuar.',
            $action,
            $references
        );
    }

    private function timeEntryBatchEntries(int $companyId, string $batchId, array $relations = []): Collection
    {
        return TimeEntry::query()
            ->with($relations)
            ->forCompany($companyId)
            ->where('period_batch_id', $batchId)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();
    }

    private function timeEntryBatchEditState(Collection $entries): array
    {
        $ordered = $entries
            ->sortBy(fn (TimeEntry $entry): string => sprintf(
                '%s-%09d',
                optional($entry->entry_date)->format('Y-m-d') ?? '9999-12-31',
                (int) $entry->id
            ))
            ->values();

        return [
            'period_batch_id' => (string) $ordered->first()?->period_batch_id,
            'period_start_date' => optional($ordered->first()?->entry_date)->toDateString(),
            'period_end_date' => optional($ordered->last()?->entry_date)->toDateString(),
            'period_total_hours' => round((float) $ordered->sum('hours_worked'), 2),
        ];
    }

    private function options(array $config, Request $request, ?Model $item = null): array
    {
        $options = [];
        $resource = (string) $request->route('resource');
        $effectiveDate = $this->effectiveDateForResource($resource, $request, $item);

        foreach ($config['fields'] as $field => $definition) {
            if (($definition['type'] ?? null) !== 'relation') {
                continue;
            }

            $query = $definition['model']::query();
            if (($definition['global'] ?? false) !== true && method_exists(new $definition['model'], 'scopeForCompany')) {
                $query->forCompany($request->user()->company_id);
            }

            foreach (($definition['where'] ?? []) as $column => $value) {
                $query->where($column, $value);
            }

            $selectedId = $item?->{$field} ?? null;
            $activeColumn = $definition['active_column']
                ?? (Schema::hasColumn((new $definition['model'])->getTable(), 'active')
                    ? 'active'
                    : (Schema::hasColumn((new $definition['model'])->getTable(), 'is_active') ? 'is_active' : null));
            if ($activeColumn) {
                $keyName = (new $definition['model'])->getKeyName();
                $query->where(function ($builder) use ($selectedId, $keyName, $activeColumn) {
                    $builder->where($activeColumn, true);
                    if ($selectedId) {
                        $builder->orWhere($keyName, $selectedId);
                    }
                });
            }

            $this->applyVigencyFilter($query, $definition['model'], $resource, $field, $effectiveDate, $selectedId);

            $records = $query->orderBy($definition['display'])->get();

            $options[$field] = $records->mapWithKeys(function ($record) use ($definition, $config, $field, $resource, $effectiveDate, $selectedId) {
                $label = $record->{$definition['display']};
                if ((string) $record->getKey() === (string) $selectedId && ! $this->isVigentRecord($record, $resource, $field, $effectiveDate)) {
                    $label .= ' (No vigente)';
                }

                $payload = ['id' => $record->id, 'label' => $label];

                if (isset($definition['option_parent_key'])) {
                    $payload['parent_id'] = $record->{$definition['option_parent_key']};
                }

                if ($record instanceof Person && in_array($resource, ['assignments', 'time-entries', 'payroll-records'], true)) {
                    $record->loadMissing(['hourlyRateCurrency']);
                    $payload += [
                        'person_rate_amount' => $record->hourly_value,
                        'person_rate_unit_type' => strtoupper((string) ($record->hourly_rate_unit_type ?: 'CURRENCY')),
                        'person_rate_currency_code' => $record->hourlyRateCurrency?->code ?: 'CLP',
                        'person_rate_currency_symbol' => $record->hourlyRateCurrency?->symbol ?: '$',
                        'person_rate_minor_units' => $record->hourlyRateCurrency?->minor_units ?? 0,
                        'person_rate_label' => trim((string) ('Persona · '.($record->full_name ?: $record->name ?: 'No informado'))),
                    ];
                }

                if (($config['model'] ?? null) === PayrollRecord::class && $field === 'person_id' && $record instanceof Person) {
                    $record->loadMissing(['employmentMode', 'employmentContractType', 'afp', 'healthSystemCatalog']);
                    $payload += [
                        'payroll_mode' => strtoupper((string) ($record->employmentMode?->code ?: $record->modality ?: '')),
                        'payroll_mode_label' => $record->employmentMode?->name ?: $record->modality,
                        'payroll_contract_label' => $record->employmentContractType?->name ?: $record->contract_type,
                        'payroll_afp_label' => $record->afp?->name,
                        'payroll_health_label' => $record->healthSystemCatalog?->name ?: $record->health_system,
                        'payroll_monthly_value' => $record->monthly_value,
                        'payroll_hourly_value' => $record->hourly_value,
                        'payroll_hourly_currency' => $record->hourly_rate_display_currency,
                        'payroll_start_date' => optional($record->start_date)->format('Y-m-d'),
                        'payroll_end_date' => optional($record->end_date)->format('Y-m-d'),
                    ];
                }

                if (($definition['model'] ?? null) === \App\Models\Currency::class) {
                    $payload['currency_code'] = $record->code;
                    $payload['currency_symbol'] = $record->symbol;
                }

                if ($resource === 'time-entries' && $field === 'project_id' && $record instanceof \App\Models\Project) {
                    $record->loadMissing(['client', 'salesCurrency']);
                    $assignmentRanges = \App\Models\ProjectAssignment::query()
                        ->where('company_id', $record->company_id)
                        ->where('project_id', $record->id)
                        ->with(['assignmentStatus:id,code', 'hourlyRateCurrency:id,code,symbol,minor_units', 'costCenter:id,name'])
                        ->get()
                        ->filter(fn ($assignment) => strtolower((string) $assignment->assignmentStatus?->code) === 'active')
                        ->map(fn ($assignment) => [
                            'id' => $assignment->id,
                            'code' => $assignment->code,
                            'person_id' => $assignment->person_id,
                            'start_date' => optional($assignment->start_date)->format('Y-m-d'),
                            'end_date' => optional($assignment->end_date)->format('Y-m-d'),
                            'hourly_value' => $assignment->hourly_value,
                            'hourly_rate_unit_type' => strtoupper((string) ($assignment->hourly_rate_unit_type ?: 'CURRENCY')),
                            'currency_code' => $assignment->hourlyRateCurrency?->code,
                            'currency_symbol' => $assignment->hourlyRateCurrency?->symbol,
                            'currency_minor_units' => $assignment->hourlyRateCurrency?->minor_units,
                            'cost_center_id' => $assignment->cost_center_id,
                            'cost_center_name' => $assignment->costCenter?->name,
                            'project_name' => $assignment->project?->name ?: $record->name,
                            'source_label' => trim((string) (($assignment->code ?: 'Asignación').' · '.($assignment->project?->name ?: $record->name ?: 'No informado'))),
                        ])->values()->all();

                    $payload += [
                        'client_id' => $record->client_id,
                        'client_label' => $record->client?->legal_name,
                        'project_name' => $record->name,
                        'project_rate_amount' => $record->contracted_hourly_rate,
                        'project_rate_unit_type' => 'CURRENCY',
                        'project_rate_currency_code' => $record->salesCurrency?->code ?: 'CLP',
                        'project_rate_currency_symbol' => $record->salesCurrency?->symbol ?: '$',
                        'project_rate_minor_units' => $record->salesCurrency?->minor_units ?? 0,
                        'assignment_ranges' => $assignmentRanges,
                    ];
                }

                if ($resource === 'assignments' && $field === 'project_id' && $record instanceof \App\Models\Project) {
                    $record->loadMissing(['client', 'salesCurrency']);
                    $payload += [
                        'project_sale_net' => $record->sale_net,
                        'project_sale_currency_code' => $record->salesCurrency?->code ?: 'CLP',
                        'project_sale_currency_symbol' => $record->salesCurrency?->symbol ?: '$',
                        'project_sale_minor_units' => $record->salesCurrency?->minor_units ?? 0,
                        'project_rate_amount' => $record->contracted_hourly_rate,
                        'project_rate_unit_type' => 'CURRENCY',
                        'project_rate_currency_code' => $record->salesCurrency?->code ?: 'CLP',
                        'project_rate_currency_symbol' => $record->salesCurrency?->symbol ?: '$',
                        'project_rate_minor_units' => $record->salesCurrency?->minor_units ?? 0,
                        'project_start_date' => optional($record->start_date)->format('Y-m-d'),
                        'project_end_date' => optional($record->end_date)->format('Y-m-d'),
                    ];
                }

                if ($resource === 'payroll-records' && $field === 'project_id') {
                    $record->loadMissing(['client', 'salesCurrency']);
                    $ranges = \App\Models\ProjectAssignment::query()
                        ->where('company_id', $record->company_id)
                        ->where('project_id', $record->id)
                        ->with(['assignmentStatus:id,code', 'person:id,name', 'hourlyRateCurrency:id,code,symbol,minor_units', 'costCenter:id,name'])
                        ->get()
                        ->filter(fn ($assignment) => strtolower((string) $assignment->assignmentStatus?->code) === 'active')
                        ->map(fn ($assignment) => [
                            'id' => $assignment->id,
                            'code' => $assignment->code,
                            'person_id' => $assignment->person_id,
                            'person_name' => $assignment->person?->name,
                            'start_date' => optional($assignment->start_date)->format('Y-m-d'),
                            'end_date' => optional($assignment->end_date)->format('Y-m-d'),
                            'hourly_value' => $assignment->hourly_value,
                            'project_value' => $assignment->project_value,
                            'hourly_rate_unit_type' => strtoupper((string) ($assignment->hourly_rate_unit_type ?: 'CURRENCY')),
                            'currency_code' => $assignment->hourlyRateCurrency?->code,
                            'currency_symbol' => $assignment->hourlyRateCurrency?->symbol,
                            'currency_minor_units' => $assignment->hourlyRateCurrency?->minor_units,
                            'cost_center_name' => $assignment->costCenter?->name,
                            'project_name' => $record->name,
                            'client_name' => $record->client?->legal_name,
                            'source_label' => trim((string) (($assignment->code ?: 'Asignación').' · '.($record->name ?: 'No informado'))),
                        ])->values()->all();

                    $payload['assignment_ranges'] = $ranges;
                }

                return [$record->id => $payload];
            })->all();
        }

        if ($resource === 'cash-movements') {
            $options['source_document_code'] = $this->cashMovementSourceDocumentOptions($request->user()->company_id, $item);
        }

        return $options;
    }

    private function cashMovementSourceDocumentOptions(int $companyId, ?Model $item = null): array
    {
        $options = [];
        $currentSourceType = (string) ($item?->source_document_type ?? '');
        $currentSourceCode = (string) ($item?->source_document_code ?? '');

        $salesDocuments = SalesDocument::query()
            ->forCompany($companyId)
            ->where('is_voided', false)
            ->whereNotIn('status', ['Borrador', 'Anulado'])
            ->orderBy('due_date')
            ->orderBy('code')
            ->get();
        $clientNames = \App\Models\Client::query()
            ->whereIn('id', $salesDocuments->pluck('client_id')->filter()->unique())
            ->pluck('legal_name', 'id');
        $projectNames = Project::query()
            ->whereIn('id', $salesDocuments->pluck('project_id')->filter()->unique())
            ->pluck('name', 'id');

        foreach ($salesDocuments as $document) {
            $balance = $this->receivables->balance($document);
            if ($balance <= 0.00001 && ! $this->isCurrentCashMovementSourceDocument($currentSourceType, $currentSourceCode, 'sales_document', $document->code)) {
                continue;
            }

            $counterparty = (string) ($clientNames[$document->client_id] ?? 'Sin contraparte');
            $project = (string) ($document->project_id ? ($projectNames[$document->project_id] ?? 'Sin proyecto') : 'Sin proyecto');
            $options[$document->code] = $this->cashMovementSourceDocumentOption(
                'sales_document',
                $document->code,
                [$document->code, $counterparty, $project, UiFormatter::formatMoney($balance), $document->status],
                $counterparty,
                $document->project_id,
                $balance,
                0
            );
        }

        $expenseDocuments = ExpenseDocument::query()
            ->forCompany($companyId)
            ->whereNotIn('payment_status', ['Borrador', 'Anulado'])
            ->orderBy('due_date')
            ->orderBy('code')
            ->get();
        $expenseProjectNames = Project::query()
            ->whereIn('id', $expenseDocuments->pluck('project_id')->filter()->unique())
            ->pluck('name', 'id');

        foreach ($expenseDocuments as $document) {
            $balance = $this->payables->balance($document);
            if ($balance <= 0.00001 && ! $this->isCurrentCashMovementSourceDocument($currentSourceType, $currentSourceCode, 'expense_document', $document->code)) {
                continue;
            }

            $counterparty = (string) ($document->vendor_name ?: 'Sin contraparte');
            $project = (string) ($document->project_id ? ($expenseProjectNames[$document->project_id] ?? 'Sin proyecto') : 'Sin proyecto');
            $options[$document->code] = $this->cashMovementSourceDocumentOption(
                'expense_document',
                $document->code,
                [$document->code, $counterparty, $project, UiFormatter::formatMoney($balance), $document->payment_status],
                $counterparty,
                $document->project_id,
                0,
                $balance
            );
        }

        $payrollRecords = PayrollRecord::query()
            ->forCompany($companyId)
            ->whereIn('status', ['Confirmado', 'Parcial'])
            ->orderBy('period_date')
            ->orderBy('code')
            ->get();
        $personNames = Person::query()
            ->whereIn('id', $payrollRecords->pluck('person_id')->filter()->unique())
            ->pluck('name', 'id');

        foreach ($payrollRecords as $record) {
            $balance = $this->payroll->balance($record);
            if ($balance <= 0.00001 && ! $this->isCurrentCashMovementSourceDocument($currentSourceType, $currentSourceCode, 'payroll_record', $record->code)) {
                continue;
            }

            $counterparty = (string) ($personNames[$record->person_id] ?? 'Sin persona');
            $period = $record->period_date ? UiFormatter::formatDate($record->period_date) : 'Sin periodo';
            $options[$record->code] = $this->cashMovementSourceDocumentOption(
                'payroll_record',
                $record->code,
                [$record->code, $counterparty, $period, UiFormatter::formatMoney($balance), $record->status],
                $counterparty,
                $record->project_id,
                0,
                $balance
            );
        }

        $obligations = LegalObligation::query()
            ->forCompany($companyId)
            ->whereNotIn('status', ['Borrador', 'Anulado'])
            ->orderBy('due_date')
            ->orderBy('code')
            ->get();

        foreach ($obligations as $obligation) {
            $balance = $this->obligations->balance($obligation);
            if ($balance <= 0.00001 && ! $this->isCurrentCashMovementSourceDocument($currentSourceType, $currentSourceCode, 'legal_obligation', $obligation->code)) {
                continue;
            }

            $period = $obligation->period_date ? UiFormatter::formatDate($obligation->period_date) : 'Sin periodo';
            $dueDate = $obligation->due_date ? UiFormatter::formatDate($obligation->due_date) : 'Sin vencimiento';
            $options[$obligation->code] = $this->cashMovementSourceDocumentOption(
                'legal_obligation',
                $obligation->code,
                [$obligation->code, $obligation->obligation_type, $period.' / '.$dueDate, UiFormatter::formatMoney($balance), $obligation->status],
                $obligation->obligation_type,
                null,
                0,
                $balance
            );
        }

        return $options;
    }

    private function isCurrentCashMovementSourceDocument(string $currentType, string $currentCode, string $sourceType, string $code): bool
    {
        return $currentType === $sourceType && $currentCode === $code;
    }

    private function cashMovementSourceDocumentOption(
        string $sourceType,
        string $code,
        array $labelParts,
        string $counterparty,
        mixed $projectId,
        float $income,
        float $expense
    ): array {
        return [
            'label' => implode(' - ', array_filter($labelParts, fn ($part): bool => filled($part))),
            'source_document_type' => $sourceType,
            'source_document_code' => $code,
            'counterparty_name' => $counterparty,
            'project_id' => $projectId,
            'suggested_income' => $income > 0 ? number_format($income, 2, '.', '') : null,
            'suggested_expense' => $expense > 0 ? number_format($expense, 2, '.', '') : null,
        ];
    }

    private function codeMeta(string $modelClass): array
    {
        $model = new $modelClass;
        $auto = method_exists($model, 'functionalCodeAuto') && $model->functionalCodeAuto();

        return [
            'auto' => $auto,
            'label' => $auto ? 'Se generará automáticamente' : 'Código',
        ];
    }

    private function payrollViewMeta(string $resource): array
    {
        if ($resource !== 'payroll-records') {
            return [];
        }

        return [
            'dependent_only_fields' => [
                'pension_health_base',
                'afc_base',
                'afp_mandatory',
                'afp_commission',
                'health_employee',
                'afc_employee',
                'iusc_amount',
                'afc_employer',
                'employer_pension',
                'accident_insurance',
                'sanna',
                'vacation_provision_amount',
            ],
            'honorarios_only_fields' => [
                'employee_retention',
            ],
        ];
    }

    private function effectiveDateForResource(string $resource, Request $request, ?Model $item): ?string
    {
        return match ($resource) {
            'sales-documents', 'expense-documents' => $request->input('issue_date') ?: optional($item?->issue_date)->toDateString(),
            'time-entries' => $request->input('entry_date') ?: optional($item?->entry_date)->toDateString(),
            'payroll-records' => $request->input('period_date') ?: optional($item?->period_date)->toDateString(),
            'projects', 'assignments', 'people' => $request->input('start_date') ?: optional($item?->start_date)->toDateString(),
            default => null,
        };
    }

    private function applyVigencyFilter($query, string $modelClass, string $resource, string $field, ?string $effectiveDate, mixed $selectedId): void
    {
        if ($selectedId) {
            $keyName = (new $modelClass)->getKeyName();
            $query->where(function ($builder) use ($modelClass, $resource, $field, $effectiveDate, $selectedId, $keyName) {
                $this->applyVigencyConstraints($builder, $modelClass, $resource, $field, $effectiveDate);
                $builder->orWhere($keyName, $selectedId);
            });

            return;
        }

        $this->applyVigencyConstraints($query, $modelClass, $resource, $field, $effectiveDate);
    }

    private function applyVigencyConstraints($query, string $modelClass, string $resource, string $field, ?string $effectiveDate): void
    {
        if (Schema::hasColumn((new $modelClass)->getTable(), 'active')) {
            $query->where('active', true);
        }

        if (Schema::hasColumn((new $modelClass)->getTable(), 'is_active')) {
            $query->where('is_active', true);
        }

        match ($modelClass) {
            \App\Models\Client::class => $query->whereHas('clientStatus', fn ($builder) => $builder->where('code', 'active')),
            \App\Models\Project::class => $query->whereHas('projectStatus', fn ($builder) => $builder->whereIn('code', \App\Models\Project::vigentStatusCodes())),
            \App\Models\Person::class => $query->whereHas('workerStatus', fn ($builder) => $builder->where('code', 'active')),
            \App\Models\ProjectAssignment::class => $query->whereHas('assignmentStatus', fn ($builder) => $builder->where('code', 'active')),
            default => null,
        };

        if ($effectiveDate && Schema::hasColumn((new $modelClass)->getTable(), 'start_date')) {
            $query->where(function ($builder) use ($effectiveDate) {
                $builder->whereNull('start_date')->orWhereDate('start_date', '<=', $effectiveDate);
            });
        }

        if ($effectiveDate && Schema::hasColumn((new $modelClass)->getTable(), 'end_date')) {
            $query->where(function ($builder) use ($effectiveDate) {
                $builder->whereNull('end_date')->orWhereDate('end_date', '>=', $effectiveDate);
            });
        }
    }

    private function isVigentRecord(Model $record, string $resource, string $field, ?string $effectiveDate): bool
    {
        if (isset($record->active) && ! $record->active) {
            return false;
        }

        if (isset($record->is_active) && ! $record->is_active) {
            return false;
        }

        $statusOk = match ($record::class) {
            \App\Models\Client::class => strtolower((string) $record->clientStatus?->code) === 'active',
            \App\Models\Project::class => \App\Models\Project::isVigentStatusCode($record->projectStatus?->code),
            \App\Models\Person::class => strtolower((string) $record->workerStatus?->code) === 'active',
            \App\Models\ProjectAssignment::class => strtolower((string) $record->assignmentStatus?->code) === 'active',
            default => true,
        };

        if (! $statusOk) {
            return false;
        }

        if ($effectiveDate && isset($record->start_date) && $record->start_date && $record->start_date->gt($effectiveDate)) {
            return false;
        }

        if ($effectiveDate && isset($record->end_date) && $record->end_date && $record->end_date->lt($effectiveDate)) {
            return false;
        }

        return true;
    }

    private function relationNames(array $config): array
    {
        $model = new $config['model'];
        $fields = array_merge($config['fields'] ?? [], $config['index_fields'] ?? []);

        return collect($fields)
            ->filter(fn (array $definition): bool => ($definition['type'] ?? null) === 'relation')
            ->map(fn (array $definition, string $field): string => $definition['relation_name'] ?? str($field)->beforeLast('_id')->camel()->toString())
            ->filter(fn (string $relation): bool => method_exists($model, $relation))
            ->values()
            ->all();
    }

    private function sortableFields(array $config): array
    {
        $model = new $config['model'];
        $table = $model->getTable();
        $fields = $config['index_fields'] ?? $config['fields'] ?? [];

        return collect($fields)
            ->mapWithKeys(function (array $definition, string $field) use ($table) {
                if (($definition['sortable'] ?? true) === false) {
                    return [];
                }

                if (isset($definition['sort_columns'])) {
                    return [$field => (array) $definition['sort_columns']];
                }

                if (($definition['type'] ?? null) === 'relation') {
                    return [];
                }

                if (Schema::hasColumn($table, $field)) {
                    return [$field => [$field]];
                }

                return [];
            })
            ->all();
    }

    private function authorizeResource(Request $request, array $config, string $ability, ?Model $model = null): void
    {
        $user = $request->user();
        $allowed = match ($ability) {
            'viewAny' => $this->policy->viewAny($user),
            'create' => $this->policy->create($user),
            'delete' => $model ? $this->policy->delete($user, $model) : false,
            'view' => $model ? $this->policy->view($user, $model) : false,
            default => $model ? $this->policy->update($user, $model) : false,
        };

        abort_unless($allowed, 403);

        if (($config['admin_only'] ?? false) === true) {
            abort_unless($user->role === 'admin', 403);
        }
    }

    private function usageCount(array $config, Model $item): int
    {
        return collect($config['usage'] ?? [])
            ->sum(fn (array $usage) => $usage['model']::query()->where($usage['column'], $item->getKey())->count());
    }

    private function assignmentCommitmentPreviewData(Request $request, ProjectAssignment $item): ?array
    {
        $draft = $this->assignmentDraftFromInput($request, $item);
        if (! $draft?->project_id || ! $draft->person_id) {
            return null;
        }

        return $this->commitments->previewAssignment(
            $draft,
            $item->exists ? (int) $item->id : null,
        );
    }

    private function assignmentDraftFromInput(Request $request, ?ProjectAssignment $item = null): ?ProjectAssignment
    {
        $old = fn (string $field, mixed $default = null): mixed => session()->hasOldInput($field)
            ? old($field)
            : $request->input($field, $default);

        $projectId = $this->nullableInteger($old('project_id', $item?->project_id));
        $personId = $this->nullableInteger($old('person_id', $item?->person_id));

        if (! $projectId || ! $personId) {
            return null;
        }

        $assignment = $item?->exists ? $item->replicate() : new ProjectAssignment();
        $assignment->id = $item?->id;
        $assignment->exists = false;
        $assignment->company_id = $request->user()->company_id;
        $assignment->project_id = $projectId;
        $assignment->person_id = $personId;
        $assignment->client_id = $this->nullableInteger($old('client_id', $item?->client_id));
        $assignment->assignment_status_id = $this->nullableInteger($old('assignment_status_id', $item?->assignment_status_id));
        $assignment->hourly_rate_unit_type = strtoupper((string) ($old('hourly_rate_unit_type', $item?->hourly_rate_unit_type ?: 'UF') ?: 'UF'));
        $assignment->hourly_rate_currency_id = $this->nullableInteger($old('hourly_rate_currency_id', $item?->hourly_rate_currency_id));
        $assignment->hourly_value = $this->nullableDecimal($old('hourly_value', $item?->hourly_value));
        $assignment->project_value = $this->nullableDecimal($old('project_value', $item?->project_value));
        $assignment->monthly_hours = $this->nullableDecimal($old('monthly_hours', $item?->monthly_hours));
        $assignment->start_date = \App\Support\UiFormatter::parseDateInput((string) ($old('start_date', optional($item?->start_date)->format('d/m/Y')) ?: ''));
        $assignment->end_date = \App\Support\UiFormatter::parseDateInput((string) ($old('end_date', optional($item?->end_date)->format('d/m/Y')) ?: ''));
        $assignment->code = $item?->code ?: 'BORRADOR';

        $person = Person::query()
            ->forCompany($request->user()->company_id)
            ->with(['employmentMode', 'employmentContractType', 'afp', 'healthSystemCatalog', 'hourlyRateCurrency'])
            ->find($personId);
        $project = Project::query()
            ->forCompany($request->user()->company_id)
            ->with(['salesCurrency', 'client'])
            ->find($projectId);

        if (! $person || ! $project) {
            return null;
        }

        $assignment->setRelation('person', $person);
        $assignment->setRelation('project', $project);

        if ($assignment->assignment_status_id) {
            $assignment->setRelation('assignmentStatus', \App\Models\RecordStatus::query()->find($assignment->assignment_status_id));
        }

        if ($assignment->hourly_rate_currency_id) {
            $assignment->setRelation('hourlyRateCurrency', Currency::query()->find($assignment->hourly_rate_currency_id));
        }

        return $assignment;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
