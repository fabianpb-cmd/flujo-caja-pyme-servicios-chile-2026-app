<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
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

        if (isset($rules['code'])) {
            $model = new $config['model'];
            $table = $model->getTable();

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

        foreach ($config['fields'] as $field => $definition) {
            if (($definition['type'] ?? null) !== 'relation' || ! isset($rules[$field], $definition['model'])) {
                continue;
            }

            $related = new $definition['model'];

            if (! method_exists($related, 'scopeForCompany')) {
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
        $checkboxes = collect(config('operational.'.$this->route('resource').'.fields', []))
            ->filter(fn (array $field): bool => ($field['type'] ?? null) === 'checkbox')
            ->keys()
            ->all();

        foreach ($checkboxes as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $config = config('operational.'.$this->route('resource').'.fields', []);

        $validator->after(function (Validator $validator) use ($config) {
            foreach ($config as $field => $definition) {
                $parentField = $definition['depends_on'] ?? null;
                $parentKey = $definition['option_parent_key'] ?? null;

                if (! $parentField || ! $parentKey || empty($this->input($field))) {
                    continue;
                }

                $related = $definition['model']::query()->find($this->input($field));
                if ($related && (string) $related->{$parentKey} !== (string) $this->input($parentField)) {
                    $validator->errors()->add($field, 'La subcategoría no corresponde a la categoría seleccionada.');
                }
            }
        });
    }
}
