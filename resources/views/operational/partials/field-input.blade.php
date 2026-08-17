@php
    $renderedFieldInput = null;
    $fieldErrorClass = $errors->has($field) ? ' is-invalid' : '';

    if (($definition['readonly'] ?? false) === true) {
        $renderedFieldInput = '<input id="'.e($field).'" class="form-control'.e($fieldErrorClass).'" value="'.e($genericDisplayValue($field, $definition, $value)).'" readonly aria-readonly="true">';
    } elseif ($type === 'textarea') {
        $renderedFieldInput = '<textarea id="'.e($field).'" name="'.e($field).'" class="form-control'.e($fieldErrorClass).'" rows="3">'.e($value).'</textarea>';
    } elseif ($type === 'select') {
        $optionsHtml = '<option value="">Seleccione</option>';
        foreach (($definition['options'] ?? []) as $key => $label) {
            $optionsHtml .= '<option value="'.e($key).'"'.((string) $value === (string) $key ? ' selected' : '').'>'.e($label).'</option>';
        }
        $renderedFieldInput = '<select id="'.e($field).'" name="'.e($field).'" class="form-select'.e($fieldErrorClass).'">'.$optionsHtml.'</select>';
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

        $renderedFieldInput = '<input id="'.e($field).'" name="'.e($field).'" type="'.e($inputType).'" class="form-control'.e($fieldErrorClass).'" value="'.e($displayValue).'"'.$inputExtraAttributes.'>';
    }
@endphp

{!! $renderedFieldInput !!}
