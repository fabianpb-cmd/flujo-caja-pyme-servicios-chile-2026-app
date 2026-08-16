<?php

namespace App\Http\Requests;

use App\Rules\ValidChileanRut;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\RecordStatus;
use App\Models\Currency;
use App\Support\ChileanRut;
use App\Support\UiFormatter;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CrudResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        $config = config('operational.'.$this->route('resource'));
        abort_unless($config, 404);

        $rules = $config['rules'];
        $recordId = $this->route('record') ?? null;
        $autoManagedCode = $this->autoManagedCode($config['model']);

        if (isset($rules['code'])) {
            $model = new $config['model'];
            $table = $model->getTable();

            if ($autoManagedCode) {
                $rules['code'] = collect($rules['code'])
                    ->reject(fn ($rule): bool => $rule === 'required')
                    ->prepend('nullable')
                    ->values()
                    ->all();
            }

            $unique = Rule::unique($table, 'code')
                ->ignore($recordId);

            if (Schema::hasColumn($table, 'company_id') || ! empty($config['unique_scope'])) {
                $unique = $unique->where(function ($query) use ($config, $table) {
                    if (Schema::hasColumn($table, 'company_id')) {
                        $query->where('company_id', $this->user()->company_id);
                    }

                    foreach (($config['unique_scope'] ?? []) as $column) {
                        $query->where($column, $this->input($column));
                    }
                });
            }

            $rules['code'][] = $unique;
        }

        if ($this->route('resource') === 'projects' && isset($rules['sale_net'])) {
            $currency = $this->projectSalesCurrency();
            $rules['sale_net'][] = 'decimal:0,'.$this->moneyMinorUnits($currency);
        }

        foreach ($config['fields'] as $field => $definition) {
            if ($this->isRutField($field, $definition) && isset($rules[$field])) {
                $rules[$field] = collect($rules[$field])
                    ->push(new ValidChileanRut())
                    ->all();
            }

            if (($definition['type'] ?? null) !== 'relation' || ! isset($rules[$field], $definition['model'])) {
                continue;
            }

            $related = new $definition['model'];

            if (! method_exists($related, 'scopeForCompany')) {
                if (($definition['global'] ?? false) === true) {
                    $rules[$field] = collect($rules[$field])
                        ->reject(fn ($rule): bool => is_string($rule) && str_starts_with($rule, 'exists:'))
                        ->push(Rule::exists($related->getTable(), 'id'))
                        ->all();
                }

                continue;
            }

            $rules[$field] = collect($rules[$field])
                ->reject(fn ($rule): bool => is_string($rule) && str_starts_with($rule, 'exists:'))
                ->push(Rule::exists($related->getTable(), 'id')->where(function ($query) use ($definition) {
                    $query->where('company_id', $this->user()->company_id);

                    foreach (($definition['where'] ?? []) as $column => $value) {
                        $query->where($column, $value);
                    }
                }))
                ->all();
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $config = config('operational.'.$this->route('resource').'.fields', []);

        if ($this->route('resource') === 'people') {
            $this->merge([
                'phone_country_code' => $this->input('phone_country_code') ?: '+56',
                'phone_number' => $this->normalizeDigits($this->input('phone_number')),
                'secondary_phone' => $this->normalizeDigits($this->input('secondary_phone')),
                'emergency_contact_phone' => $this->normalizeDigits($this->input('emergency_contact_phone')),
            ]);
        }

        foreach ($config as $field => $definition) {
            if (! $this->isRutField($field, $definition)) {
                continue;
            }

            $this->merge([$field => ChileanRut::normalize($this->input($field))]);
        }

        $dateFields = collect($config)
            ->filter(fn (array $field): bool => ($field['type'] ?? null) === 'date')
            ->keys()
            ->all();

        foreach ($dateFields as $field) {
            $this->merge([$field => $this->normalizeDateInput($this->input($field))]);
        }

        $checkboxes = collect($config)
            ->filter(fn (array $field): bool => ($field['type'] ?? null) === 'checkbox')
            ->keys()
            ->all();

        foreach ($checkboxes as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $resource = $this->route('resource');
        $config = config('operational.'.$this->route('resource').'.fields', []);

        $validator->after(function (Validator $validator) use ($config, $resource) {
            foreach (collect($config)->filter(fn (array $field): bool => ($field['type'] ?? null) === 'date')->keys() as $field) {
                if (! filled($this->input($field))) {
                    continue;
                }

                if (UiFormatter::parseDateInput($this->input($field)) === null) {
                    $validator->errors()->add($field, 'La fecha ingresada no es válida.');
                }
            }

            foreach ($config as $field => $definition) {
                $parentField = $definition['depends_on'] ?? null;
                $parentKey = $definition['option_parent_key'] ?? null;

                if (! $parentField || ! $parentKey || empty($this->input($field))) {
                    continue;
                }

                $related = $definition['model']::query()->find($this->input($field));
                if ($related && (string) $related->{$parentKey} !== (string) $this->input($parentField)) {
                    $validator->errors()->add($field, $this->dependencyMessage($field, $parentField));
                }

                if ($related && ! $this->canUseRelatedRecord($field, $related)) {
                    $validator->errors()->add($field, $this->vigencyMessage($field));
                }
            }

            if ($resource === 'payroll-records' && filled($this->input('project_id')) && filled($this->input('person_id')) && filled($this->input('period_date'))) {
                if (! $this->payrollProjectAssignmentExists()) {
                    $validator->errors()->add('project_id', 'La persona no se encuentra asignada al proyecto seleccionado para el período indicado.');
                }
            }

            if ($resource === 'time-entries' && filled($this->input('project_id')) && filled($this->input('person_id')) && filled($this->input('entry_date'))) {
                if (! $this->timeEntryProjectAssignmentExists()) {
                    $validator->errors()->add('project_id', 'La persona no se encuentra asignada al proyecto seleccionado para la fecha indicada.');
                }
            }

            if ($resource !== 'people') {
                return;
            }

            $rut = $this->input('rut');
            $nationality = mb_strtolower(trim((string) $this->input('nationality')));

            if ($nationality !== 'extranjero' && empty($rut)) {
                $validator->errors()->add('rut', 'El RUT es obligatorio para personal activo.');
            }

            if (! empty($rut)) {
                $query = Person::query()->where('company_id', $this->user()->company_id)->where('rut', $rut);
                if ($this->route('record')) {
                    $query->whereKeyNot($this->route('record'));
                }

                if ($query->exists()) {
                    $validator->errors()->add('rut', 'Ya existe un miembro del personal con este RUT en la empresa.');
                }
            }

            if ($resource === 'projects' && filled($this->input('sale_net'))) {
                $currency = $this->projectSalesCurrency();
                if (! $this->moneyHasAllowedScale($this->input('sale_net'), $currency)) {
                    $validator->errors()->add('sale_net', $this->moneyScaleMessage($currency));
                }
            }
        });
    }

    private function normalizeDigits(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';

        return $digits === '' ? null : $digits;
    }

    private function normalizeDateInput(mixed $value): ?string
    {
        $date = UiFormatter::parseDateInput($value);

        return $date?->toDateString() ?? (is_string($value) ? trim($value) : null);
    }

    private function dependencyMessage(string $field, string $parentField): string
    {
        return match ([$parentField, $field]) {
            ['client_id', 'project_id'] => 'El proyecto seleccionado no pertenece al cliente indicado.',
            ['expense_category_id', 'expense_subcategory_id'] => 'La subcategoría seleccionada no pertenece a la categoría indicada.',
            ['region_id', 'commune_id'] => 'La comuna seleccionada no pertenece a la región indicada.',
            default => 'La opción seleccionada no corresponde al valor padre indicado.',
        };
    }

    private function isRutField(string $field, array $definition): bool
    {
        return ($definition['presentation'] ?? null) === 'rut'
            || in_array($field, ['rut', 'tax_id', 'tax_identifier', 'bank_account_holder_rut'], true)
            || str_ends_with($field, '_rut');
    }

    private function projectSalesCurrency(): mixed
    {
        $currencyId = $this->input('sales_currency_id');

        if (! filled($currencyId) && $this->route('record')) {
            $currencyId = \App\Models\Project::query()->whereKey($this->route('record'))->value('sales_currency_id');
        }

        if (! filled($currencyId)) {
            return 'CLP';
        }

        return Currency::query()->find($currencyId) ?: 'CLP';
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

    private function moneyMinorUnits(mixed $currency): int
    {
        return (int) data_get($currency, 'minor_units', match (strtoupper((string) $currency)) {
            'CLP' => 0,
            default => 2,
        });
    }

    private function vigencyMessage(string $field): string
    {
        return match ($field) {
            'client_id' => 'El cliente seleccionado no se encuentra vigente para la operación indicada.',
            'project_id' => 'El proyecto seleccionado no se encuentra vigente para la operación indicada.',
            'person_id' => 'La persona seleccionada no se encuentra vigente para la operación indicada.',
            default => 'El registro seleccionado no se encuentra vigente para la operación indicada.',
        };
    }

    private function canUseRelatedRecord(string $field, object $related): bool
    {
        $resource = (string) $this->route('resource');
        $record = $this->route('record');
        $currentSelected = $this->currentRecordFieldValue($field);
        if ($record && (string) $currentSelected === (string) data_get($related, 'id')) {
            return true;
        }

        if (property_exists($related, 'active') || isset($related->active)) {
            if ((bool) data_get($related, 'active') === false) {
                return false;
            }
        }

        if (property_exists($related, 'is_active') || isset($related->is_active)) {
            if ((bool) data_get($related, 'is_active') === false) {
                return false;
            }
        }

        return match (get_class($related)) {
            \App\Models\Client::class => $this->statusCode($related, 'clientStatus') === 'active',
            \App\Models\Project::class => \App\Models\Project::isVigentStatusCode($this->statusCode($related, 'projectStatus')),
            \App\Models\Person::class => $this->statusCode($related, 'workerStatus') === 'active',
            \App\Models\ProjectAssignment::class => $this->statusCode($related, 'assignmentStatus') === 'active',
            default => true,
        };
    }

    private function statusCode(object $model, string $relation): ?string
    {
        $related = $model->{$relation} ?? null;

        return $related ? strtolower((string) $related->code) : null;
    }

    private function payrollProjectAssignmentExists(): bool
    {
        $period = Carbon::parse((string) $this->input('period_date'))->startOfMonth();
        $periodEnd = $period->copy()->endOfMonth();
        if ((string) $this->currentRecordFieldValue('project_id') === (string) $this->input('project_id')) {
            return true;
        }

        return ProjectAssignment::query()
            ->where('company_id', $this->user()->company_id)
            ->where('person_id', $this->input('person_id'))
            ->where('project_id', $this->input('project_id'))
            ->whereHas('assignmentStatus', fn ($query) => $query->where('code', 'active'))
            ->where(function ($query) use ($periodEnd) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $periodEnd);
            })
            ->where(function ($query) use ($period) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $period);
            })
            ->exists();
    }

    private function timeEntryProjectAssignmentExists(): bool
    {
        $entryDate = UiFormatter::parseDateInput($this->input('entry_date'));
        if (! $entryDate) {
            return false;
        }

        if ((string) $this->currentRecordFieldValue('project_id') === (string) $this->input('project_id')) {
            return true;
        }

        return ProjectAssignment::query()
            ->where('company_id', $this->user()->company_id)
            ->where('person_id', $this->input('person_id'))
            ->where('project_id', $this->input('project_id'))
            ->whereHas('assignmentStatus', fn ($query) => $query->where('code', 'active'))
            ->where(function ($query) use ($entryDate) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $entryDate->toDateString());
            })
            ->where(function ($query) use ($entryDate) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $entryDate->toDateString());
            })
            ->exists();
    }

    private function currentRecordFieldValue(string $field): mixed
    {
        $recordId = $this->route('record');
        if (! $recordId) {
            return null;
        }

        $config = config('operational.'.$this->route('resource'));
        if (! $config) {
            return null;
        }

        return $config['model']::query()->whereKey($recordId)->value($field);
    }

    private function autoManagedCode(string $modelClass): bool
    {
        $model = new $modelClass;

        return method_exists($model, 'functionalCodeAuto') && $model->functionalCodeAuto();
    }
}
