<?php

namespace App\Http\Controllers;

use App\Http\Requests\CrudResourceRequest;
use App\Models\ExpenseDocument;
use App\Models\Person;
use App\Models\PayrollRecord;
use App\Models\SalesDocument;
use App\Policies\CompanyOwnedPolicy;
use App\Services\AuditService;
use App\Services\CashMovementService;
use App\Services\CatalogService;
use App\Services\PayablesService;
use App\Services\PayrollService;
use App\Services\ReceivablesService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class OperationalCrudController extends Controller
{
    public function __construct(
        private readonly CompanyOwnedPolicy $policy,
        private readonly CashMovementService $cashMovements,
        private readonly ReceivablesService $receivables,
        private readonly PayablesService $payables,
        private readonly PayrollService $payroll,
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

        $query = $config['model']::query()
            ->with($this->relationNames($config));

        if (method_exists(new $config['model'], 'scopeForCompany')) {
            $query->forCompany($request->user()->company_id);
        }

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
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('operational.index', compact('resource', 'config', 'items', 'search'));
    }

    public function create(Request $request, string $resource): View
    {
        $config = $this->config($resource);
        $this->authorizeResource($request, $config, 'create');

        return view('operational.form', [
            'resource' => $resource,
            'config' => $config,
            'item' => new $config['model'],
            'options' => $this->options($config, $request, new $config['model']),
        ]);
    }

    public function store(CrudResourceRequest $request, string $resource): RedirectResponse
    {
        $config = $this->config($resource);
        $this->authorizeResource($request, $config, 'create');
        $data = $this->prepareData($request, $resource, $request->validated());

        if ($resource === 'cash-movements') {
            $this->cashMovements->create($data, $request->user());
        } else {
            $model = DB::transaction(function () use ($config, $data) {
                return $config['model']::query()->create($data);
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

        return view('operational.show', compact('resource', 'config', 'item'));
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
        ]);
    }

    public function update(CrudResourceRequest $request, string $resource, int $record): RedirectResponse
    {
        $config = $this->config($resource);
        $item = $config['model']::query()->findOrFail($record);
        $this->authorizeResource($request, $config, 'update', $item);

        $data = $this->prepareData($request, $resource, $request->validated());
        $before = $item->toArray();
        DB::transaction(fn () => $item->update($data));
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
            $data['calculated_amount'] = round((float) $data['hours_approved'] * (float) $data['hourly_value'], 2);
        }

        if ($resource === 'payroll-records') {
            $person = Person::query()->findOrFail($data['person_id']);
            $data = array_merge($data, $this->payroll->calculate($person, $data['period_date'], $data));
            $data['status'] = 'Pendiente';
        }

        return $data;
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

        foreach ($config['fields'] as $field => $definition) {
            if (($definition['type'] ?? null) !== 'relation') {
                continue;
            }

            $query = $definition['model']::query();
            if (method_exists(new $definition['model'], 'scopeForCompany')) {
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

            $records = $query->orderBy($definition['display'])->get();

            $options[$field] = $records->mapWithKeys(function ($record) use ($definition) {
                $payload = ['id' => $record->id, 'label' => $record->{$definition['display']}];

                if (isset($definition['option_parent_key'])) {
                    $payload['parent_id'] = $record->{$definition['option_parent_key']};
                }

                return [$record->id => $payload];
            })->all();
        }

        return $options;
    }

    private function relationNames(array $config): array
    {
        $model = new $config['model'];

        return collect($config['fields'])
            ->filter(fn (array $definition): bool => ($definition['type'] ?? null) === 'relation')
            ->map(fn (array $definition, string $field): string => $definition['relation_name'] ?? str($field)->beforeLast('_id')->camel()->toString())
            ->filter(fn (string $relation): bool => method_exists($model, $relation))
            ->values()
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
