@php
    $assignmentInputType = 'number';
    $assignmentHasError = $errors->has($field);
    $assignmentInputExtraAttributes = $field === 'monthly_hours'
        ? ' step="1" min="0" max="744" inputmode="numeric" data-assignments-hours-input="true"'
        : ' step="0.01" min="0" max="9999999999999999.99" inputmode="decimal" data-assignments-rate-input="true"';
    $assignmentInputClass = 'form-control'.($assignmentHasError ? ' is-invalid' : '');

    $assignmentInputHtml = $sharedRateUnitFields && in_array($field, ['hourly_value', 'project_value'], true)
        ? '<div class="input-group">'
            . '<span class="input-group-text rate-unit-chip" data-rate-unit-prefix-for="'.e($field).'">'.e($selectedRateUnitPrefix).'</span>'
            . '<input id="'.e($field).'" name="'.e($field).'" type="'.e($assignmentInputType).'" class="'.e($assignmentInputClass).'" value="'.e($displayValue).'"'.$assignmentInputExtraAttributes.'>'
            . '</div>'
        : '<input id="'.e($field).'" name="'.e($field).'" type="'.e($assignmentInputType).'" class="'.e($assignmentInputClass).'" value="'.e($displayValue).'"'.$assignmentInputExtraAttributes.'>';
@endphp

{!! $assignmentInputHtml !!}
