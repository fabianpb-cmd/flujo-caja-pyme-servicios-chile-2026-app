<?php

namespace App\Services;

use App\Models\ContractType;
use App\Models\Project;
use App\Models\User;

class AssistantScreenGuideService
{
    public function __construct(private readonly BillingStrategyService $billingStrategies)
    {
    }

    public function build(array $context, ?User $user = null): ?array
    {
        $resource = $context['resource'] ?? null;
        $config = is_string($resource) ? config("operational.{$resource}") : null;
        if (! is_array($config) || ! is_array($config['fields'] ?? null)) {
            return null;
        }

        $rules = is_array($config['rules'] ?? null) ? $config['rules'] : [];
        $fields = collect($config['fields'])->map(function (array $field, string $key) use ($rules): array {
            $fieldRules = is_array($rules[$key] ?? null) ? $rules[$key] : [];
            $required = $this->isRequired($fieldRules);
            $readonly = (bool) ($field['readonly'] ?? false);
            $type = (string) ($field['type'] ?? 'text');

            return [
                'key' => $key,
                'label' => $field['label'] ?? $key,
                'section' => $field['section'] ?? null,
                'type' => $type,
                'classification' => $this->classification($key, $type, $readonly, $required, $field['depends_on'] ?? null),
                'required' => $required,
                'nullable' => in_array('nullable', $fieldRules, true),
                'readonly' => $readonly,
                'depends_on' => $field['depends_on'] ?? null,
                'catalog_selection' => in_array($type, ['relation', 'select'], true),
                'validation' => $this->relevantRules($fieldRules),
            ];
        })->values();

        $states = is_array($context['form'] ?? null) ? $context['form'] : [];
        $required = $fields->filter(fn (array $field): bool => $field['required'] && $field['classification'] !== 'SYSTEM_GENERATED');
        $missing = $required->filter(fn (array $field): bool => data_get($states, $field['key'].'.state') === 'empty')->pluck('label')->values()->all();

        return [
            'source_id' => 'FORM-GUIDE:'.$resource,
            'screen' => $config['title'] ?? $resource,
            'purpose' => 'Guía estructural de la pantalla actual; no contiene valores de registros.',
            'sections' => $fields->pluck('section')->filter()->unique()->values()->all(),
            'fields' => $fields->all(),
            'completion_order' => $required->pluck('label')->values()->all(),
            'required_fields' => $required->pluck('label')->values()->all(),
            'optional_fields' => $fields->filter(fn (array $field): bool => ! $field['required'] && ! $field['readonly'])->pluck('label')->values()->all(),
            'calculated_fields' => $fields->where('classification', 'SYSTEM_CALCULATED')->pluck('label')->values()->all(),
            'dependencies' => $fields->filter(fn (array $field): bool => filled($field['depends_on']))->map(fn (array $field): array => ['field' => $field['label'], 'depends_on' => $field['depends_on']])->values()->all(),
            'save_requirements' => $required->pluck('label')->values()->all(),
            'missing_required_fields' => $missing,
            'warnings' => $fields->filter(fn (array $field): bool => filled($field['depends_on']))->map(fn (array $field): string => "{$field['label']} depende de {$field['depends_on']}.")->values()->all(),
            'contract_strategy' => $this->contractStrategy($resource, $context, $user),
        ];
    }

    private function contractStrategy(string $resource, array $context, ?User $user): ?array
    {
        if ($resource !== 'projects' || ! $user || ! filled(data_get($context, 'ids.contract_type_id'))) {
            return null;
        }

        $contract = ContractType::query()
            ->where('company_id', $user->company_id)
            ->whereKey((int) data_get($context, 'ids.contract_type_id'))
            ->first();
        if (! $contract) {
            return null;
        }

        $project = new Project;
        $project->setRelation('contractType', $contract);
        $strategy = $this->billingStrategies->forProject($project);
        $guidance = match ($strategy) {
            BillingStrategyService::HOURLY => 'Facturación por HH aprobadas sin bolsa contractual. Requiere moneda comercial y Tarifa comercial HH.',
            BillingStrategyService::HOURS_BANK => 'Bolsa total consumible: Venta neta y Tarifa comercial HH definen la capacidad total. No se reinicia ni crea excedentes automáticos.',
            BillingStrategyService::MONTHLY_RECURRING => 'Bolsa mensual consumible: Valor mensual contratado y Tarifa comercial HH definen la capacidad de cada mes. No hay arrastre ni excedentes automáticos.',
            BillingStrategyService::CLOSED_PROJECT => 'Proyecto cerrado: requiere venta contractual, moneda comercial y un plan de hitos porcentuales según las reglas vigentes.',
            default => null,
        };

        return $guidance === null ? null : [
            'strategy' => $strategy,
            'guidance' => $guidance,
        ];
    }

    private function classification(string $key, string $type, bool $readonly, bool $required, mixed $dependsOn): string
    {
        if ($key === 'code') {
            return 'SYSTEM_GENERATED';
        }
        if ($readonly) {
            return 'SYSTEM_CALCULATED';
        }
        if (filled($dependsOn)) {
            return 'CONDITIONAL';
        }
        if (in_array($type, ['relation', 'select'], true)) {
            return 'CATALOG_SELECTION';
        }
        if (! $required) {
            return 'OPTIONAL';
        }

        return 'USER_INPUT';
    }

    private function isRequired(array $rules): bool
    {
        return collect($rules)->contains(fn ($rule): bool => is_string($rule) && str_starts_with($rule, 'required'));
    }

    private function relevantRules(array $rules): array
    {
        return collect($rules)->filter(fn ($rule): bool => is_string($rule) && (
            str_starts_with($rule, 'required')
            || str_starts_with($rule, 'after')
            || str_starts_with($rule, 'before')
            || str_starts_with($rule, 'min:')
            || str_starts_with($rule, 'max:')
            || str_starts_with($rule, 'gt:')
            || in_array($rule, ['nullable', 'date', 'numeric', 'integer', 'boolean', 'email'], true)
        ))->values()->all();
    }
}
