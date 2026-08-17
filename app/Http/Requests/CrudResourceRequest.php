<?php

namespace App\Http\Requests;

use App\Rules\ValidChileanRut;
use App\Models\ApprovalStatus;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\RecordStatus;
use App\Models\TimeEntry;
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

    public function attributes(): array
    {
        $config = config('operational.'.$this->route('resource').'.fields', []);

        return collect($config)
            ->mapWithKeys(fn (array $definition, string $field): array => [$field => $definition['label'] ?? $field])
            ->all();
    }

    public function messages(): array
    {
        return match ((string) $this->route('resource')) {
            'assignments' => [
                'start_date.required_with' => 'La fecha inicio es obligatoria cuando se informa la fecha término.',
                'end_date.required_with' => 'La fecha término es obligatoria cuando se informa la fecha inicio.',
                'end_date.after_or_equal' => 'La fecha término debe ser igual o posterior a la fecha inicio.',
                'monthly_hours.max' => 'Horas mensuales no puede superar 744.',
            ],
            'time-entries' => [
                'hours_worked.gt' => 'Las horas trabajadas deben ser mayores que 0.',
                'hours_worked.max' => 'Las horas trabajadas no pueden superar 24 en un mismo registro.',
                'hours_approved.max' => 'Las horas aprobadas no pueden superar 24 en un mismo registro.',
            ],
            default => [],
        };
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

        if ($this->route('resource') === 'payroll-records' && filled($this->input('period_date'))) {
            $period = UiFormatter::parseDateInput($this->input('period_date'));
            if ($period) {
                $this->merge(['period_date' => $period->copy()->startOfMonth()->toDateString()]);
            }
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
                $this->validateTimeEntryIntegrity($validator);
            }

            $this->validateTechnicalFieldLimits($validator, $resource, $config);

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

    private function validateTechnicalFieldLimits(Validator $validator, string $resource, array $config): void
    {
        foreach ($this->technicalFieldSpecs($resource, $config) as $field => $spec) {
            if ($validator->errors()->has($field) || ! filled($this->input($field))) {
                continue;
            }

            $value = trim((string) $this->input($field));
            if ($value === '') {
                continue;
            }

            if (($spec['type'] ?? null) === 'decimal' && ! $this->fitsDecimalColumn($value, $spec['precision'], $spec['scale'])) {
                $validator->errors()->add($field, $this->decimalLimitMessage($spec['label'], $spec['precision'], $spec['scale']));
                continue;
            }

            if (($spec['type'] ?? null) === 'unsignedSmallInteger' && ! $this->fitsUnsignedIntegerMax($value, $spec['max'])) {
                $validator->errors()->add($field, $this->unsignedIntegerLimitMessage($spec['label'], $spec['max']));
            }
        }
    }

    private function technicalFieldSpecs(string $resource, array $config): array
    {
        $labels = fn (string $field, string $fallback): string => (string) data_get($config, $field.'.label', $fallback);

        return match ($resource) {
            'assignments' => [
                'hourly_value' => ['type' => 'decimal', 'precision' => 18, 'scale' => 2, 'label' => $labels('hourly_value', 'El valor HH')],
                'project_value' => ['type' => 'decimal', 'precision' => 18, 'scale' => 2, 'label' => $labels('project_value', 'El monto pactado de la asignación')],
                'monthly_hours' => ['type' => 'unsignedSmallInteger', 'max' => 65535, 'label' => $labels('monthly_hours', 'Las horas mensuales')],
            ],
            'payroll-records' => [
                'hours_approved' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'label' => $labels('hours_approved', 'Las horas aprobadas')],
                'monthly_value' => ['type' => 'decimal', 'precision' => 18, 'scale' => 2, 'label' => $labels('monthly_value', 'El valor mensual')],
                'hourly_value' => ['type' => 'decimal', 'precision' => 18, 'scale' => 2, 'label' => $labels('hourly_value', 'La tarifa por hora')],
                'project_value' => ['type' => 'decimal', 'precision' => 18, 'scale' => 2, 'label' => $labels('project_value', 'El valor proyecto/hito')],
                'bonuses' => ['type' => 'decimal', 'precision' => 18, 'scale' => 2, 'label' => $labels('bonuses', 'Los bonos imponibles')],
                'non_taxable_allowances' => ['type' => 'decimal', 'precision' => 18, 'scale' => 2, 'label' => $labels('non_taxable_allowances', 'Las asignaciones no imponibles')],
                'advances' => ['type' => 'decimal', 'precision' => 18, 'scale' => 2, 'label' => $labels('advances', 'Los anticipos')],
                'other_deductions' => ['type' => 'decimal', 'precision' => 18, 'scale' => 2, 'label' => $labels('other_deductions', 'Los otros descuentos')],
            ],
            'time-entries' => [
                'hours_worked' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'label' => $labels('hours_worked', 'Las horas trabajadas')],
                'hours_approved' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'label' => $labels('hours_approved', 'Las horas aprobadas')],
                'hourly_value' => ['type' => 'decimal', 'precision' => 18, 'scale' => 2, 'label' => $labels('hourly_value', 'La tarifa aplicable')],
            ],
            default => [],
        };
    }

    private function validateTimeEntryIntegrity(Validator $validator): void
    {
        $entryDate = UiFormatter::parseDateInput($this->input('entry_date'));
        if (! $entryDate) {
            return;
        }

        $project = Project::query()
            ->where('company_id', $this->user()->company_id)
            ->find($this->input('project_id'));

        if (! $project) {
            return;
        }

        if (filled($this->input('client_id')) && (string) $project->client_id !== (string) $this->input('client_id')) {
            $validator->errors()->add('client_id', 'El cliente del registro debe coincidir con el cliente del proyecto seleccionado.');
        }

        $assignments = $this->timeEntryAssignmentsForProject($entryDate, false);
        $matchingAssignments = $this->timeEntryAssignmentsForProject($entryDate, true);

        if ($matchingAssignments->isEmpty()) {
            $validator->errors()->add('project_id', $this->timeEntryAssignmentMissingMessage($assignments));
        }

        if ($matchingAssignments->count() > 1 && ! $this->currentTimeEntryUsesAssignment($matchingAssignments)) {
            $validator->errors()->add('project_id', 'Existe más de una asignación vigente para esta persona y proyecto en la fecha indicada. Revise la asignación correspondiente antes de registrar horas.');
        }

        $workedHours = $this->numericInput($this->input('hours_worked'));
        $approvedHours = $this->numericInput($this->input('hours_approved'));

        if ($workedHours !== null) {
            $dailyHours = TimeEntry::query()
                ->where('company_id', $this->user()->company_id)
                ->where('person_id', $this->input('person_id'))
                ->whereDate('entry_date', $entryDate->toDateString())
                ->when($this->route('record'), fn ($query) => $query->whereKeyNot($this->route('record')))
                ->sum('hours_worked');

            if (round((float) $dailyHours + $workedHours, 2) > 24) {
                $validator->errors()->add('hours_worked', 'La suma diaria de horas trabajadas para esta persona no puede superar 24.');
            }
        }

        if ($workedHours !== null && $approvedHours !== null && $approvedHours > $workedHours) {
            $validator->errors()->add('hours_approved', 'Las horas aprobadas no pueden superar las horas trabajadas.');
        }

        $approvalCode = $this->approvalStatusCode();
        if ($approvalCode === 'approved' && $approvedHours !== null && $approvedHours <= 0) {
            $validator->errors()->add('hours_approved', 'Cuando la aprobación es Aprobado, las horas aprobadas deben ser mayores que 0.');
        }

        if ($approvalCode === 'rejected' && $approvedHours !== null && $approvedHours > 0) {
            $validator->errors()->add('hours_approved', 'Cuando la aprobación es Rechazado, las horas aprobadas deben ser 0.');
        }

        $paymentStatus = strtolower(trim((string) $this->input('payment_status')));
        if ($paymentStatus === 'paid' && $approvalCode !== 'approved') {
            $validator->errors()->add('payment_status', 'Un registro solo puede marcarse como pagado cuando su aprobación está en estado Aprobado.');
        }
    }

    private function timeEntryAssignmentsForProject(Carbon $entryDate, bool $applyDateWindow): \Illuminate\Support\Collection
    {
        $query = ProjectAssignment::query()
            ->where('company_id', $this->user()->company_id)
            ->where('person_id', $this->input('person_id'))
            ->where('project_id', $this->input('project_id'))
            ->whereHas('assignmentStatus', fn ($query) => $query->where('code', 'active'));

        if ($applyDateWindow) {
            $query
                ->where(function ($query) use ($entryDate) {
                    $query->whereNull('start_date')->orWhereDate('start_date', '<=', $entryDate->toDateString());
                })
                ->where(function ($query) use ($entryDate) {
                    $query->whereNull('end_date')->orWhereDate('end_date', '>=', $entryDate->toDateString());
                });
        }

        return $query->orderBy('start_date')->orderBy('id')->get();
    }

    private function currentTimeEntryUsesAssignment(\Illuminate\Support\Collection $matchingAssignments): bool
    {
        $recordId = $this->route('record');
        if (! $recordId) {
            return false;
        }

        $assignmentId = TimeEntry::query()->whereKey($recordId)->value('assignment_id');

        return $assignmentId !== null && $matchingAssignments->contains(fn (ProjectAssignment $assignment): bool => (int) $assignment->id === (int) $assignmentId);
    }

    private function timeEntryAssignmentMissingMessage(\Illuminate\Support\Collection $assignments): string
    {
        if ($assignments->count() === 1) {
            $assignment = $assignments->first();

            return sprintf(
                'La fecha registrada está fuera de la vigencia de la asignación (%s).',
                $this->timeEntryAssignmentDateRangeLabel($assignment)
            );
        }

        if ($assignments->isNotEmpty()) {
            return 'La fecha registrada está fuera de la vigencia de la asignación seleccionada.';
        }

        return 'La persona no se encuentra asignada al proyecto seleccionado para la fecha indicada.';
    }

    private function timeEntryAssignmentDateRangeLabel(ProjectAssignment $assignment): string
    {
        $start = $assignment->start_date ? UiFormatter::formatDate($assignment->start_date) : 'sin inicio informado';
        $end = $assignment->end_date ? UiFormatter::formatDate($assignment->end_date) : 'sin término informado';

        if ($assignment->start_date && $assignment->end_date) {
            return $start.' al '.$end;
        }

        if ($assignment->start_date) {
            return 'desde '.$start;
        }

        if ($assignment->end_date) {
            return 'hasta '.$end;
        }

        return 'sin vigencia informada';
    }

    private function numericInput(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', preg_replace('/\s+/', '', (string) $value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function approvalStatusCode(): ?string
    {
        $approvalStatusId = $this->input('approval_status_id');
        if (! filled($approvalStatusId)) {
            return null;
        }

        return strtolower((string) ApprovalStatus::query()
            ->where('company_id', $this->user()->company_id)
            ->whereKey($approvalStatusId)
            ->value('code'));
    }

    private function fitsDecimalColumn(string $value, int $precision, int $scale): bool
    {
        $normalized = str_replace(',', '.', trim($value));
        if (! preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            return true;
        }

        [$integerPart, $fractionPart] = array_pad(explode('.', $normalized, 2), 2, '');
        if (strlen($fractionPart) > $scale) {
            return false;
        }

        $significantIntegerPart = ltrim($integerPart, '0');
        if ($significantIntegerPart === '') {
            $significantIntegerPart = '0';
        }

        return strlen($significantIntegerPart) <= ($precision - $scale);
    }

    private function fitsUnsignedIntegerMax(string $value, int $max): bool
    {
        if (! preg_match('/^\d+$/', trim($value))) {
            return true;
        }

        return (int) $value <= $max;
    }

    private function decimalLimitMessage(string $label, int $precision, int $scale): string
    {
        return sprintf(
            '%s supera el máximo permitido por el campo (%s).',
            $label,
            $this->decimalMaxValue($precision, $scale)
        );
    }

    private function unsignedIntegerLimitMessage(string $label, int $max): string
    {
        return sprintf('%s no puede superar %d.', $label, $max);
    }

    private function decimalMaxValue(int $precision, int $scale): string
    {
        $integerDigits = str_repeat('9', max($precision - $scale, 1));

        if ($scale === 0) {
            return $integerDigits;
        }

        return $integerDigits.','.str_repeat('9', $scale);
    }
}
