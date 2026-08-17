@extends('layouts.app')

@section('content')
@php($editing = $item->exists)
@php($fields = $config['fields'])
@php($autoCode = $codeMeta['auto'] ?? false)
@php($isPayroll = $resource === 'payroll-records')
@php($payrollViewMeta = $payrollViewMeta ?? [])
@php($genericDisplayValue = function (string $field, array $definition, mixed $value) use ($item) {
    $type = $definition['type'] ?? 'text';
    $presentation = $definition['presentation'] ?? null;

    if ($value === null || $value === '') {
        return '—';
    }

    return match (true) {
        $type === 'money' => \App\Support\UiFormatter::display($item, $field, $definition),
        $presentation === 'rut' => \App\Support\ChileanRut::format((string) $value) ?? '—',
        $presentation === 'percent' => \App\Support\UiFormatter::formatPercent($value),
        $presentation === 'hours' => \App\Support\UiFormatter::formatHours($value),
        $type === 'date' => \App\Support\UiFormatter::formatDate($value),
        in_array($type, ['decimal', 'number'], true) => \App\Support\UiFormatter::formatNumber($value),
        default => (string) $value,
    };
})
@php($payrollHelp = [
    'person_id' => 'Persona o prestador al que corresponde la remuneración. Su ficha aporta la referencia base de cálculo.',
    'period_date' => 'Mes al que pertenece la remuneración. El sistema normaliza el valor al primer día del mes para mantener el período contable.',
    'payment_date' => 'Fecha prevista o real de pago del período. El estado se recalcula según este dato y los pagos registrados.',
    'amount_basis' => 'Indica si el monto pactado se interpreta como bruto o líquido para el cálculo.',
    'project_id' => 'Proyecto asociado a la asignación vigente del período, cuando exista.',
    'hours_approved' => 'Horas aprobadas del período. Déjelo vacío solo si corresponde usar la referencia automática.',
    'monthly_value' => 'Valor mensual override. Déjelo vacío para usar el valor base de la persona o de la novedad automática del período. Si ingresa 0,00, el período queda con base mensual en cero.',
    'hourly_value' => 'Tarifa hora override. Déjelo vacío para usar la tarifa base de la persona o de la novedad automática del período. Si ingresa 0,00, el período queda sin tarifa por hora.',
    'project_value' => 'Monto fijo del proyecto o hito. Déjelo vacío para usar la referencia automática del período cuando exista. Si ingresa 0,00, el período queda sin monto fijo.',
    'bonuses' => 'Bonos imponibles del período. Si provienen de Novedades remuneración, el sistema los toma como referencia automática.',
    'non_taxable_allowances' => 'Asignaciones no imponibles del período. Si provienen de Novedades remuneración, el sistema los toma como referencia automática.',
    'base_salary' => 'Sueldo proporcional o base honorarios calculada automáticamente.',
    'taxable_gross' => 'Base sobre la cual se calculan cotizaciones, sujeta a topes legales.',
    'pension_health_base' => 'Base previsional afecta a AFP y salud, considerando el tope legal vigente del período.',
    'afc_base' => 'Base afecta a Seguro de Cesantía, considerando el tope AFC aplicable al período.',
    'employee_retention' => 'Monto retenido y enterado posteriormente mediante las obligaciones tributarias correspondientes.',
    'afp_mandatory' => 'Cotización obligatoria del trabajador sobre la base previsional con tope.',
    'afp_commission' => 'Comisión vigente de la AFP de la persona según el período.',
    'health_employee' => 'Cotización legal de salud calculada sobre la base previsional.',
    'afc_employee' => 'Seguro de Cesantía de cargo del trabajador cuando corresponde según tipo de contrato.',
    'iusc_amount' => 'Impuesto Único de Segunda Categoría calculado según tabla SII vigente y base tributaria.',
    'advances' => 'Dato de ingreso manual para el período. Déjelo vacío si no corresponde un anticipo.',
    'other_deductions' => 'Dato de ingreso manual para el período. Déjelo vacío si no corresponde otro descuento.',
    'net_pay' => 'Monto líquido a pagar después de descuentos legales y manuales.',
    'afc_employer' => 'Seguro de Cesantía de cargo del empleador según tipo de contrato.',
    'employer_pension' => 'Cotización adicional de cargo del empleador según la vigencia legal del período.',
    'accident_insurance' => 'Seguro de accidentes del trabajo: tasa básica más eventual tasa adicional de la empresa.',
    'sanna' => 'Cotización de cargo del empleador correspondiente al seguro SANNA.',
    'vacation_provision_amount' => 'Costo provisionado para análisis financiero. No corresponde a un descuento del trabajador ni a una salida real de caja.',
    'employer_cost' => 'Costo económico total de la remuneración, incluyendo aportes del empleador y provisiones.',
    'calculation_notes' => 'Observaciones del cálculo o alertas que el sistema detectó para este período.',
    'status' => 'Estado de pago o control operacional del documento.',
]);
@php($payrollAutoFields = ['code', 'base_salary', 'taxable_gross', 'employee_retention', 'afp_mandatory', 'afp_commission', 'health_employee', 'afc_employee', 'iusc_amount', 'net_pay', 'afc_employer', 'employer_pension', 'accident_insurance', 'sanna', 'vacation_provision_amount', 'employer_cost', 'calculation_status', 'calculation_notes'])
@php($payrollManualFields = ['person_id', 'project_id', 'period_date', 'payment_date', 'amount_basis', 'hours_approved', 'monthly_value', 'hourly_value', 'project_value', 'bonuses', 'non_taxable_allowances', 'advances', 'other_deductions'])
@php($payrollSections = [
    'Datos base' => ['code', 'person_id', 'project_id', 'period_date', 'payment_date', 'amount_basis'],
    'Remuneración' => ['hours_approved', 'monthly_value', 'hourly_value', 'project_value', 'bonuses', 'non_taxable_allowances', 'base_salary', 'taxable_gross', 'pension_health_base', 'afc_base'],
    'Descuentos legales' => ['employee_retention', 'afp_mandatory', 'afp_commission', 'health_employee', 'afc_employee', 'iusc_amount'],
    'Otros descuentos' => ['advances', 'other_deductions'],
    'Líquido' => ['net_pay'],
    'Aportes empleador' => ['afc_employer', 'employer_pension', 'accident_insurance', 'sanna', 'employer_cost'],
    'Provisiones' => ['vacation_provision_amount'],
    'Control' => ['calculation_status', 'calculation_notes', 'status'],
])
@php($payrollSummary = [
    ['label' => 'Días remunerados', 'field' => 'worked_days', 'text' => 'Cantidad de días considerados en el período. Para sueldo mensual fijo, el valor diario se determina sobre base 30.'],
    ['label' => 'Sueldo proporcional', 'field' => 'base_salary', 'text' => 'Monto calculado según sueldo mensual y días remunerados.'],
    ['label' => 'Total imponible', 'field' => 'taxable_gross', 'text' => 'Base sobre la cual se calculan cotizaciones, sujeta a topes legales.'],
    ['label' => 'Días vacaciones devengados', 'field' => 'vacation_days_accrued_period', 'text' => 'Devengo estimado de feriado legal del período.'],
    ['label' => 'Valor día vacaciones', 'field' => 'vacation_daily_value', 'text' => 'Valor utilizado para estimar la provisión, según tipo de remuneración.'],
    ['label' => 'Provisión vacaciones', 'field' => 'vacation_provision_amount', 'text' => 'Costo provisionado para análisis financiero. No corresponde a un descuento del trabajador ni a una salida real de caja.'],
])
@php($projectHelp = [
    'sales_currency_id' => 'Moneda utilizada para cotizar y registrar las ventas del proyecto.',
    'sale_net' => 'Venta antes de IVA, expresada en la moneda definida para el proyecto.',
    'sale_total' => 'Total con IVA en la misma moneda comercial del proyecto.',
])
@php($rateUnitHelp = [
    'hourly_rate_unit_type' => 'Unidad en que se pactó la tarifa por hora. UF usa la unidad de cuenta del período; las monedas usan su conversión configurada.',
    'hourly_value' => 'Tarifa por una hora de trabajo. La unidad se muestra al lado del monto.',
    'project_value' => 'Valor contractual o referencial del proyecto. Usa la misma unidad definida para la tarifa HH.',
])
@php($formHelp = [
    'clients' => [
        'tax_id' => 'Puede ingresarlo con o sin puntos. El sistema valida automáticamente el dígito verificador.',
        'payment_term_id' => 'Se utiliza para calcular automáticamente vencimientos cuando corresponda.',
    ],
    'projects' => [
        'client_id' => 'El proyecto queda asociado al cliente seleccionado.',
        'sales_currency_id' => 'Moneda utilizada para registrar las ventas del proyecto.',
        'manager_id' => 'Solo se muestran responsables vigentes para nuevas asignaciones.',
        'hourly_rate_unit_type' => 'Unidad en que se pactó la tarifa por hora.',
        'hourly_value' => 'Tarifa por hora expresada en la unidad seleccionada.',
        'project_value' => 'Valor contractual o referencial del proyecto, expresado en la unidad indicada.',
        'sale_net' => 'Venta antes de IVA, expresada en la moneda definida para el proyecto.',
        'sale_total' => 'Total con IVA en la misma moneda comercial del proyecto.',
    ],
    'people' => [
        'rut' => 'Puede ingresarlo con o sin puntos. El sistema valida automáticamente el dígito verificador.',
        'employment_mode_id' => 'Define la forma de contratación y las reglas utilizadas posteriormente en remuneraciones.',
        'employment_contract_type_id' => 'Se utiliza para aplicar reglas laborales y previsionales según corresponda.',
        'afp_id' => 'AFP asociada a la persona. Sus tasas se obtienen según el período de cálculo.',
        'health_system_id' => 'Sistema de salud asociado a la persona.',
        'hourly_rate_unit_type' => 'Unidad en que se pactó la tarifa por hora. Puede ser UF o una moneda habilitada.',
        'hourly_value' => 'Tarifa correspondiente a una hora de trabajo. El formato depende de la unidad seleccionada.',
        'monthly_hours' => 'Referencia de horas contractuales o mensuales utilizada cuando corresponda.',
        'start_date' => 'Inicio de vigencia de la relación contractual.',
        'end_date' => 'Fin de vigencia. Déjelo vacío mientras la relación continúe vigente.',
    ],
])
@php($pageHelp = [
    'people' => [
        'title' => '¿Cómo completar la ficha de Personal?',
        'bullets' => [
            'Complete identificación y contacto de la persona.',
            'El RUT se valida automáticamente antes de guardar.',
            'Modalidad y tipo de contrato determinan cálculos posteriores de remuneraciones.',
            'AFP y salud se usan automáticamente cuando corresponda a trabajadores dependientes.',
            'La tarifa HH puede expresarse en UF o moneda.',
            'Los proyectos disponibles posteriormente dependen de las asignaciones de la persona.',
            'Las fechas laborales permiten determinar vigencia histórica.',
            'Los datos desactivados históricamente se mantienen para trazabilidad.',
        ],
    ],
    'time-entries' => [
        'title' => '¿Cómo registrar horas?',
        'bullets' => [
            'Seleccione primero la persona y la fecha. El sistema mostrará los proyectos con una asignación vigente para ese día y completará automáticamente el cliente, la tarifa y la referencia de la asignación.',
            'Indique la actividad realizada y las horas efectivamente trabajadas ese día.',
            'Las horas aprobadas representan la cantidad finalmente validada para control, cálculo y procesos posteriores.',
            'La tarifa aplicable y el cliente se obtienen automáticamente desde la asignación o el proyecto correspondiente.',
        ],
    ],
    'projects' => [
        'title' => '¿Cómo completar el proyecto?',
        'bullets' => [
            'Seleccione el cliente antes de completar los datos comerciales.',
            'Defina la moneda o unidad de venta del proyecto.',
            'La tarifa HH puede pactarse en UF o moneda.',
            'Los valores en UF o moneda extranjera se convierten usando el valor correspondiente a la fecha aplicable.',
            'Las ventas del proyecto respetan la moneda definida para éste.',
            'Solo registros vigentes pueden asignarse a nuevas operaciones.',
            'Las relaciones históricas se conservan aunque posteriormente queden inactivas.',
        ],
    ],
    'assignments' => [
    'title' => '¿Cómo completar la asignación?',
        'bullets' => [
            'Define cómo se remunera la participación de esta persona y durante qué período aplica en el proyecto.',
            'Usa Valor HH cuando el acuerdo considera una tarifa por cada hora de trabajo registrada.',
            'Usa Monto pactado de la asignación cuando existe un monto fijo para la participación o para un hito acordado.',
            'Un valor 0,00 significa que esa modalidad no se utilizará en la asignación.',
            'Si completas Valor HH y Monto pactado de la asignación, el sistema mostrará una advertencia para que revises el acuerdo contractual.',
            'Las fechas corresponden a la vigencia de la asignación y pueden diferir de las del proyecto, pero se advertirá si quedan fuera de su rango.',
        ],
    ],
])
@php($resourceSectionMap = [
    'assignments' => [
        'person_id' => 'Asignación',
        'client_id' => 'Asignación',
        'project_id' => 'Asignación',
        'hourly_rate_unit_type' => 'Tarifa',
        'hourly_rate_currency_id' => 'Tarifa',
        'hourly_value' => 'Tarifa',
        'project_value' => 'Tarifa',
        'monthly_hours' => 'Tarifa',
        'cost_center_id' => 'Tarifa',
        'start_date' => 'Vigencia',
        'end_date' => 'Vigencia',
        'assignment_status_id' => 'Vigencia',
    ],
    'time-entries' => [
        'entry_date' => 'Datos base',
        'person_id' => 'Datos base',
        'client_id' => 'Datos base',
        'project_id' => 'Datos base',
        'activity_id' => 'Datos base',
        'hours_worked' => 'Horas',
        'hours_approved' => 'Horas',
        'hourly_value' => 'Horas',
        'cost_center_id' => 'Control',
        'approval_status_id' => 'Control',
        'payment_status' => 'Control',
    ],
])
@php($resourceFieldHelp = [
    'assignments' => [
        'person_id' => 'Persona que quedará asociada al proyecto durante la vigencia indicada.',
        'project_id' => 'Proyecto al que se asigna la persona.',
        'start_date' => 'Fecha desde la cual comienza la vigencia de esta asignación. Puede ser distinta a la del proyecto, pero se advertirá si inicia antes de su rango.',
        'end_date' => 'Fecha hasta la cual se mantiene vigente esta asignación. Puede ser distinta a la del proyecto, pero se advertirá si termina después de su rango.',
        'hourly_rate_unit_type' => 'Unidad monetaria usada para expresar la tarifa por hora de esta asignación.',
        'hourly_value' => 'Monto correspondiente a una hora de trabajo. Si ingresa 0,00, el sistema entiende que no se utilizará tarifa por hora.',
        'project_value' => 'Monto fijo acordado para la participación o para un hito de esta asignación. Si ingresa 0,00, el sistema entiende que no se utilizará monto fijo.',
        'monthly_hours' => 'Cantidad de horas de esta asignación consideradas por mes. Se usan como capacidad referencial cuando el sistema necesita estimar horas vigentes.',
    ],
    'time-entries' => [
        'person_id' => 'Persona a la que corresponde este registro de horas.',
        'project_id' => 'Proyecto asociado a una asignación válida para la persona y la fecha seleccionadas.',
        'client_id' => 'Cliente derivado automáticamente desde el proyecto seleccionado.',
        'entry_date' => 'Fecha en que se trabajaron las horas. Debe quedar dentro de la vigencia de la asignación aplicable.',
        'activity_id' => 'Actividad registrada para identificar el trabajo realizado.',
        'hours_worked' => 'Horas efectivamente registradas para esta persona, proyecto y fecha. Cada registro diario debe ser mayor que 0 y no puede superar 24 horas.',
        'hours_approved' => 'Horas finalmente aprobadas para control, cálculo y procesos posteriores. No pueden superar las horas trabajadas.',
        'hourly_value' => 'Tarifa por hora obtenida automáticamente desde la asignación vigente o, cuando corresponda, desde el proyecto.',
        'cost_center_id' => 'Centro de costo asociado al registro. Si la asignación ya lo define, se propone automáticamente como referencia.',
        'approval_status_id' => 'Estado actual de revisión del registro de horas.',
        'payment_status' => 'Estado manual de pago del registro. Solo corresponde marcarlo como pagado cuando la aprobación ya está resuelta.',
    ],
])
@php($resourceLayoutOverrides = [
    'assignments' => [
        'person_id' => 'col-12 col-md-6 col-xl-4',
        'client_id' => 'col-12 col-md-6 col-xl-4',
        'project_id' => 'col-12 col-md-6 col-xl-4',
        'hourly_rate_unit_type' => 'col-12 col-md-6 col-xl-3',
        'hourly_rate_currency_id' => 'col-12 col-md-6 col-xl-3',
        'hourly_value' => 'col-12 col-md-6 col-xl-3',
        'project_value' => 'col-12 col-md-6 col-xl-3',
        'monthly_hours' => 'col-12 col-md-6 col-xl-3',
        'cost_center_id' => 'col-12 col-md-6 col-xl-4',
        'start_date' => 'col-12 col-md-6 col-xl-3',
        'end_date' => 'col-12 col-md-6 col-xl-3',
        'assignment_status_id' => 'col-12 col-md-6 col-xl-3',
    ],
    'time-entries' => [
        'entry_date' => 'col-12 col-md-6 col-xl-3',
        'person_id' => 'col-12 col-md-6 col-xl-4',
        'client_id' => 'col-12 col-md-6 col-xl-4',
        'project_id' => 'col-12 col-md-6 col-xl-4',
        'activity_id' => 'col-12 col-md-6 col-xl-4',
        'hours_worked' => 'col-12 col-md-6 col-xl-3',
        'hours_approved' => 'col-12 col-md-6 col-xl-3',
        'hourly_value' => 'col-12 col-md-6 col-xl-3',
        'cost_center_id' => 'col-12 col-md-6 col-xl-4',
        'approval_status_id' => 'col-12 col-md-6 col-xl-3',
        'payment_status' => 'col-12 col-md-6 col-xl-3',
    ],
])
@php($resourceSectionLabels = $resourceSectionMap[$resource] ?? [])
@php($resourceColumns = $resourceLayoutOverrides[$resource] ?? [])
@php($sharedRateUnitFields = in_array($resource, ['people', 'assignments'], true) && array_key_exists('hourly_rate_unit_type', $fields) && array_key_exists('hourly_rate_currency_id', $fields))
@php($selectedRateUnitType = old('hourly_rate_unit_type', $item->hourly_rate_unit_type ?? 'UF'))
@php($selectedRateCurrencyId = old('hourly_rate_currency_id', $item->hourly_rate_currency_id ?? null))
@php($selectedRateCurrency = $selectedRateCurrencyId !== null && isset($options['hourly_rate_currency_id'][$selectedRateCurrencyId]) ? $options['hourly_rate_currency_id'][$selectedRateCurrencyId] : null)
@php($selectedRateUnitPrefix = $selectedRateUnitType === 'UF' ? 'UF' : (string) ($selectedRateCurrency['currency_symbol'] ?? $selectedRateCurrency['currency_code'] ?? 'Moneda'))
@php($timeEntryRatePreview = $resource === 'time-entries' ? app(\App\Services\HourlyRateService::class)->resolveForEntry($item) : null)
@php($timeEntryRatePreviewCurrency = data_get($timeEntryRatePreview, 'currency'))
@php($timeEntryRatePreviewCode = strtoupper((string) (data_get($timeEntryRatePreview, 'currency_code') ?: ($timeEntryRatePreviewCurrency instanceof \App\Models\Currency ? $timeEntryRatePreviewCurrency->code : 'CLP'))))
@php($timeEntryRatePreviewPrefix = $timeEntryRatePreviewCode === 'UF'
    ? 'UF'
    : ($timeEntryRatePreviewCurrency instanceof \App\Models\Currency ? ($timeEntryRatePreviewCurrency->symbol ?: $timeEntryRatePreviewCode) : ($timeEntryRatePreviewCode === 'CLP' ? '$' : $timeEntryRatePreviewCode)))
@php($timeEntryRatePreviewDecimals = $timeEntryRatePreviewCode === 'CLP'
    ? 0
    : ($timeEntryRatePreviewCode === 'UF'
        ? 2
        : (int) data_get($timeEntryRatePreviewCurrency, 'minor_units', 2)))
@php($timeEntryRatePreviewAmount = data_get($timeEntryRatePreview, 'amount'))
@php($timeEntryRatePreviewDisplay = $timeEntryRatePreviewAmount !== null ? \App\Support\UiFormatter::formatNumber($timeEntryRatePreviewAmount, $timeEntryRatePreviewDecimals) : null)
@php($timeEntryRatePreviewSource = data_get($timeEntryRatePreview, 'source_label'))
@php($timeEntryRatePreviewProjectName = data_get($timeEntryRatePreview, 'project_name'))
@php($assignmentSelectedProjectId = $resource === 'assignments' ? old('project_id', $item->project_id ?? null) : null)
@php($assignmentSelectedProject = $assignmentSelectedProjectId !== null ? ($options['project_id'][$assignmentSelectedProjectId] ?? null) : null)
@php($assignmentProjectSaleNet = data_get($assignmentSelectedProject, 'project_sale_net'))
@php($assignmentProjectSaleCurrencyCode = data_get($assignmentSelectedProject, 'project_sale_currency_code', 'CLP'))
@php($assignmentProjectSaleCurrencySymbol = data_get($assignmentSelectedProject, 'project_sale_currency_symbol', '$'))
@php($assignmentProjectSaleMinorUnits = (int) data_get($assignmentSelectedProject, 'project_sale_minor_units', 0))
@php($assignmentProjectStartDate = data_get($assignmentSelectedProject, 'project_start_date'))
@php($assignmentProjectEndDate = data_get($assignmentSelectedProject, 'project_end_date'))
@php($assignmentProjectSaleDisplay = match (true) {
    $assignmentSelectedProject === null => 'Venta neta proyecto: Seleccione un proyecto.',
    $assignmentProjectSaleNet !== null => 'Venta neta proyecto: '.\App\Support\UiFormatter::formatMoney($assignmentProjectSaleNet, $assignmentProjectSaleCurrencyCode),
    default => 'Venta neta proyecto: No informada',
})
@php($assignmentHourlyValue = old('hourly_value', $item->hourly_value ?? null))
@php($assignmentProjectValue = old('project_value', $item->project_value ?? null))
@php($assignmentHasBothValues = is_numeric($assignmentHourlyValue) && (float) $assignmentHourlyValue > 0 && is_numeric($assignmentProjectValue) && (float) $assignmentProjectValue > 0)
@php($assignmentProjectExceedsSale = $assignmentProjectSaleNet !== null && is_numeric($assignmentProjectValue) && (float) $assignmentProjectValue > (float) $assignmentProjectSaleNet)
@php($assignmentStartDate = old('start_date', optional($item->start_date)->format('d/m/Y')))
@php($assignmentEndDate = old('end_date', optional($item->end_date)->format('d/m/Y')))
@php($assignmentProjectVigencyDisplay = match (true) {
    $assignmentSelectedProject === null => 'Vigencia proyecto: Seleccione un proyecto.',
    filled($assignmentProjectStartDate) && filled($assignmentProjectEndDate) => 'Vigencia proyecto: '.\App\Support\UiFormatter::formatDate($assignmentProjectStartDate).' al '.\App\Support\UiFormatter::formatDate($assignmentProjectEndDate),
    filled($assignmentProjectStartDate) => 'Vigencia proyecto desde '.\App\Support\UiFormatter::formatDate($assignmentProjectStartDate),
    filled($assignmentProjectEndDate) => 'Vigencia proyecto hasta '.\App\Support\UiFormatter::formatDate($assignmentProjectEndDate),
    default => 'Vigencia proyecto: No informada',
})
@php($assignmentProjectDateRangeWarning = match (true) {
    filled($assignmentProjectStartDate)
        && filled($assignmentProjectEndDate)
        && filled($assignmentStartDate)
        && filled($assignmentEndDate)
        && (\App\Support\UiFormatter::parseDateInput($assignmentStartDate)?->lt(\Illuminate\Support\Carbon::parse($assignmentProjectStartDate)))
        && (\App\Support\UiFormatter::parseDateInput($assignmentEndDate)?->gt(\Illuminate\Support\Carbon::parse($assignmentProjectEndDate)))
        => 'La vigencia de la asignación inicia antes y termina después de la vigencia del proyecto seleccionado.',
    filled($assignmentProjectStartDate)
        && filled($assignmentStartDate)
        && (\App\Support\UiFormatter::parseDateInput($assignmentStartDate)?->lt(\Illuminate\Support\Carbon::parse($assignmentProjectStartDate)))
        => 'La vigencia de la asignación inicia antes de la vigencia del proyecto seleccionado.',
    filled($assignmentProjectEndDate)
        && filled($assignmentEndDate)
        && (\App\Support\UiFormatter::parseDateInput($assignmentEndDate)?->gt(\Illuminate\Support\Carbon::parse($assignmentProjectEndDate)))
        => 'La vigencia de la asignación termina después de la vigencia del proyecto seleccionado.',
    default => null,
})
@php($formTitle = match ($resource) {
    'assignments' => $editing ? 'Editar asignación' : 'Nueva asignación',
    default => $editing ? ($config['edit_title'] ?? ('Editar '.$config['title'])) : ($config['create_title'] ?? ('Nuevo '.$config['title'])),
})
@php($formSubtitle = match ($resource) {
    'time-entries' => 'La asignación vigente, el cliente y la tarifa aplicable se validan y actualizan automáticamente antes de guardar.',
    default => 'Los cálculos financieros asociados se actualizan al guardar.',
})
@php($timeEntrySelectedPersonId = $resource === 'time-entries' ? old('person_id', $item->person_id ?? null) : null)
@php($timeEntrySelectedProjectId = $resource === 'time-entries' ? old('project_id', $item->project_id ?? null) : null)
@php($timeEntrySelectedProject = $resource === 'time-entries' && $timeEntrySelectedProjectId !== null ? ($options['project_id'][$timeEntrySelectedProjectId] ?? null) : null)
@php($timeEntrySelectedProjectRanges = collect(data_get($timeEntrySelectedProject, 'assignment_ranges', [])))
@php($timeEntryEntryDate = $resource === 'time-entries' ? old('entry_date', optional($item->entry_date)->format('d/m/Y')) : null)
@php($timeEntryEntryDateParsed = $resource === 'time-entries' ? \App\Support\UiFormatter::parseDateInput($timeEntryEntryDate) : null)
@php($timeEntryMatchingRanges = $timeEntrySelectedProjectRanges->filter(function (array $range) use ($timeEntrySelectedPersonId, $timeEntryEntryDateParsed) {
    if ((string) ($range['person_id'] ?? '') !== (string) $timeEntrySelectedPersonId) {
        return false;
    }

    if (! $timeEntryEntryDateParsed) {
        return false;
    }

    $start = ! empty($range['start_date']) ? \Illuminate\Support\Carbon::parse($range['start_date']) : null;
    $end = ! empty($range['end_date']) ? \Illuminate\Support\Carbon::parse($range['end_date']) : null;

    return (! $start || $start->lte($timeEntryEntryDateParsed)) && (! $end || $end->gte($timeEntryEntryDateParsed));
}))
@php($timeEntrySelectedRange = $timeEntryMatchingRanges->count() === 1 ? $timeEntryMatchingRanges->first() : null)
@php($timeEntryPersonRanges = $timeEntrySelectedProjectRanges->filter(fn (array $range) => (string) ($range['person_id'] ?? '') === (string) $timeEntrySelectedPersonId)->values())
@php($timeEntryAssignmentLabel = match (true) {
    $resource !== 'time-entries' => null,
    $timeEntrySelectedRange !== null => 'Asignación: '.($timeEntrySelectedRange['code'] ?? $timeEntrySelectedRange['source_label'] ?? 'Asignación vigente'),
    $timeEntrySelectedProject === null => 'Asignación: Seleccione una persona y un proyecto.',
    $timeEntryPersonRanges->isNotEmpty() => 'Asignación: Revise la vigencia de la asignación para la fecha indicada.',
    default => 'Asignación: No existe una asignación válida para esta persona y proyecto.',
})
@php($timeEntryAssignmentProjectLabel = match (true) {
    $resource !== 'time-entries' => null,
    $timeEntrySelectedRange !== null => 'Proyecto: '.($timeEntrySelectedRange['project_name'] ?? data_get($timeEntrySelectedProject, 'label') ?? 'No informado'),
    $timeEntrySelectedProject !== null => 'Proyecto: '.(data_get($timeEntrySelectedProject, 'project_name') ?: data_get($timeEntrySelectedProject, 'label') ?: 'No informado'),
    default => 'Proyecto: Seleccione una persona y una fecha.',
})
@php($timeEntryAssignmentVigencyLabel = match (true) {
    $resource !== 'time-entries' => null,
    $timeEntrySelectedRange !== null && ! empty($timeEntrySelectedRange['start_date']) && ! empty($timeEntrySelectedRange['end_date']) => 'Vigencia: '.\App\Support\UiFormatter::formatDate($timeEntrySelectedRange['start_date']).' al '.\App\Support\UiFormatter::formatDate($timeEntrySelectedRange['end_date']),
    $timeEntrySelectedRange !== null && ! empty($timeEntrySelectedRange['start_date']) => 'Vigencia: desde '.\App\Support\UiFormatter::formatDate($timeEntrySelectedRange['start_date']),
    $timeEntrySelectedRange !== null && ! empty($timeEntrySelectedRange['end_date']) => 'Vigencia: hasta '.\App\Support\UiFormatter::formatDate($timeEntrySelectedRange['end_date']),
    $resource === 'time-entries' && $timeEntrySelectedProject !== null => 'Vigencia: No informada',
    default => 'Vigencia: Seleccione una persona y un proyecto.',
})
@php($timeEntryContextClient = match (true) {
    $resource !== 'time-entries' => null,
    $timeEntrySelectedProject !== null && filled(data_get($timeEntrySelectedProject, 'client_label')) => 'Cliente: '.data_get($timeEntrySelectedProject, 'client_label'),
    $timeEntrySelectedProject !== null => 'Cliente: No informado',
    default => 'Cliente: Se completará automáticamente.',
})
@php($timeEntryContextCostCenter = match (true) {
    $resource !== 'time-entries' => null,
    $timeEntrySelectedRange !== null && filled($timeEntrySelectedRange['cost_center_name'] ?? null) => 'Centro de costo: '.($timeEntrySelectedRange['cost_center_name'] ?? ''),
    default => null,
})
@php($timeEntryOutOfRange = $resource === 'time-entries' && $timeEntrySelectedProject !== null && $timeEntryPersonRanges->count() === 1 && $timeEntryMatchingRanges->isEmpty())
@php($timeEntryOutOfRangeStart = $timeEntryOutOfRange && ! empty($timeEntryPersonRanges->first()['start_date']) ? \App\Support\UiFormatter::formatDate($timeEntryPersonRanges->first()['start_date']) : null)
@php($timeEntryOutOfRangeEnd = $timeEntryOutOfRange && ! empty($timeEntryPersonRanges->first()['end_date']) ? \App\Support\UiFormatter::formatDate($timeEntryPersonRanges->first()['end_date']) : null)
@php($timeEntryOutOfRangeLabel = match (true) {
    $timeEntryOutOfRangeStart && $timeEntryOutOfRangeEnd => $timeEntryOutOfRangeStart.' al '.$timeEntryOutOfRangeEnd,
    $timeEntryOutOfRangeStart => 'desde '.$timeEntryOutOfRangeStart,
    $timeEntryOutOfRangeEnd => 'hasta '.$timeEntryOutOfRangeEnd,
    $timeEntryOutOfRange => 'sin vigencia informada',
    default => null,
})
@php($timeEntryContextWarning = match (true) {
    $resource !== 'time-entries' => null,
    $timeEntryMatchingRanges->count() > 1 => 'Existe más de una asignación vigente para esta persona y proyecto en la fecha indicada. Revise la asignación correspondiente antes de registrar horas.',
    $timeEntryOutOfRange => 'La fecha registrada está fuera de la vigencia de la asignación ('.$timeEntryOutOfRangeLabel.').',
    default => null,
})
@php($payrollDependentOnly = $payrollViewMeta['dependent_only_fields'] ?? [])
@php($payrollHonorariosOnly = $payrollViewMeta['honorarios_only_fields'] ?? [])
@php($payrollDisplayValue = function (string $field, array $definition, mixed $value) use ($item) {
    $type = $definition['type'] ?? 'text';
    $presentation = $definition['presentation'] ?? null;

    if ($value === null || $value === '') {
        return '—';
    }

    if ($type === 'money') {
        return \App\Support\UiFormatter::formatMoney($value, $definition['currency'] ?? 'CLP');
    }

    if ($presentation === 'rut') {
        return \App\Support\ChileanRut::format((string) $value) ?? '—';
    }

    if ($presentation === 'percent') {
        return \App\Support\UiFormatter::formatPercent($value);
    }

    if ($presentation === 'hours') {
        return \App\Support\UiFormatter::formatHours($value);
    }

    if (in_array($type, ['decimal', 'number'], true)) {
        return \App\Support\UiFormatter::formatNumber($value);
    }

    if ($type === 'date' && $value) {
        try {
            return \App\Support\UiFormatter::formatDate($value);
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    return (string) $value;
})
@php($payrollWarnings = [])
@php($selectedPayrollPerson = $isPayroll ? (($options['person_id'][$item->person_id] ?? null) ?: null) : null)
@php($selectedPayrollTitle = is_array($selectedPayrollPerson) ? ($selectedPayrollPerson['label'] ?? null) : null)
@php($selectedPayrollSegments = collect([
    is_array($selectedPayrollPerson) ? ($selectedPayrollPerson['payroll_mode_label'] ?? null) : null,
    is_array($selectedPayrollPerson) ? ($selectedPayrollPerson['payroll_contract_label'] ?? null) : null,
])->filter()->implode(' · '))
@php($selectedPayrollExtras = collect([
    is_array($selectedPayrollPerson) && !empty($selectedPayrollPerson['payroll_afp_label']) ? 'AFP '.$selectedPayrollPerson['payroll_afp_label'] : null,
    is_array($selectedPayrollPerson) && !empty($selectedPayrollPerson['payroll_health_label']) ? 'Salud '.$selectedPayrollPerson['payroll_health_label'] : null,
    is_array($selectedPayrollPerson) && filled($selectedPayrollPerson['payroll_monthly_value'] ?? null) ? 'Base mensual '.\App\Support\UiFormatter::formatMoney($selectedPayrollPerson['payroll_monthly_value']) : null,
    is_array($selectedPayrollPerson) && filled($selectedPayrollPerson['payroll_hourly_value'] ?? null) ? 'Tarifa hora base '.\App\Support\UiFormatter::formatMoney($selectedPayrollPerson['payroll_hourly_value'], $selectedPayrollPerson['payroll_hourly_currency'] ?? 'CLP').' / HH' : null,
])->filter()->implode(' · '))
@if ($isPayroll)
    @if (($item->calculation_status ?? null) && $item->calculation_status !== 'OK')
        @php($payrollWarnings[] = $item->calculation_status)
    @endif
    @if (filled($item->calculation_notes))
        @php($payrollWarnings[] = $item->calculation_notes)
    @endif
@endif
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $formTitle }}</h1>
        <div class="page-subtitle">{{ $formSubtitle }}</div>
    </div>
    <a class="btn btn-outline-secondary" href="{{ route('operational.index', $resource) }}">Volver</a>
</div>

@if (! empty($pageHelp[$resource] ?? null))
    <x-page-help :id="$resource.'-page-help'" :title="$pageHelp[$resource]['title']" :bullets="$pageHelp[$resource]['bullets']" />
@endif

@if ($isPayroll)
    <div class="app-panel p-3 mb-4 payroll-help-shell">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <button class="payroll-help-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#payrollUsageHelp" aria-expanded="false" aria-controls="payrollUsageHelp">
                <i class="bi bi-question-circle"></i>
                <span>¿Cómo usar esta pantalla?</span>
                <i class="bi bi-chevron-down small"></i>
            </button>
            <a class="small text-decoration-none" data-bs-toggle="collapse" href="#payrollConceptDetail" role="button" aria-expanded="false" aria-controls="payrollConceptDetail">
                Ver detalle de conceptos
            </a>
        </div>

        <div class="collapse mt-3" id="payrollUsageHelp">
            <ul class="small text-muted mb-3 ps-3">
                <li>Seleccione Persona, Proyecto y Período primero. El sistema completa la referencia base y valida la asignación vigente del mes.</li>
                <li>Los campos marcados como override reemplazan un valor automático del período; déjelos vacíos cuando quiera usar la referencia base.</li>
                <li>La modalidad, el contrato, AFP, salud y la tarifa base provienen de la ficha de Personal o de las novedades del período.</li>
                <li>Las novedades de remuneración alimentan horas aprobadas, bonos, asignaciones no imponibles, anticipos y otros descuentos cuando existen.</li>
                <li>Para honorarios, Base, retención y líquido se calculan automáticamente según el período.</li>
                <li>Para dependientes, AFP, salud, AFC, IUSC y aportes del empleador se calculan según el período legal vigente.</li>
                <li>La provisión de vacaciones afecta costo y análisis financiero, no el líquido ni la caja real.</li>
                <li>Use “Recalcular” antes de confirmar si cambió algún dato base del período.</li>
                <li>Una remuneración confirmada o cerrada no debe modificarse sin el flujo correspondiente.</li>
                <li>Revise siempre el bloque “Costo empresa” antes de confirmar.</li>
            </ul>
            <div class="payroll-help-legend small">
                <span><i class="bi bi-info-circle-fill"></i> ayuda del campo</span>
                <span><i class="bi bi-calculator"></i> calculado automáticamente</span>
                <span><i class="bi bi-pencil"></i> dato ingresado manualmente</span>
            </div>
        </div>

        <div class="collapse payroll-help-detail mt-3" id="payrollConceptDetail">
            <div class="payroll-summary-list">
                @foreach ($payrollSummary as $summary)
                    <div class="payroll-summary-item">
                        <strong>{{ $summary['label'] }}</strong>
                        <span>{{ $summary['text'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif

@if ($isPayroll)
    <div class="app-panel p-3 mb-4 payroll-person-shell">
        <div id="payrollPersonSummary"
             class="payroll-person-summary"
             data-empty-title="Seleccione una persona"
             data-empty-meta="La modalidad, contrato, AFP, salud y valores base se obtienen desde la ficha de Personal."
             data-empty-extra="Luego revise el período y complete solo conceptos extraordinarios si corresponde.">
            <div class="payroll-person-title">{{ $selectedPayrollTitle ?: 'Seleccione una persona' }}</div>
            <div class="payroll-person-meta">{{ $selectedPayrollSegments ?: 'La modalidad, contrato, AFP, salud y valores base se obtienen desde la ficha de Personal.' }}</div>
            <div class="payroll-person-extra">{{ $selectedPayrollExtras ?: 'Luego revise el período y complete solo conceptos extraordinarios si corresponde.' }}</div>
        </div>
        <div class="small text-muted mt-2">
            Los campos marcados como override reemplazan la referencia automática del período. Déjelos vacíos solo cuando corresponda usar el valor base.
        </div>
    </div>
@endif

@if ($isPayroll && ! empty($payrollWarnings))
    <div class="alert alert-warning app-panel">
        <div class="fw-semibold mb-1">Revisión requerida</div>
        <ul class="mb-0 ps-3">
            @foreach ($payrollWarnings as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($isPayroll && $editing && ! empty($payrollHourlyCost))
    <div class="app-panel p-3 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="section-title mb-0">Costo HH del período</div>
            @if (! empty($payrollCalculationBreakdown))
                <x-calculation-breakdown
                    id="payroll-edit-breakdown"
                    title="Cálculo de remuneración"
                    subtitle="Snapshot histórico del período"
                    :breakdown="$payrollCalculationBreakdown"
                    trigger-class="btn btn-sm btn-outline-secondary"
                />
            @endif
        </div>
        <div class="row g-3">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="text-muted small">Costo empresa</div>
                <div class="fw-semibold">{{ \App\Support\UiFormatter::formatMoney($payrollHourlyCost['company_cost']) }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="text-muted small">Horas período</div>
                <div class="fw-semibold">{{ \App\Support\UiFormatter::formatHours($payrollHourlyCost['worked_hours']) }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="text-muted small">Costo HH real</div>
                <div class="fw-semibold">{{ $payrollHourlyCost['real_hourly_cost'] !== null ? \App\Support\UiFormatter::formatMoney($payrollHourlyCost['real_hourly_cost']) : '—' }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="text-muted small">Costo HH ref.</div>
                <div class="fw-semibold">{{ $payrollHourlyCost['reference_hourly_cost'] !== null ? \App\Support\UiFormatter::formatMoney($payrollHourlyCost['reference_hourly_cost']) : '—' }}</div>
            </div>
        </div>
        <div class="small text-muted mt-3">
            Costo HH real = costo empresa del período / horas productivas registradas.
            {{ $payrollHourlyCost['reference_capacity_label'] ?? '' }}
            @if (! empty($payrollHourlyCost['real_hourly_cost_message']))
                {{ $payrollHourlyCost['real_hourly_cost_message'] }}
            @endif
        </div>
    </div>
@endif

@if ($resource === 'sales-documents' && ! empty($salesCalculationBreakdown))
    <div class="app-panel p-3 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="section-title mb-1">Cálculo de venta</div>
                <div class="small text-muted">Desglose del documento y sus parámetros.</div>
            </div>
            <x-calculation-breakdown
                id="sales-edit-breakdown"
                title="Cálculo de venta"
                subtitle="{{ $editing ? 'Documento guardado o en edición' : 'Prefacturación' }}"
                :breakdown="$salesCalculationBreakdown"
                trigger-class="btn btn-sm btn-outline-secondary"
            />
        </div>
    </div>
@endif

<form method="POST" action="{{ $editing ? route('operational.update', [$resource, $item->id]) : route('operational.store', $resource) }}" class="app-panel p-4" data-operational-form="true">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    @if ($isPayroll)
        @foreach ($payrollSections as $sectionTitle => $sectionFields)
            @php($visibleFields = collect($sectionFields)->filter(fn (string $field) => array_key_exists($field, $fields))->values()->all())
            @continue(empty($visibleFields))
            <div class="section-title">{{ $sectionTitle }}</div>
            <div class="row g-3 mb-3">
                @foreach ($visibleFields as $field)
                    @php($definition = $fields[$field])
                    @php($type = $definition['type'] ?? 'text')
                    @php($value = old($field, $item->{$field}))
                    @if ($field === 'phone_country_code' && blank($value))
                        @php($value = '+56')
                    @endif
                    @php($colClass = $definition['col'] ?? 'col-12 col-md-6')
                    @php($label = $definition['label'])
                    @php($helpText = $payrollHelp[$field] ?? $projectHelp[$field] ?? $rateUnitHelp[$field] ?? null)
                    @php($isCalculated = in_array($field, $payrollAutoFields, true))
                    @php($isManual = in_array($field, $payrollManualFields, true))
                    @php($visibilityMode = in_array($field, $payrollDependentOnly, true) ? 'dependent' : (in_array($field, $payrollHonorariosOnly, true) ? 'honorarios' : 'all'))
                    <div class="{{ $colClass }} payroll-field-wrapper"
                         data-payroll-field-wrapper="true"
                         data-payroll-field="{{ $field }}"
                         data-payroll-visibility="{{ $visibilityMode }}">
                        @if ($field === 'code' && $autoCode)
                            <label for="{{ $field }}" class="form-label payroll-field-label">
                                <span>{{ $label }}</span>
                                @if ($helpText)
                                    <x-field-help :text="$helpText" />
                                @endif
                                <span class="payroll-field-marker calculated" title="Calculado automáticamente" aria-hidden="true">
                                    <i class="bi bi-calculator"></i>
                                </span>
                            </label>
                            @if ($editing)
                                <input id="{{ $field }}" class="form-control payroll-calculated-field" value="{{ $value }}" readonly aria-readonly="true">
                            @else
                                <div class="form-control bg-light text-muted">{{ $codeMeta['label'] ?? 'Se generará automáticamente' }}</div>
                            @endif
                            @continue
                        @endif
                        @if ($type === 'checkbox')
                            <div class="form-check mt-4">
                                <input type="checkbox" class="form-check-input" id="{{ $field }}" name="{{ $field }}" value="1" @checked((bool) $value)>
                                <label class="form-check-label payroll-field-label" for="{{ $field }}">
                                    <span>{{ $label }}</span>
                                    @if ($helpText)
                                        <x-field-help :text="$helpText" />
                                    @endif
                                </label>
                            </div>
                        @else
                            <label for="{{ $field }}" class="form-label payroll-field-label">
                                <span>{{ $label }}</span>
                                @if ($helpText)
                                    <x-field-help :text="$helpText" />
                                @endif
                                @if ($isCalculated)
                                    <span class="payroll-field-marker calculated" title="Calculado automáticamente" aria-hidden="true">
                                        <i class="bi bi-calculator"></i>
                                    </span>
                                @elseif ($isManual)
                                    <span class="payroll-field-marker manual" title="Dato manual" aria-hidden="true">
                                        <i class="bi bi-pencil"></i>
                                    </span>
                                @endif
                            </label>
                            @if (($definition['readonly'] ?? false) === true)
                                <input
                                    id="{{ $field }}"
                                    class="form-control payroll-calculated-field"
                                    value="{{ $payrollDisplayValue($field, $definition, $value) }}"
                                    readonly
                                    aria-readonly="true"
                                >
                            @elseif ($type === 'textarea')
                                <textarea
                                    id="{{ $field }}"
                                    name="{{ $field }}"
                                    class="form-control {{ $isManual ? 'payroll-manual-field' : '' }}"
                                    rows="{{ $field === 'calculation_notes' ? 2 : 3 }}"
                                    @if ($isManual) aria-describedby="{{ $field }}Help" @endif
                                >{{ $value }}</textarea>
                            @elseif ($type === 'select')
                                <select id="{{ $field }}" name="{{ $field }}" class="form-select {{ $isManual ? 'payroll-manual-field' : '' }}">
                                    <option value="">Seleccione</option>
                                    @foreach (($definition['options'] ?? []) as $key => $optionLabel)
                                        <option value="{{ $key }}" @selected((string) $value === (string) $key)>{{ $optionLabel }}</option>
                                    @endforeach
                                </select>
                            @elseif ($type === 'relation')
                                <select
                                    id="{{ $field }}"
                                    name="{{ $field }}"
                                    class="form-select {{ $isManual ? 'payroll-manual-field' : '' }}"
                                    @if(isset($definition['depends_on']))
                                        data-dependent-select="true"
                                        data-parent-field="{{ $definition['depends_on'] }}"
                                        data-placeholder-default="Seleccione"
                                        data-placeholder-parent="{{ $definition['depends_on'] === 'client_id' ? 'Seleccione un cliente primero' : ($definition['depends_on'] === 'expense_category_id' ? 'Seleccione una categoría primero' : 'Seleccione un valor padre primero') }}"
                                        data-placeholder-empty="{{ $field === 'project_id' ? 'No hay proyectos para este cliente' : ($field === 'expense_subcategory_id' ? 'No hay subcategorías para esta categoría' : 'No hay opciones disponibles') }}"
                                    @endif
                                >
                                    <option value="">Seleccione</option>
                                @foreach (($options[$field] ?? []) as $key => $option)
                                        @php($optionLabel = is_array($option) ? $option['label'] : $option)
                                        @php($parentId = is_array($option) ? ($option['parent_id'] ?? null) : null)
                                        <option value="{{ $key }}"
                                            @selected((string) $value === (string) $key)
                                            @if($parentId) data-parent-id="{{ $parentId }}" @endif
                                            @if($field === 'project_id' && is_array($option) && isset($option['assignment_ranges']))
                                                data-assignment-ranges='@json($option['assignment_ranges'])'
                                            @endif
                                            @if($field === 'person_id' && is_array($option))
                                                data-payroll-mode="{{ $option['payroll_mode'] ?? '' }}"
                                                data-payroll-mode-label="{{ $option['payroll_mode_label'] ?? '' }}"
                                                data-payroll-contract-label="{{ $option['payroll_contract_label'] ?? '' }}"
                                                data-payroll-afp-label="{{ $option['payroll_afp_label'] ?? '' }}"
                                                data-payroll-health-label="{{ $option['payroll_health_label'] ?? '' }}"
                                                data-payroll-monthly-value="{{ $option['payroll_monthly_value'] ?? '' }}"
                                                data-payroll-hourly-value="{{ $option['payroll_hourly_value'] ?? '' }}"
                                                data-payroll-hourly-currency="{{ $option['payroll_hourly_currency'] ?? 'CLP' }}"
                                                data-payroll-start-date="{{ $option['payroll_start_date'] ?? '' }}"
                                                data-payroll-end-date="{{ $option['payroll_end_date'] ?? '' }}"
                                            @endif
                                        >{{ $optionLabel }}</option>
                                    @endforeach
                                </select>
                            @else
                                @php($displayValue = $type === 'date' ? ($value ? \App\Support\UiFormatter::formatDate($value) : null) : ($value instanceof \Carbon\CarbonInterface ? $value->format('Y-m-d') : $value))
                                <input
                                    id="{{ $field }}"
                                    name="{{ $field }}"
                                    type="{{ in_array($type, ['email', 'number'], true) ? $type : 'text' }}"
                                    class="form-control {{ $isCalculated ? 'payroll-calculated-field' : ($isManual ? 'payroll-manual-field' : '') }}"
                                    value="{{ $isCalculated ? $payrollDisplayValue($field, $definition, $displayValue) : $displayValue }}"
                                    @if ($type === 'date') placeholder="dd/mm/yyyy" inputmode="numeric" @endif
                                    @if (($definition['presentation'] ?? null) === 'rut') placeholder="12.345.678-5" @endif
                                    @if (($definition['presentation'] ?? null) === 'phone') placeholder="+56 9 1234 5678" @endif
                                    @if (($definition['presentation'] ?? null) === 'rut') data-rut-field="true" autocomplete="off" @endif
                                    @if ($isCalculated) readonly aria-readonly="true" data-bs-toggle="tooltip" data-bs-trigger="hover focus" data-bs-title="Calculado automáticamente. Para modificar el resultado cambie los datos base o parámetros correspondientes." @endif
                                    @if ($isManual) data-bs-toggle="tooltip" data-bs-trigger="hover focus" data-bs-title="Dato de ingreso manual. Ingrese solo si corresponde al período." @endif
                                >
                            @endif
                            @error($field)
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    @else
        <div class="row g-3">
            @php($currentSection = null)
            @foreach ($fields as $field => $definition)
                @php($fieldSection = $definition['section'] ?? ($resourceSectionLabels[$field] ?? null))
                @if ($fieldSection !== $currentSection)
                    @php($currentSection = $fieldSection)
                    @if ($currentSection)
                        <div class="col-12">
                            <div class="section-title">{{ $currentSection }}</div>
                        </div>
                    @endif
                @endif

                @php($type = $definition['type'] ?? 'text')
                @php($value = old($field, $item->{$field}))
                @if ($field === 'phone_country_code' && blank($value))
                    @php($value = '+56')
                @endif
                @php($colClass = $definition['col'] ?? ($resourceColumns[$field] ?? 'col-12 col-md-6'))
                @if ($field === 'code' && $autoCode)
                    <div class="{{ $colClass }}">
                        <label for="{{ $field }}" class="form-label">Código</label>
                        @if ($editing)
                            <input id="{{ $field }}" class="form-control" value="{{ $value }}" readonly aria-readonly="true">
                        @else
                            <div class="form-control bg-light text-muted">{{ $codeMeta['label'] ?? 'Se generará automáticamente' }}</div>
                        @endif
                    </div>
                    @continue
                @endif
                @if (!($sharedRateUnitFields && $field === 'hourly_rate_currency_id'))
                    <div class="{{ $colClass }}">
                        @if ($type === 'checkbox')
                            <div class="form-check mt-4">
                                <input type="checkbox" class="form-check-input" id="{{ $field }}" name="{{ $field }}" value="1" @checked((bool) $value)>
                                <label class="form-check-label" for="{{ $field }}">{{ $definition['label'] }}</label>
                            </div>
                        @else
                            @php($helpText = $payrollHelp[$field] ?? ($formHelp[$resource][$field] ?? null) ?? ($resourceFieldHelp[$resource][$field] ?? null) ?? $projectHelp[$field] ?? $rateUnitHelp[$field] ?? null)
                            <label for="{{ $field }}" class="form-label field-label-with-help">
                                <span>{{ $definition['label'] }}</span>
                                @if ($helpText)
                                    <x-field-help :text="$helpText" />
                                @endif
                            </label>
                            @if ($resource === 'time-entries' && $field === 'client_id')
                                @php($clientDisplay = old('client_id', $item->client?->legal_name ?? ''))
                                <input type="hidden" id="client_id" name="client_id" value="{{ old('client_id', $item->client_id ?? '') }}" data-time-entry-client-id>
                                <input
                                    id="client_id_display"
                                    class="form-control @error($field) is-invalid @enderror"
                                    value="{{ $clientDisplay ?: '—' }}"
                                    readonly
                                    aria-readonly="true"
                                    data-time-entry-client-display
                                >
                            @elseif ($resource === 'time-entries' && $field === 'hourly_value')
                                <input type="hidden" id="hourly_value" name="hourly_value" value="{{ old('hourly_value', $timeEntryRatePreviewAmount ?? $item->hourly_value ?? '') }}" data-time-entry-rate-raw>
                                <div class="input-group w-100">
                                    <span class="input-group-text rate-unit-chip" data-time-entry-rate-prefix>{{ $timeEntryRatePreviewPrefix }}</span>
                                    <input
                                        id="hourly_value_display"
                                        class="form-control @error($field) is-invalid @enderror"
                                        value="{{ $timeEntryRatePreviewDisplay ?? '' }}"
                                        placeholder="{{ $timeEntryRatePreviewAmount === null ? 'No aplica / No configurada' : '' }}"
                                        readonly
                                        aria-readonly="true"
                                        data-time-entry-rate-display
                                    >
                                    <span class="input-group-text">/ HH</span>
                                </div>
                                <div class="small mt-1 {{ $timeEntryRatePreviewAmount === null ? 'text-warning' : 'text-muted' }}" data-time-entry-rate-message>
                                    {{ $timeEntryRatePreviewAmount === null ? 'No existe una tarifa HH aplicable para esta combinación de persona, proyecto y fecha.' : ($timeEntryRatePreviewSource ? 'Origen: '.$timeEntryRatePreviewSource : 'Tarifa obtenida automáticamente.') }}
                                </div>
                            @elseif ($resource === 'time-entries' && $field === 'project_id')
                                @php($projectValue = old('project_id', $item->project_id ?? ''))
                                <select
                                    id="{{ $field }}"
                                    name="{{ $field }}"
                                    class="form-select @error($field) is-invalid @enderror"
                                    data-time-entry-project-select="true"
                                >
                                    <option value="">Seleccione</option>
                                    @foreach (($options[$field] ?? []) as $key => $option)
                                        @php($label = is_array($option) ? $option['label'] : $option)
                                        @php($parentId = is_array($option) ? ($option['parent_id'] ?? null) : null)
                                        <option
                                            value="{{ $key }}"
                                            @selected((string) $projectValue === (string) $key)
                                            @if($parentId) data-parent-id="{{ $parentId }}" @endif
                                            @if(is_array($option))
                                                data-client-id="{{ $option['client_id'] ?? '' }}"
                                                data-client-label="{{ $option['client_label'] ?? '' }}"
                                                data-project-name="{{ $option['project_name'] ?? $label }}"
                                                data-project-rate-amount="{{ $option['project_rate_amount'] ?? '' }}"
                                                data-project-rate-unit-type="{{ $option['project_rate_unit_type'] ?? '' }}"
                                                data-project-rate-currency-code="{{ $option['project_rate_currency_code'] ?? '' }}"
                                                data-project-rate-currency-symbol="{{ $option['project_rate_currency_symbol'] ?? '' }}"
                                                data-project-rate-minor-units="{{ $option['project_rate_minor_units'] ?? '' }}"
                                                data-assignment-ranges='@json($option['assignment_ranges'] ?? [])'
                                            @endif
                                        >{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div class="app-panel bg-light border-0 p-2 mt-2" data-time-entry-assignment-context>
                                    <div class="small fw-semibold text-muted mb-1">Referencia de la asignación</div>
                                    <div class="small text-muted" data-time-entry-assignment-label>{{ $timeEntryAssignmentLabel }}</div>
                                    <div class="small text-muted" data-time-entry-assignment-project>{{ $timeEntryAssignmentProjectLabel }}</div>
                                    <div class="small text-muted" data-time-entry-assignment-vigency>{{ $timeEntryAssignmentVigencyLabel }}</div>
                                    <div class="small text-muted" data-time-entry-assignment-client>{{ $timeEntryContextClient }}</div>
                                    <div class="small text-muted" data-time-entry-assignment-rate>
                                        Tarifa: {{ $timeEntryRatePreviewAmount !== null ? trim($timeEntryRatePreviewPrefix.' '.($timeEntryRatePreviewDisplay ?? '')).' / HH' : 'No aplica / No configurada' }}
                                    </div>
                                    <div class="small text-muted {{ $timeEntryContextCostCenter ? '' : 'd-none' }}" data-time-entry-assignment-cost-center>{{ $timeEntryContextCostCenter }}</div>
                                </div>
                                <div class="mt-2 {{ $timeEntryContextWarning ? 'alert alert-warning py-2 mb-0' : 'd-none' }}" data-time-entry-context-warning-box>
                                    <div class="{{ $timeEntryContextWarning ? '' : 'd-none' }}" data-time-entry-context-warning>{{ $timeEntryContextWarning }}</div>
                                </div>
                            @elseif ($resource === 'assignments' && $field === 'project_id')
                                @php($assignmentProjectValue = old('project_id', $item->project_id ?? ''))
                                <select
                                    id="{{ $field }}"
                                    name="{{ $field }}"
                                    class="form-select @error($field) is-invalid @enderror"
                                    data-assignments-project-select="true"
                                >
                                    <option value="">Seleccione</option>
                                    @foreach (($options[$field] ?? []) as $key => $option)
                                        @php($label = is_array($option) ? $option['label'] : $option)
                                        @php($parentId = is_array($option) ? ($option['parent_id'] ?? null) : null)
                                        <option
                                            value="{{ $key }}"
                                            @selected((string) $assignmentProjectValue === (string) $key)
                                            @if($parentId) data-parent-id="{{ $parentId }}" @endif
                                            @if(is_array($option))
                                                data-project-sale-net="{{ $option['project_sale_net'] ?? '' }}"
                                                data-project-sale-currency-code="{{ $option['project_sale_currency_code'] ?? '' }}"
                                                data-project-sale-currency-symbol="{{ $option['project_sale_currency_symbol'] ?? '' }}"
                                                data-project-sale-minor-units="{{ $option['project_sale_minor_units'] ?? '' }}"
                                                data-project-start-date="{{ $option['project_start_date'] ?? '' }}"
                                                data-project-end-date="{{ $option['project_end_date'] ?? '' }}"
                                            @endif
                                        >{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div class="app-panel bg-light border-0 p-2 mt-2" data-assignments-project-reference>
                                    <div class="small fw-semibold text-muted mb-1">Referencia del proyecto</div>
                                    <div class="small text-muted" data-assignments-project-sale-net>
                                        {{ $assignmentProjectSaleDisplay }}
                                    </div>
                                    <div class="small text-muted" data-assignments-project-vigency>
                                        {{ $assignmentProjectVigencyDisplay }}
                                    </div>
                                </div>
                            @elseif ($resource === 'time-entries' && $field === 'person_id')
                                <select
                                    id="{{ $field }}"
                                    name="{{ $field }}"
                                    class="form-select @error($field) is-invalid @enderror"
                                    data-time-entry-person-select="true"
                                >
                                    <option value="">Seleccione</option>
                                    @foreach (($options[$field] ?? []) as $key => $option)
                                        @php($label = is_array($option) ? $option['label'] : $option)
                                        <option value="{{ $key }}" @selected((string) $value === (string) $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            @elseif ($resource === 'time-entries' && $field === 'entry_date')
                                <input
                                    id="{{ $field }}"
                                    name="{{ $field }}"
                                    type="text"
                                    class="form-control @error($field) is-invalid @enderror"
                                    value="{{ $value ? \App\Support\UiFormatter::formatDate($value) : '' }}"
                                    placeholder="dd/mm/yyyy"
                                    inputmode="numeric"
                                    data-time-entry-date-input="true"
                                >
                                <div class="mt-2 d-none" data-time-entry-date-validation-box>
                                    <div class="small text-danger" data-time-entry-date-validation></div>
                                </div>
                            @elseif ($sharedRateUnitFields && $field === 'hourly_rate_unit_type')
                                <div class="d-flex flex-wrap align-items-end gap-2">
                                    <div class="flex-grow-1" style="min-width: 220px;">
                                        <select id="hourly_rate_unit_visual" class="form-select" data-rate-unit-selector="true">
                                            <option value="UF" @selected($selectedRateUnitType === 'UF')>UF</option>
                                            @foreach (($options['hourly_rate_currency_id'] ?? []) as $currencyId => $currencyOption)
                                                @php($currencyCode = strtoupper((string) ($currencyOption['currency_code'] ?? $currencyOption['label'] ?? '')))
                                                @php($currencySymbol = (string) ($currencyOption['currency_symbol'] ?? ''))
                                                <option
                                                    value="currency:{{ $currencyId }}"
                                                    data-currency-symbol="{{ $currencySymbol }}"
                                                    data-currency-code="{{ $currencyCode }}"
                                                    @selected($selectedRateUnitType !== 'UF' && (string) $selectedRateCurrencyId === (string) $currencyId)
                                                >
                                                    {{ $currencyCode ?: ($currencyOption['label'] ?? 'Moneda') }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <input type="hidden" name="hourly_rate_unit_type" value="{{ $selectedRateUnitType === 'UF' ? 'UF' : 'CURRENCY' }}" data-rate-unit-type-field>
                                    <input type="hidden" name="hourly_rate_currency_id" value="{{ $selectedRateUnitType === 'UF' ? '' : $selectedRateCurrencyId }}" data-rate-currency-field>
                                </div>
                            @else
                                @include('operational.partials.field-input')
                            @endif
                            @error($field)
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                            @if ($resource === 'time-entries' && $field === 'hours_approved')
                                <div class="mt-2 d-none" data-time-entry-approved-warning-box>
                                    <div class="small text-danger" data-time-entry-approved-warning></div>
                                </div>
                            @endif
                            @if ($resource === 'assignments' && $field === 'monthly_hours')
                                <div class="mt-2 {{ $assignmentHasBothValues || $assignmentProjectExceedsSale ? 'alert alert-warning py-2 mb-0' : 'd-none' }}" data-assignments-tariff-warning-box>
                                    <div class="{{ $assignmentHasBothValues ? '' : 'd-none' }}" data-assignments-warning-double>
                                        Se ingresó una tarifa por hora y un monto fijo. Verifique que ambas condiciones correspondan al acuerdo contractual para evitar duplicidad en la remuneración.
                                    </div>
                                    <div class="{{ $assignmentProjectExceedsSale ? '' : 'd-none' }} mt-1" data-assignments-warning-sale>
                                        El monto pactado de la asignación supera la venta neta del proyecto. Revise el impacto económico antes de guardar.
                                    </div>
                                </div>
                            @endif
                            @if ($resource === 'assignments' && $field === 'end_date')
                                <div class="mt-2 {{ $assignmentProjectDateRangeWarning ? 'alert alert-warning py-2 mb-0' : 'd-none' }}" data-assignments-vigency-warning-box>
                                    <div class="{{ $assignmentProjectDateRangeWarning ? '' : 'd-none' }}" data-assignments-warning-vigency>
                                        {{ $assignmentProjectDateRangeWarning }}
                                    </div>
                                </div>
                            @endif
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    <div class="d-flex justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="{{ route('operational.index', $resource) }}">Cancelar</a>
        <button type="submit" class="btn btn-primary">Guardar</button>
    </div>
</form>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
    (() => {
        const form = document.querySelector('[data-operational-form="true"]');
        const childSelects = document.querySelectorAll('[data-dependent-select="true"]');
        const isPayroll = @json($isPayroll);

        childSelects.forEach((childSelect) => {
            const parentField = childSelect.dataset.parentField;
            const parentSelect = document.getElementById(parentField);
            if (!parentSelect) {
                return;
            }

            const syncOptions = () => {
                const parentValue = parentSelect.value;
                let hasSelectedVisible = false;
                let visibleOptions = 0;
                const placeholder = childSelect.options[0];

                Array.from(childSelect.options).forEach((option, index) => {
                    if (index === 0) {
                        option.hidden = false;
                        return;
                    }

                    const optionParentId = option.dataset.parentId || '';
                    const visible = parentValue === '' || optionParentId === '' || optionParentId === parentValue;
                    option.hidden = !visible;
                    option.disabled = !visible;

                    if (visible && option.selected) {
                        hasSelectedVisible = true;
                    }

                    if (visible) {
                        visibleOptions++;
                    }
                });

                if (!hasSelectedVisible) {
                    childSelect.value = '';
                }

                if (parentValue === '') {
                    childSelect.disabled = true;
                    placeholder.textContent = childSelect.dataset.placeholderParent || 'Seleccione un valor padre primero';
                } else if (visibleOptions === 0) {
                    childSelect.disabled = true;
                    placeholder.textContent = childSelect.dataset.placeholderEmpty || 'No hay opciones disponibles';
                } else {
                    childSelect.disabled = false;
                    placeholder.textContent = childSelect.dataset.placeholderDefault || 'Seleccione';
                }

                if (childSelect.matches('[data-assignments-project-select="true"]') && typeof syncAssignmentContext === 'function') {
                    syncAssignmentContext();
                }
            };

            parentSelect.addEventListener('change', syncOptions);
            syncOptions();
        });

        const rateUnitSelector = document.querySelector('[data-rate-unit-selector="true"]');
        const rateUnitTypeField = document.querySelector('[data-rate-unit-type-field]');
        const rateCurrencyField = document.querySelector('[data-rate-currency-field]');
        const rateUnitPrefixTargets = Array.from(document.querySelectorAll('[data-rate-unit-prefix-for]'));

        const syncRateUnitUi = () => {
            if (!rateUnitSelector || !rateUnitTypeField || !rateCurrencyField) {
                return;
            }

            const value = rateUnitSelector.value || 'UF';
            const isUf = value === 'UF';
            const currencyId = isUf ? '' : value.replace('currency:', '');
            const selectedOption = rateUnitSelector.options[rateUnitSelector.selectedIndex];
            const prefix = isUf
                ? 'UF'
                : (selectedOption?.dataset?.currencySymbol || selectedOption?.dataset?.currencyCode || selectedOption?.textContent?.trim() || 'Moneda');

            rateUnitTypeField.value = isUf ? 'UF' : 'CURRENCY';
            rateCurrencyField.value = currencyId;
            rateUnitPrefixTargets.forEach((target) => {
                target.textContent = prefix;
            });
        };

        rateUnitSelector?.addEventListener('change', syncRateUnitUi);
        syncRateUnitUi();

        if (!form) {
            return;
        }

        if (isPayroll) {
            const personSelect = form.querySelector('#person_id');
            const payrollProjectSelect = form.querySelector('#project_id');
            const payrollPeriodInput = form.querySelector('#period_date');
            const personSummary = document.getElementById('payrollPersonSummary');
            const fieldWrappers = Array.from(form.querySelectorAll('[data-payroll-field-wrapper="true"]'));
            const sectionTitles = Array.from(form.querySelectorAll('.section-title'));
            const honorariosModes = ['HONORARIOS_MENSUAL', 'PAGO_POR_HORA', 'POR_PROYECTO'];

        const moneyFormat = (value, currency = 'CLP') => {
            if (value === null || value === undefined || value === '') {
                return '—';
            }

            const code = String(currency || 'CLP').toUpperCase();
            const decimals = code === 'UF' ? 2 : (code === 'CLP' ? 0 : 2);
            const symbol = {
                CLP: '$',
                UF: 'UF',
                USD: 'US$',
                EUR: '€',
            }[code] || code;

            return symbol + ' ' + new Intl.NumberFormat('es-CL', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            }).format(Number(value));
        };

        const renderPersonSummary = () => {
            if (!personSelect || !personSummary) {
                return;
            }

            const option = personSelect.options[personSelect.selectedIndex];
            if (!option || !option.value) {
                personSummary.querySelector('.payroll-person-title').textContent = personSummary.dataset.emptyTitle;
                personSummary.querySelector('.payroll-person-meta').textContent = personSummary.dataset.emptyMeta;
                personSummary.querySelector('.payroll-person-extra').textContent = personSummary.dataset.emptyExtra;
                return;
            }

            const title = option.textContent.trim();
            const segments = [
                option.dataset.payrollModeLabel,
                option.dataset.payrollContractLabel,
            ].filter(Boolean);

            const extras = [
                option.dataset.payrollAfpLabel ? `AFP ${option.dataset.payrollAfpLabel}` : null,
                option.dataset.payrollHealthLabel ? `Salud ${option.dataset.payrollHealthLabel}` : null,
                option.dataset.payrollMonthlyValue ? `Mensual ${moneyFormat(option.dataset.payrollMonthlyValue)}` : null,
                option.dataset.payrollHourlyValue ? `Hora ${moneyFormat(option.dataset.payrollHourlyValue, option.dataset.payrollHourlyCurrency || 'CLP')}` : null,
            ].filter(Boolean);

            personSummary.querySelector('.payroll-person-title').textContent = title;
            personSummary.querySelector('.payroll-person-meta').textContent = segments.join(' · ') || 'Ficha base sin modalidad visible';
            personSummary.querySelector('.payroll-person-extra').textContent = extras.join(' · ') || personSummary.dataset.emptyExtra;
        };

        const syncPayrollVisibility = () => {
            if (!personSelect) {
                return;
            }

            const option = personSelect.options[personSelect.selectedIndex];
            const currentMode = option?.dataset.payrollMode || '';
            const payrollType = honorariosModes.includes(currentMode) ? 'honorarios' : (currentMode ? 'dependent' : 'all');

            fieldWrappers.forEach((wrapper) => {
                const visibility = wrapper.dataset.payrollVisibility || 'all';
                const shouldShow = payrollType === 'all'
                    ? true
                    : visibility === 'all' || visibility === payrollType;

                wrapper.classList.toggle('d-none', !shouldShow);
            });

            sectionTitles.forEach((title) => {
                const group = title.nextElementSibling;
                if (!group || !group.classList.contains('row')) {
                    return;
                }

                const visibleFields = Array.from(group.querySelectorAll('[data-payroll-field-wrapper="true"]'))
                    .filter((wrapper) => !wrapper.classList.contains('d-none'));

                title.classList.toggle('d-none', visibleFields.length === 0);
                group.classList.toggle('d-none', visibleFields.length === 0);
            });
        };

        const syncPayrollUi = () => {
            renderPersonSummary();
            syncPayrollVisibility();
        };

        const projectAvailableForPayroll = (option) => {
            if (!payrollProjectSelect || !personSelect || !payrollPeriodInput) {
                return true;
            }

            if (!personSelect.value) {
                return false;
            }

            const periodRaw = payrollPeriodInput.value;
            if (!periodRaw) {
                return false;
            }

            const [day, month, year] = periodRaw.split('/');
            if (!day || !month || !year) {
                return false;
            }

            const periodDate = new Date(`${year}-${month}-${day}T00:00:00`);
            const ranges = JSON.parse(option.dataset.assignmentRanges || '[]');

            return ranges.some((range) => {
                if (String(range.person_id) !== String(personSelect.value)) {
                    return false;
                }

                const start = range.start_date ? new Date(`${range.start_date}T00:00:00`) : null;
                const end = range.end_date ? new Date(`${range.end_date}T00:00:00`) : null;

                return (!start || start <= periodDate) && (!end || end >= periodDate);
            });
        };

        const syncPayrollProjects = () => {
            if (!payrollProjectSelect) {
                return;
            }

            const placeholder = payrollProjectSelect.options[0];
            let visibleOptions = 0;
            let selectedVisible = false;

            Array.from(payrollProjectSelect.options).forEach((option, index) => {
                if (index === 0) {
                    option.hidden = false;
                    return;
                }

                const visible = projectAvailableForPayroll(option);
                option.hidden = !visible;
                option.disabled = !visible;
                if (visible) {
                    visibleOptions++;
                }
                if (visible && option.selected) {
                    selectedVisible = true;
                }
            });

            if (!selectedVisible) {
                payrollProjectSelect.value = '';
            }

            if (!personSelect?.value) {
                payrollProjectSelect.disabled = true;
                placeholder.textContent = 'Seleccione una persona primero';
            } else if (!payrollPeriodInput?.value) {
                payrollProjectSelect.disabled = true;
                placeholder.textContent = 'Seleccione el período primero';
            } else if (visibleOptions === 0) {
                payrollProjectSelect.disabled = true;
                placeholder.textContent = 'No existen proyectos asignados para esta persona en el período.';
            } else {
                payrollProjectSelect.disabled = false;
                placeholder.textContent = 'Seleccione';
            }
        };

            personSelect?.addEventListener('change', syncPayrollUi);
            personSelect?.addEventListener('change', syncPayrollProjects);
            payrollPeriodInput?.addEventListener('change', syncPayrollProjects);
            syncPayrollUi();
            syncPayrollProjects();
        }

        if (!form) {
            return;
        }

        const timeEntryPersonSelect = form.querySelector('[data-time-entry-person-select="true"]');
        const timeEntryProjectSelect = form.querySelector('[data-time-entry-project-select="true"]');
        const timeEntryDateInput = form.querySelector('[data-time-entry-date-input="true"]');
        const timeEntryClientHidden = form.querySelector('[data-time-entry-client-id]');
        const timeEntryClientDisplay = form.querySelector('[data-time-entry-client-display]');
        const timeEntryRateRaw = form.querySelector('[data-time-entry-rate-raw]');
        const timeEntryRateDisplay = form.querySelector('[data-time-entry-rate-display]');
        const timeEntryRatePrefix = form.querySelector('[data-time-entry-rate-prefix]');
        const timeEntryRateMessage = form.querySelector('[data-time-entry-rate-message]');
        const timeEntryAssignmentLabel = form.querySelector('[data-time-entry-assignment-label]');
        const timeEntryAssignmentProject = form.querySelector('[data-time-entry-assignment-project]');
        const timeEntryAssignmentVigency = form.querySelector('[data-time-entry-assignment-vigency]');
        const timeEntryAssignmentClient = form.querySelector('[data-time-entry-assignment-client]');
        const timeEntryAssignmentRate = form.querySelector('[data-time-entry-assignment-rate]');
        const timeEntryAssignmentCostCenter = form.querySelector('[data-time-entry-assignment-cost-center]');
        const timeEntryContextWarningBox = form.querySelector('[data-time-entry-context-warning-box]');
        const timeEntryContextWarning = form.querySelector('[data-time-entry-context-warning]');
        const timeEntryDateValidationBox = form.querySelector('[data-time-entry-date-validation-box]');
        const timeEntryDateValidation = form.querySelector('[data-time-entry-date-validation]');
        const timeEntryWorkedInput = form.querySelector('#hours_worked');
        const timeEntryApprovedInput = form.querySelector('#hours_approved');
        const timeEntryApprovedWarningBox = form.querySelector('[data-time-entry-approved-warning-box]');
        const timeEntryApprovedWarning = form.querySelector('[data-time-entry-approved-warning]');
        const timeEntryApprovalStatusSelect = form.querySelector('#approval_status_id');
        const timeEntryPaymentStatusSelect = form.querySelector('#payment_status');
        const timeEntryCostCenterSelect = form.querySelector('#cost_center_id');

        const parseChileanDate = (value) => {
            if (!value) {
                return null;
            }

            const normalizedValue = String(value).trim();
            if (normalizedValue === '') {
                return null;
            }

            if (/^\d{4}-\d{2}-\d{2}$/.test(normalizedValue)) {
                const date = new Date(`${normalizedValue}T00:00:00`);
                return Number.isNaN(date.getTime()) ? null : date;
            }

            const parts = normalizedValue.split(/[\/-]/);
            if (parts.length !== 3) {
                return null;
            }

            const [first, second, third] = parts;
            const [day, month, year] = first.length === 4
                ? [third, second, first]
                : [first, second, third];
            const date = new Date(`${year}-${month}-${day}T00:00:00`);
            return Number.isNaN(date.getTime()) ? null : date;
        };

        const formatRateValue = (value, decimals) => {
            if (value === null || value === undefined || value === '') {
                return '';
            }

            return new Intl.NumberFormat('es-CL', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            }).format(Number(value));
        };

        const parseTimeEntryNumber = (value) => {
            if (value === null || value === undefined || value === '') {
                return null;
            }

            const normalized = String(value).replace(/\s+/g, '').replace(',', '.');
            const parsed = Number(normalized);

            return Number.isFinite(parsed) ? parsed : null;
        };

        const timeEntryProjectRanges = (option) => {
            if (!option) {
                return [];
            }

            try {
                return JSON.parse(option.dataset.assignmentRanges || '[]');
            } catch (error) {
                return [];
            }
        };

        const timeEntryRangesForPerson = (option) => {
            const personId = String(timeEntryPersonSelect?.value || '');

            return timeEntryProjectRanges(option).filter((range) => String(range.person_id) === personId);
        };

        const timeEntryMatchingRanges = (option) => {
            const periodDate = parseChileanDate(timeEntryDateInput?.value || '');
            if (!periodDate) {
                return [];
            }

            return timeEntryRangesForPerson(option).filter((range) => {
                const start = range.start_date ? new Date(`${range.start_date}T00:00:00`) : null;
                const end = range.end_date ? new Date(`${range.end_date}T00:00:00`) : null;

                return (!start || start <= periodDate) && (!end || end >= periodDate);
            });
        };

        const formatTimeEntryDate = (value) => {
            const date = value ? new Date(`${value}T00:00:00`) : null;
            if (!date || Number.isNaN(date.getTime())) {
                return '';
            }

            return new Intl.DateTimeFormat('es-CL', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
            }).format(date);
        };

        const assignmentRangeDateLabel = (range) => {
            if (!range) {
                return 'Vigencia: No informada';
            }

            if (range.start_date && range.end_date) {
                return `Vigencia: ${formatTimeEntryDate(range.start_date)} al ${formatTimeEntryDate(range.end_date)}`;
            }

            if (range.start_date) {
                return `Vigencia: desde ${formatTimeEntryDate(range.start_date)}`;
            }

            if (range.end_date) {
                return `Vigencia: hasta ${formatTimeEntryDate(range.end_date)}`;
            }

            return 'Vigencia: No informada';
        };

        const selectedTimeEntryProjectOption = () => {
            if (!timeEntryProjectSelect) {
                return null;
            }

            return timeEntryProjectSelect.options[timeEntryProjectSelect.selectedIndex] || null;
        };

        const resolveTimeEntryRate = () => {
            const projectOption = selectedTimeEntryProjectOption();
            if (!projectOption) {
                return { amount: null, prefix: '—', decimals: 2, source: null, clientId: '', clientLabel: '', matchedRange: null, projectOption: null };
            }

            const matchedRanges = timeEntryMatchingRanges(projectOption);
            const matchedRange = matchedRanges.length === 1 ? matchedRanges[0] : null;

            const sourceType = matchedRange && Number(matchedRange.hourly_value) > 0 ? 'assignment' : 'project';
            const amount = sourceType === 'assignment'
                ? matchedRange.hourly_value
                : projectOption.dataset.projectRateAmount;
            const unitType = sourceType === 'assignment'
                ? (matchedRange.hourly_rate_unit_type || 'CURRENCY')
                : (projectOption.dataset.projectRateUnitType || 'CURRENCY');
            const currencyCode = sourceType === 'assignment'
                ? (matchedRange.currency_code || (unitType === 'UF' ? 'UF' : 'CLP'))
                : (projectOption.dataset.projectRateCurrencyCode || 'CLP');
            const currencySymbol = sourceType === 'assignment'
                ? (matchedRange.currency_symbol || (currencyCode === 'CLP' ? '$' : currencyCode))
                : (projectOption.dataset.projectRateCurrencySymbol || '$');
            const decimals = sourceType === 'assignment'
                ? (unitType === 'UF' ? 2 : (currencyCode === 'CLP' ? 0 : 2))
                : parseInt(projectOption.dataset.projectRateMinorUnits || '0', 10);
            const sourceLabel = sourceType === 'assignment'
                ? (matchedRange?.source_label || `Asignación · ${projectOption.dataset.projectName || projectOption.textContent.trim()}`)
                : `Proyecto · ${projectOption.dataset.projectName || projectOption.textContent.trim()}`;

            return {
                amount,
                prefix: unitType === 'UF' ? 'UF' : currencySymbol,
                decimals,
                source: amount ? sourceLabel : null,
                clientId: projectOption.dataset.clientId || '',
                clientLabel: projectOption.dataset.clientLabel || '',
                matchedRange,
                projectOption,
            };
        };

        const syncTimeEntryProjects = () => {
            if (!timeEntryProjectSelect) {
                return;
            }

            const placeholder = timeEntryProjectSelect.options[0];
            let visibleOptions = 0;

            Array.from(timeEntryProjectSelect.options).forEach((option, index) => {
                if (index === 0) {
                    option.hidden = false;
                    return;
                }

                const visible = timeEntryMatchingRanges(option).length > 0;
                const preserveSelected = option.selected && !visible;

                option.hidden = !visible && !preserveSelected;
                option.disabled = !visible && !preserveSelected;
                if (visible) {
                    visibleOptions++;
                }
            });

            if (!timeEntryPersonSelect?.value) {
                timeEntryProjectSelect.disabled = true;
                placeholder.textContent = 'Seleccione una persona primero';
            } else if (!timeEntryDateInput?.value) {
                timeEntryProjectSelect.disabled = true;
                placeholder.textContent = 'Seleccione una fecha primero';
            } else if (visibleOptions === 0) {
                timeEntryProjectSelect.disabled = true;
                placeholder.textContent = 'No existen proyectos asignados para esta persona en la fecha indicada.';
            } else {
                timeEntryProjectSelect.disabled = false;
                placeholder.textContent = 'Seleccione';
            }
        };

        const syncTimeEntryContext = () => {
            syncTimeEntryProjects();

            const resolution = resolveTimeEntryRate();
            const projectOption = resolution.projectOption;
            const matchedRanges = timeEntryMatchingRanges(projectOption);
            const personRanges = timeEntryRangesForPerson(projectOption);
            const workedHours = parseTimeEntryNumber(timeEntryWorkedInput?.value);
            const approvedHours = parseTimeEntryNumber(timeEntryApprovedInput?.value);
            const approvalCode = String(timeEntryApprovalStatusSelect?.selectedOptions?.[0]?.textContent || '').trim().toLowerCase();

            if (timeEntryRateRaw && timeEntryRateDisplay && timeEntryRatePrefix && timeEntryRateMessage) {
                if (resolution.amount === null || resolution.amount === '') {
                    timeEntryRateRaw.value = '';
                    timeEntryRateDisplay.value = '';
                    timeEntryRateDisplay.placeholder = 'No aplica / No configurada';
                    timeEntryRatePrefix.textContent = '—';
                    timeEntryRateMessage.textContent = 'No existe una tarifa HH aplicable para esta combinación de persona, proyecto y fecha.';
                    timeEntryRateMessage.className = 'small mt-1 text-warning';
                } else {
                    timeEntryRateRaw.value = resolution.amount;
                    timeEntryRateDisplay.value = formatRateValue(resolution.amount, resolution.decimals);
                    timeEntryRateDisplay.placeholder = '';
                    timeEntryRatePrefix.textContent = resolution.prefix || '—';
                    timeEntryRateMessage.textContent = resolution.source ? `Origen: ${resolution.source}` : 'Tarifa obtenida automáticamente.';
                    timeEntryRateMessage.className = 'small mt-1 text-muted';
                }
            }

            if (timeEntryClientHidden && timeEntryClientDisplay) {
                timeEntryClientHidden.value = resolution.clientId || '';
                timeEntryClientDisplay.value = resolution.clientLabel || '—';
            }

            if (timeEntryAssignmentLabel) {
                timeEntryAssignmentLabel.textContent = resolution.matchedRange
                    ? `Asignación: ${resolution.matchedRange.code || resolution.matchedRange.source_label || 'Asignación vigente'}`
                    : (projectOption?.value ? 'Asignación: Revise la vigencia de la asignación para la fecha indicada.' : 'Asignación: Seleccione una persona y una fecha.');
            }

            if (timeEntryAssignmentProject) {
                const projectName = resolution.matchedRange?.project_name
                    || projectOption?.dataset.projectName
                    || projectOption?.textContent?.trim()
                    || 'No informado';
                timeEntryAssignmentProject.textContent = `Proyecto: ${projectName}`;
            }

            if (timeEntryAssignmentVigency) {
                timeEntryAssignmentVigency.textContent = assignmentRangeDateLabel(resolution.matchedRange || personRanges[0] || null);
            }

            if (timeEntryAssignmentClient) {
                timeEntryAssignmentClient.textContent = `Cliente: ${resolution.clientLabel || 'Se completará automáticamente.'}`;
            }

            if (timeEntryAssignmentRate) {
                timeEntryAssignmentRate.textContent = `Tarifa: ${resolution.amount !== null && resolution.amount !== '' ? `${resolution.prefix || '—'} ${formatRateValue(resolution.amount, resolution.decimals)} / HH` : 'No aplica / No configurada'}`;
            }

            if (timeEntryAssignmentCostCenter) {
                const costCenterName = resolution.matchedRange?.cost_center_name || '';
                timeEntryAssignmentCostCenter.textContent = costCenterName ? `Centro de costo: ${costCenterName}` : '';
                timeEntryAssignmentCostCenter.classList.toggle('d-none', costCenterName === '');
            }

            if (timeEntryCostCenterSelect && !timeEntryCostCenterSelect.value && resolution.matchedRange?.cost_center_id) {
                timeEntryCostCenterSelect.value = String(resolution.matchedRange.cost_center_id);
            }

            let contextWarning = '';
            if (matchedRanges.length > 1) {
                contextWarning = 'Existe más de una asignación vigente para esta persona y proyecto en la fecha indicada. Revise la asignación correspondiente antes de registrar horas.';
            } else if (projectOption?.value && personRanges.length === 1 && matchedRanges.length === 0 && timeEntryDateInput?.value) {
                const firstRange = personRanges[0];
                const startLabel = firstRange.start_date ? formatTimeEntryDate(firstRange.start_date) : 'sin inicio informado';
                const endLabel = firstRange.end_date ? formatTimeEntryDate(firstRange.end_date) : 'sin término informado';
                contextWarning = `La fecha registrada está fuera de la vigencia de la asignación (${firstRange.start_date && firstRange.end_date ? `${startLabel} al ${endLabel}` : (firstRange.start_date ? `desde ${startLabel}` : (firstRange.end_date ? `hasta ${endLabel}` : 'sin vigencia informada'))}).`;
            }

            if (timeEntryContextWarningBox && timeEntryContextWarning) {
                timeEntryContextWarning.textContent = contextWarning;
                timeEntryContextWarning.classList.toggle('d-none', contextWarning === '');
                timeEntryContextWarningBox.classList.toggle('d-none', contextWarning === '');
            }

            if (timeEntryDateValidationBox && timeEntryDateValidation) {
                timeEntryDateValidation.textContent = contextWarning;
                timeEntryDateValidationBox.classList.toggle('d-none', contextWarning === '');
            }

            let approvedWarning = '';
            if (workedHours !== null && approvedHours !== null && approvedHours > workedHours) {
                approvedWarning = 'Las horas aprobadas no pueden superar las horas trabajadas.';
            } else if (approvalCode.includes('aprobado') && approvedHours !== null && approvedHours <= 0) {
                approvedWarning = 'Si la aprobación es Aprobado, ingrese horas aprobadas mayores que 0.';
            } else if (approvalCode.includes('rechazado') && approvedHours !== null && approvedHours > 0) {
                approvedWarning = 'Si la aprobación es Rechazado, las horas aprobadas deben ser 0.';
            } else if (String(timeEntryPaymentStatusSelect?.value || '') === 'paid' && !approvalCode.includes('aprobado')) {
                approvedWarning = 'Un registro solo puede marcarse como pagado cuando su aprobación ya está resuelta como Aprobado.';
            }

            if (timeEntryApprovedWarningBox && timeEntryApprovedWarning) {
                timeEntryApprovedWarning.textContent = approvedWarning;
                timeEntryApprovedWarning.classList.toggle('d-none', approvedWarning === '');
                timeEntryApprovedWarningBox.classList.toggle('d-none', approvedWarning === '');
            }
        };

        const timeEntryReactiveFieldIds = new Set([
            'person_id',
            'project_id',
            'entry_date',
            'hours_worked',
            'hours_approved',
            'approval_status_id',
            'payment_status',
        ]);

        const syncTimeEntryContextOnEvent = (event) => {
            if (!timeEntryReactiveFieldIds.has(event.target?.id || '')) {
                return;
            }

            syncTimeEntryContext();
        };

        form.addEventListener('input', syncTimeEntryContextOnEvent);
        form.addEventListener('change', syncTimeEntryContextOnEvent);

        timeEntryPersonSelect?.addEventListener('change', syncTimeEntryContext);
        timeEntryProjectSelect?.addEventListener('change', syncTimeEntryContext);
        timeEntryDateInput?.addEventListener('input', syncTimeEntryContext);
        timeEntryDateInput?.addEventListener('change', syncTimeEntryContext);
        timeEntryDateInput?.addEventListener('blur', syncTimeEntryContext);
        timeEntryWorkedInput?.addEventListener('input', syncTimeEntryContext);
        timeEntryApprovedInput?.addEventListener('input', syncTimeEntryContext);
        timeEntryApprovalStatusSelect?.addEventListener('change', syncTimeEntryContext);
        timeEntryPaymentStatusSelect?.addEventListener('change', syncTimeEntryContext);
        syncTimeEntryContext();

        const assignmentProjectSelect = form.querySelector('[data-assignments-project-select="true"]');
        const assignmentProjectSaleNet = form.querySelector('[data-assignments-project-sale-net]');
        const assignmentProjectVigency = form.querySelector('[data-assignments-project-vigency]');
        const assignmentTariffWarningBox = form.querySelector('[data-assignments-tariff-warning-box]');
        const assignmentVigencyWarningBox = form.querySelector('[data-assignments-vigency-warning-box]');
        const assignmentWarningDouble = form.querySelector('[data-assignments-warning-double]');
        const assignmentWarningSale = form.querySelector('[data-assignments-warning-sale]');
        const assignmentWarningVigency = form.querySelector('[data-assignments-warning-vigency]');
        const assignmentHourlyInput = form.querySelector('#hourly_value');
        const assignmentProjectInput = form.querySelector('#project_value');
        const assignmentStartInput = form.querySelector('#start_date');
        const assignmentEndInput = form.querySelector('#end_date');

        const parseAssignmentNumber = (value) => {
            if (value === null || value === undefined || value === '') {
                return null;
            }

            const normalized = String(value).replace(/\s+/g, '').replace(',', '.');
            const parsed = Number(normalized);
            return Number.isFinite(parsed) ? parsed : null;
        };

        const formatAssignmentMoney = (value, currencyCode = 'CLP', decimals = 0) => {
            if (value === null || value === undefined || value === '') {
                return '';
            }

            const code = String(currencyCode || 'CLP').toUpperCase();
            const symbol = {
                CLP: '$',
                UF: 'UF',
                USD: 'US$',
                EUR: '€',
            }[code] || code;

            return `${symbol} ${new Intl.NumberFormat('es-CL', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            }).format(Number(value))}`;
        };

        const formatAssignmentDate = (value) => {
            const date = value ? new Date(`${value}T00:00:00`) : null;
            if (!date || Number.isNaN(date.getTime())) {
                return '';
            }

            return new Intl.DateTimeFormat('es-CL', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
            }).format(date);
        };

        const projectVigencyText = (startDate, endDate, hasProject) => {
            if (!hasProject) {
                return 'Vigencia proyecto: Seleccione un proyecto.';
            }

            if (startDate && endDate) {
                return `Vigencia proyecto: ${formatAssignmentDate(startDate)} al ${formatAssignmentDate(endDate)}`;
            }

            if (startDate) {
                return `Vigencia proyecto desde ${formatAssignmentDate(startDate)}`;
            }

            if (endDate) {
                return `Vigencia proyecto hasta ${formatAssignmentDate(endDate)}`;
            }

            return 'Vigencia proyecto: No informada';
        };

        const projectSaleText = (saleNet, saleCurrencyCode, saleMinorUnits, hasProject) => {
            if (!hasProject) {
                return 'Venta neta proyecto: Seleccione un proyecto.';
            }

            if (saleNet === null) {
                return 'Venta neta proyecto: No informada';
            }

            return `Venta neta proyecto: ${formatAssignmentMoney(saleNet, saleCurrencyCode, Number.isNaN(saleMinorUnits) ? 0 : saleMinorUnits)}`;
        };

        const assignmentVigencyWarningText = (projectStart, projectEnd, assignmentStartDate, assignmentEndDate) => {
            const startsBeforeProject = projectStart && assignmentStartDate && assignmentStartDate < projectStart;
            const endsAfterProject = projectEnd && assignmentEndDate && assignmentEndDate > projectEnd;

            if (startsBeforeProject && endsAfterProject) {
                return 'La vigencia de la asignación inicia antes y termina después de la vigencia del proyecto seleccionado.';
            }

            if (startsBeforeProject) {
                return 'La vigencia de la asignación inicia antes de la vigencia del proyecto seleccionado.';
            }

            if (endsAfterProject) {
                return 'La vigencia de la asignación termina después de la vigencia del proyecto seleccionado.';
            }

            return '';
        };

        const syncAssignmentContext = () => {
            if (!assignmentProjectSelect || !assignmentProjectSaleNet) {
                return;
            }

            const option = assignmentProjectSelect.options[assignmentProjectSelect.selectedIndex];
            const hasProject = Boolean(option?.value);
            const saleNetRaw = option?.dataset?.projectSaleNet || '';
            const saleCurrencyCode = option?.dataset?.projectSaleCurrencyCode || 'CLP';
            const saleMinorUnits = Number.parseInt(option?.dataset?.projectSaleMinorUnits || '0', 10);
            const saleNet = parseAssignmentNumber(saleNetRaw);
            const projectStartDate = option?.dataset?.projectStartDate || '';
            const projectEndDate = option?.dataset?.projectEndDate || '';
            assignmentProjectSaleNet.textContent = projectSaleText(saleNet, saleCurrencyCode, saleMinorUnits, hasProject);

            if (assignmentProjectVigency) {
                assignmentProjectVigency.textContent = projectVigencyText(projectStartDate, projectEndDate, hasProject);
            }

            const hourlyValue = parseAssignmentNumber(assignmentHourlyInput?.value);
            const projectValue = parseAssignmentNumber(assignmentProjectInput?.value);
            const assignmentStartDate = parseChileanDate(assignmentStartInput?.value || '');
            const assignmentEndDate = parseChileanDate(assignmentEndInput?.value || '');
            const projectStart = projectStartDate ? new Date(`${projectStartDate}T00:00:00`) : null;
            const projectEnd = projectEndDate ? new Date(`${projectEndDate}T00:00:00`) : null;
            const hasBothValues = (hourlyValue ?? 0) > 0 && (projectValue ?? 0) > 0;
            const exceedsSaleNet = saleNet !== null && projectValue !== null && projectValue > saleNet;
            const vigencyWarning = assignmentVigencyWarningText(projectStart, projectEnd, assignmentStartDate, assignmentEndDate);

            if (assignmentWarningDouble) {
                assignmentWarningDouble.classList.toggle('d-none', !hasBothValues);
            }

            if (assignmentWarningSale) {
                assignmentWarningSale.classList.toggle('d-none', !exceedsSaleNet);
            }

            if (assignmentWarningVigency) {
                assignmentWarningVigency.textContent = vigencyWarning;
                assignmentWarningVigency.classList.toggle('d-none', vigencyWarning === '');
            }

            if (assignmentTariffWarningBox) {
                assignmentTariffWarningBox.classList.toggle('d-none', !hasBothValues && !exceedsSaleNet);
            }

            if (assignmentVigencyWarningBox) {
                assignmentVigencyWarningBox.classList.toggle('d-none', vigencyWarning === '');
            }
        };

        const assignmentReactiveFieldIds = new Set([
            'project_id',
            'hourly_value',
            'project_value',
            'start_date',
            'end_date',
        ]);

        const syncAssignmentContextOnEvent = (event) => {
            const targetId = event.target?.id || '';
            if (!assignmentReactiveFieldIds.has(targetId)) {
                return;
            }

            syncAssignmentContext();
        };

        form.addEventListener('input', syncAssignmentContextOnEvent);
        form.addEventListener('change', syncAssignmentContextOnEvent);

        assignmentProjectSelect?.addEventListener('change', syncAssignmentContext);
        assignmentHourlyInput?.addEventListener('input', syncAssignmentContext);
        assignmentHourlyInput?.addEventListener('change', syncAssignmentContext);
        assignmentProjectInput?.addEventListener('input', syncAssignmentContext);
        assignmentProjectInput?.addEventListener('change', syncAssignmentContext);
        assignmentStartInput?.addEventListener('input', syncAssignmentContext);
        assignmentStartInput?.addEventListener('change', syncAssignmentContext);
        assignmentStartInput?.addEventListener('blur', syncAssignmentContext);
        assignmentEndInput?.addEventListener('input', syncAssignmentContext);
        assignmentEndInput?.addEventListener('change', syncAssignmentContext);
        assignmentEndInput?.addEventListener('blur', syncAssignmentContext);
        syncAssignmentContext();
    })();
</script>
@endpush
