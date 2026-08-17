<?php

namespace App\Http\Controllers;

use App\Http\Requests\CrudResourceRequest;
use App\Models\ExpenseDocument;
use App\Models\Currency;
use App\Models\PayrollAdjustment;
use App\Models\Person;
use App\Models\PayrollRecord;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\SalesDocument;
use App\Policies\CompanyOwnedPolicy;
use App\Services\AuditService;
use App\Services\CashMovementService;
use App\Services\CatalogService;
use App\Services\HourlyRateService;
use App\Services\HourlyCostService;
use App\Services\PayablesService;
use App\Services\PayrollService;
use App\Services\SalesPrefacturationService;
use App\Services\ReceivablesService;
use App\Support\MassAssignment;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
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
        private readonly SalesPrefacturationService $salesPrefacturation,
        private readonly HourlyRateService $hourlyRates,
        private readonly HourlyCostService $hourlyCosts,
        private readonly CatalogService $catalogs,
        private readonly AuditService $audit,
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
            }, fn ($query) => $query->latest('id'))
            ->paginate(15)
            ->withQueryString();

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
            'payrollHourlyCost' => null,
        ]);
    }

    public function store(CrudResourceRequest $request, string $resource): RedirectResponse
    {
        $config = $this->config($resource);
        $this->authorizeResource($request, $config, 'create');
        try {
            $data = $this->prepareData($request, $resource, $request->validated());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['payroll' => $exception->getMessage()]);
        }

        if ($resource === 'cash-movements') {
            $this->cashMovements->create($data, $request->user());
        } else {
            $model = DB::transaction(function () use ($config, $data) {
                return MassAssignment::create($config['model'], $data);
            });

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

        return view('operational.show', compact('resource', 'config', 'item', 'payrollHourlyCost', 'payrollCalculationBreakdown', 'salesCalculationBreakdown'));
    }

    public function edit(Request $request, string $resource, int $record): View
    {
        $config = $this->config($resource);
        $item = $config['model']::query()->with($this->relationNames($config))->findOrFail($record);
        $this->authorizeResource($request, $config, 'update', $item);

        return view('operational.form', [
            'resource' => $resource,
            'config' => $config,
            'item' => $item,
            'options' => $this->options($config, $request, $item),
            'codeMeta' => $this->codeMeta($config['model']),
            'payrollViewMeta' => $this->payrollViewMeta($resource),
            'payrollHourlyCost' => $resource === 'payroll-records' ? $this->hourlyCosts->forPayroll($item) : null,
            'payrollCalculationBreakdown' => $resource === 'payroll-records' ? $this->payroll->explain($item) : null,
            'salesCalculationBreakdown' => $resource === 'sales-documents' ? $this->salesPrefacturation->documentBreakdown($item) : null,
        ]);
    }

    public function update(CrudResourceRequest $request, string $resource, int $record): RedirectResponse
    {
        $config = $this->config($resource);
        $item = $config['model']::query()->findOrFail($record);
        $this->authorizeResource($request, $config, 'update', $item);

        try {
            $data = $this->prepareData($request, $resource, $request->validated());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['payroll' => $exception->getMessage()]);
        }
        if ($this->codeMeta($config['model'])['auto']) {
            unset($data['code']);
        }
        $before = $item->toArray();
        DB::transaction(fn () => MassAssignment::fillAndSave($item, $data));
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
            $resolution = $this->hourlyRates->resolveForTimeEntry($person, $project, $data['entry_date']);

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
            $derived = $this->payrollRecordDefaults($data['company_id'], $person->id, $period, $data['project_id'] ?? null);

            foreach ($derived as $field => $value) {
                if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                    if ($value !== null) {
                        $data[$field] = $value;
                    }
                }
            }

            $data = array_merge($data, $this->payroll->calculate($person, $period, $data));
            $data['status'] = 'Pendiente';
        }

        return $data;
    }

    private function payrollRecordDefaults(int $companyId, int $personId, Carbon $period, mixed $projectId): array
    {
        $defaults = [
            'project_id' => null,
            'hours_approved' => null,
            'monthly_value' => null,
            'hourly_value' => null,
            'project_value' => null,
            'health_additional' => null,
            'bonuses' => null,
            'non_taxable_allowances' => null,
            'advances' => null,
            'other_deductions' => null,
        ];

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

        $defaults['project_id'] = $this->payrollAutoProjectId($companyId, $personId, $period, $projectId);

        $person = Person::query()->forCompany($companyId)->find($personId);
        if ($defaults['monthly_value'] === null && $person?->monthly_value !== null) {
            $defaults['monthly_value'] = (float) $person->monthly_value;
        }

        if ($defaults['hourly_value'] === null && $person?->hourly_value !== null) {
            $defaults['hourly_value'] = (float) $person->hourly_value;
        }

        if ($defaults['health_additional'] === null && $person?->additional_health_plan !== null) {
            $defaults['health_additional'] = (float) $person->additional_health_plan;
        }

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

        return $options;
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
}
