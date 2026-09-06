@php
    $renderedFieldInput = null;
    $fieldErrorClass = $errors->has($field) ? ' is-invalid' : '';
    $rawNumericValue = function (mixed $input): ?string {
        if ($input === null || $input === '') {
            return null;
        }

        $normalized = preg_replace('/[^0-9,\.\-]/', '', (string) $input);
        if ($normalized === null || $normalized === '') {
            return null;
        }

        $hasComma = str_contains($normalized, ',');
        $hasDot = str_contains($normalized, '.');

        if ($hasComma && $hasDot) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($hasComma) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return $normalized;
    };

    if (($definition['readonly'] ?? false) === true) {
        $renderedFieldInput = '<input id="'.e($field).'" class="form-control'.e($fieldErrorClass).'" value="'.e($genericDisplayValue($field, $definition, $value)).'" readonly aria-readonly="true">';
    } elseif ($type === 'textarea') {
        $renderedFieldInput = '<textarea id="'.e($field).'" name="'.e($field).'" class="form-control'.e($fieldErrorClass).'" rows="3">'.e($value).'</textarea>';
    } elseif ($type === 'money') {
        $inputValue = $rawNumericValue($value);
        $displayInputValue = $inputValue !== null && is_numeric($inputValue)
            ? \App\Support\UiFormatter::formatNumber($inputValue)
            : ($value !== null ? (string) $value : '');
        $renderedFieldInput = '<input id="'.e($field).'" name="'.e($field).'" type="text" inputmode="decimal" autocomplete="off" data-money-input="true" class="form-control'.e($fieldErrorClass).'" value="'.e($displayInputValue).'">';
    } elseif ($type === 'select') {
        $optionsHtml = '<option value="">Seleccione</option>';
        foreach (($options[$field] ?? ($definition['options'] ?? [])) as $key => $option) {
            $label = is_array($option) ? ($option['label'] ?? $key) : $option;
            $optionAttributes = (string) $value === (string) $key ? ' selected' : '';

            if (is_array($option)) {
                foreach ($option as $attribute => $attributeValue) {
                    if ($attribute === 'label' || $attributeValue === null || $attributeValue === '') {
                        continue;
                    }

                    $dataAttribute = str_replace('_', '-', \Illuminate\Support\Str::kebab($attribute));
                    $optionAttributes .= ' data-'.e($dataAttribute).'="'.e($attributeValue).'"';
                }
            }

            $optionsHtml .= '<option value="'.e($key).'"'.$optionAttributes.'>'.e($label).'</option>';
        }
        $selectAttributes = '';
        if (($definition['source_document_selector'] ?? false) === true) {
            $selectAttributes = ' data-source-document-select="true" data-parent-field="'.e($definition['depends_on'] ?? 'source_document_type').'" data-placeholder-parent="Seleccione un tipo de documento primero" data-placeholder-empty="No hay documentos pendientes para este tipo" data-placeholder-default="Seleccione"';
        }

        $renderedFieldInput = '<select id="'.e($field).'" name="'.e($field).'" class="form-select'.e($fieldErrorClass).'"'.$selectAttributes.'>'.$optionsHtml.'</select>';
        if (($definition['source_document_selector'] ?? false) === true) {
            $renderedFieldInput .= '<input id="'.e($field).'_other" name="" type="text" value="'.e($value).'" class="form-control'.e($fieldErrorClass).'" placeholder="Referencia o código libre" hidden disabled data-source-document-other-input="true">';
        }
    } elseif ($type === 'relation') {
        $relationAttributes = '';
        if (isset($definition['depends_on'])) {
            $relationAttributes = ' data-dependent-select="true" data-parent-field="'.e($definition['depends_on']).'" data-placeholder-default="Seleccione" data-placeholder-parent="'.e($definition['depends_on'] === 'client_id' ? 'Seleccione un cliente primero' : ($definition['depends_on'] === 'expense_category_id' ? 'Seleccione una categoría primero' : 'Seleccione un valor padre primero')).'" data-placeholder-empty="'.e($field === 'project_id' ? 'No hay proyectos para este cliente' : ($field === 'expense_subcategory_id' ? 'No hay subcategorías para esta categoría' : 'No hay opciones disponibles')).'"';
        }

        $optionsHtml = '<option value="">Seleccione</option>';
        foreach (($options[$field] ?? []) as $key => $option) {
            $label = is_array($option) ? $option['label'] : $option;
            $parentId = is_array($option) ? ($option['parent_id'] ?? null) : null;
            $optionAttributes = ((string) $value === (string) $key ? ' selected' : '') . ($parentId ? ' data-parent-id="'.e($parentId).'"' : '');
            $optionsHtml .= '<option value="'.e($key).'"'.$optionAttributes.'>'.e($label).'</option>';
        }

        $renderedFieldInput = '<select id="'.e($field).'" name="'.e($field).'" class="form-select'.e($fieldErrorClass).'"'.$relationAttributes.'>'.$optionsHtml.'</select>';
    } elseif ($resource === 'assignments' && in_array($field, ['hourly_value', 'project_value', 'monthly_hours'], true)) {
        $displayValue = $type === 'date' ? ($value ? \App\Support\UiFormatter::formatDate($value) : null) : ($value instanceof \Carbon\CarbonInterface ? $value->format('Y-m-d') : $value);
        $renderedFieldInput = view('operational.partials.assignment-numeric-field', [
            'field' => $field,
            'displayValue' => $displayValue,
            'sharedRateUnitFields' => $sharedRateUnitFields,
            'selectedRateUnitPrefix' => $selectedRateUnitPrefix,
        ])->render();
    } else {
        $displayValue = $type === 'date' ? ($value ? \App\Support\UiFormatter::formatDate($value) : null) : ($value instanceof \Carbon\CarbonInterface ? $value->format('Y-m-d') : $value);
        $inputType = 'text';
        $inputExtraAttributes = '';

        if (($definition['presentation'] ?? null) === 'phone' || $field === 'phone_country_code') {
            $inputType = 'tel';
        } elseif (in_array($type, ['email', 'number'], true)) {
            $inputType = $type;
        }

        if ($type === 'date') {
            $inputExtraAttributes .= ' placeholder="dd/mm/yyyy" inputmode="numeric"';
        }

        if (($definition['presentation'] ?? null) === 'rut') {
            $inputExtraAttributes .= ' placeholder="12.345.678-5" data-rut-field="true" autocomplete="off"';
        }

        if (($definition['presentation'] ?? null) === 'phone') {
            $inputExtraAttributes .= ' placeholder="+56 9 1234 5678"';
        }

        if ($resource === 'time-entries' && in_array($field, ['hours_worked', 'hours_approved'], true)) {
            $inputType = 'number';
            $inputExtraAttributes .= ' step="0.01" min="'.e($field === 'hours_worked' ? '0.01' : '0').'" max="24" inputmode="decimal"';
        }

        $renderedFieldInput = '<input id="'.e($field).'" name="'.e($field).'" type="'.e($inputType).'" class="form-control'.e($fieldErrorClass).'" value="'.e($displayValue).'"'.$inputExtraAttributes.'>';
    }
@endphp

{!! $renderedFieldInput !!}

@once
    @push('scripts')
        <script nonce="{{ $cspNonce ?? '' }}">
            (() => {
                const moneyInputs = Array.from(document.querySelectorAll('[data-money-input="true"]'));
                if (moneyInputs.length === 0) {
                    return;
                }

                const normalizeMoney = (value) => {
                    let normalized = String(value ?? '').trim().replace(/[^0-9,.-]/g, '');
                    if (normalized === '') {
                        return '';
                    }

                    const negative = normalized.startsWith('-');
                    normalized = normalized.replace(/-/g, '');
                    const hasComma = normalized.includes(',');
                    const dotCount = (normalized.match(/\./g) || []).length;

                    if (hasComma) {
                        normalized = normalized.replace(/\./g, '').replace(',', '.');
                    } else if (dotCount > 1) {
                        normalized = normalized.replace(/\./g, '');
                    } else if (dotCount === 1) {
                        const [integerPart, decimalPart = ''] = normalized.split('.');
                        if (decimalPart.length === 3 && integerPart.length >= 1) {
                            normalized = integerPart + decimalPart;
                        }
                    }

                    return (negative ? '-' : '') + normalized;
                };

                const formatMoney = (value) => {
                    const normalized = normalizeMoney(value);
                    if (normalized === '' || Number.isNaN(Number(normalized))) {
                        return value;
                    }

                    const decimals = normalized.includes('.')
                        ? Math.min(normalized.split('.')[1].length, 2)
                        : 0;

                    return new Intl.NumberFormat('es-CL', {
                        minimumFractionDigits: 0,
                        maximumFractionDigits: decimals,
                    }).format(Number(normalized));
                };

                moneyInputs.forEach((input) => {
                    input.addEventListener('focus', () => {
                        input.value = normalizeMoney(input.value);
                    });
                    input.addEventListener('blur', () => {
                        input.value = formatMoney(input.value);
                    });
                });

                document.querySelectorAll('form').forEach((form) => {
                    form.addEventListener('submit', () => {
                        form.querySelectorAll('[data-money-input="true"]').forEach((input) => {
                            input.value = normalizeMoney(input.value);
                        });
                    }, { capture: true });
                });
            })();
        </script>
    @endpush
@endonce
