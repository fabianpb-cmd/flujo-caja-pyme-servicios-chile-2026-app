<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class AssistantContextService
{
    private const ALLOWED_IDS = ['client_id', 'project_id', 'milestone_id', 'cash_account_id'];
    private const SAFE_VALUES = ['issue_date'];
    private const SENSITIVE = ['password', 'password_confirmation', 'two_factor', 'recovery', 'token', 'csrf', 'rut', 'email', 'phone', 'address', 'bank_account', 'document_number', 'notes'];

    public function build(array $input, string $actualRoute): array
    {
        $resource = (string) ($input['resource'] ?? '');
        $config = $resource !== '' ? config("operational.$resource") : null;
        if ($resource !== '' && ! is_array($config)) {
            throw ValidationException::withMessages(['resource' => 'El recurso solicitado no está disponible para el ayudante.']);
        }

        $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];
        $focused = (string) ($input['focused_field'] ?? '');
        if ($focused !== '' && ! array_key_exists($focused, $fields)) {
            $focused = '';
        }

        $form = is_array($input['form'] ?? null) ? $input['form'] : [];
        $safeForm = [];
        $ids = [];
        foreach ($form as $name => $value) {
            if (! is_string($name) || $this->isSensitive($name) || ! array_key_exists($name, $fields)) {
                continue;
            }
            if (in_array($name, self::ALLOWED_IDS, true) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
                $ids[$name] = (int) $value;
                $safeForm[$name] = ['state' => 'filled'];
            } elseif (in_array($name, self::SAFE_VALUES, true) && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $safeForm[$name] = ['state' => 'filled'];
                $ids[$name] = $value;
            } else {
                $safeForm[$name] = ['state' => blank($value) ? 'empty' : 'filled'];
            }
        }

        return [
            'route' => $actualRoute,
            'resource' => $resource ?: null,
            'title' => $config['title'] ?? null,
            'section' => $config['section'] ?? null,
            'focused_field' => $focused ?: null,
            'focused_definition' => $focused ? $this->fieldDefinition($fields[$focused], $config['rules'][$focused] ?? []) : null,
            'form' => $safeForm,
            'ids' => $ids,
            'history' => $this->history($input['history'] ?? []),
        ];
    }

    private function fieldDefinition(array $field, array $rules): array
    {
        return [
            'label' => $field['label'] ?? null,
            'type' => $field['type'] ?? 'text',
            'readonly' => (bool) ($field['readonly'] ?? false),
            'depends_on' => $field['depends_on'] ?? null,
            'required' => in_array('required', $rules, true),
            'nullable' => in_array('nullable', $rules, true),
            'validation' => array_values(array_filter($rules, fn ($rule) => is_string($rule) && (str_starts_with($rule, 'required') || in_array($rule, ['date', 'numeric', 'integer', 'boolean', 'email'], true)))),
        ];
    }

    private function history(mixed $history): array
    {
        if (! is_array($history)) {
            return [];
        }

        return collect($history)->take(-6)->map(fn ($item) => [
            'question' => mb_substr((string) data_get($item, 'question', ''), 0, 2000),
            'answer' => mb_substr((string) data_get($item, 'answer', ''), 0, 2000),
        ])->filter(fn (array $item) => $item['question'] !== '' || $item['answer'] !== '')->values()->all();
    }

    private function isSensitive(string $name): bool
    {
        $normalized = strtolower($name);
        return collect(self::SENSITIVE)->contains(fn (string $token) => str_contains($normalized, $token));
    }
}
