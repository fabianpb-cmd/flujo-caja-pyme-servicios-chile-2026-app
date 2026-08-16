@php
    $assignmentInputType = 'number';
    $assignmentInputExtraAttributes = $field === 'monthly_hours'
        ? ' step="1" min="0" inputmode="numeric" data-assignments-hours-input="true"'
        : ' step="0.01" min="0" inputmode="decimal" data-assignments-rate-input="true"';

    $assignmentInputHtml = $sharedRateUnitFields && in_array($field, ['hourly_value', 'project_value'], true)
        ? '<div class="input-group">'
            . '<span class="input-group-text rate-unit-chip" data-rate-unit-prefix-for="'.e($field).'">'.e($selectedRateUnitPrefix).'</span>'
            . '<input id="'.e($field).'" name="'.e($field).'" type="'.e($assignmentInputType).'" class="form-control" value="'.e($displayValue).'"'.$assignmentInputExtraAttributes.'>'
            . '</div>'
        : '<input id="'.e($field).'" name="'.e($field).'" type="'.e($assignmentInputType).'" class="form-control" value="'.e($displayValue).'"'.$assignmentInputExtraAttributes.'>';
@endphp

{!! $assignmentInputHtml !!}
