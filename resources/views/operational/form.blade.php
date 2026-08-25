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
    'person_id' => 'Persona a la que corresponde la remuneración. Su ficha aporta la referencia base de cálculo.',
    'period_date' => 'Mes al que corresponde esta remuneración. El sistema la normaliza al primer día del mes.',
    'payment_date' => 'Fecha prevista o real de pago del período. Solo afecta el control y cierre operativo.',
    'amount_basis' => 'Indica si la base del período se interpreta como bruto o líquido pactado.',
    'project_id' => 'Proyecto asociado a la asignación vigente del período, cuando exista.',
    'hours_approved' => 'Override horas aprobadas. Déjelo vacío para usar las horas aprobadas automáticas del período obtenidas desde Horas.',
    'monthly_value' => 'Valor mensual override. Déjelo vacío para usar el valor automático del período. Si ingresa 0,00, la base mensual queda en cero.',
    'hourly_value' => 'Valor hora override. Déjelo vacío para usar la tarifa automática del período. Si ingresa 0,00, la modalidad por hora queda desactivada.',
    'project_value' => 'Valor proyecto/hito override. Déjelo vacío para usar el valor automático del período. Si ingresa 0,00, el monto fijo queda en cero.',
    'bonuses' => 'Bonos imponibles del período. Si provienen de Novedades remuneración, el sistema los toma como referencia automática.',
    'non_taxable_allowances' => 'Asignaciones no imponibles del período. Si provienen de Novedades remuneración, el sistema las toma como referencia automática.',
    'base_salary' => 'Base calculada automáticamente según la modalidad y los datos del período.',
    'taxable_gross' => 'Base sobre la cual se calculan cotizaciones, sujeta a topes legales.',
    'pension_health_base' => 'Base previsional afecta a AFP y salud, considerando el tope legal vigente del período.',
    'afc_base' => 'Base afecta a Seguro de Cesantía, considerando el tope AFC aplicable al período.',
    'employee_retention' => 'Monto retenido por honorarios según la retención legal del período.',
    'afp_mandatory' => 'Cotización obligatoria del trabajador sobre la base previsional con tope.',
    'afp_commission' => 'Comisión vigente de la AFP de la persona según el período.',
    'health_employee' => 'Cotización legal de salud calculada sobre la base previsional.',
    'afc_employee' => 'Seguro de Cesantía de cargo del trabajador cuando corresponde según tipo de contrato.',
    'iusc_amount' => 'Impuesto Único de Segunda Categoría calculado según la tabla legal vigente.',
    'advances' => 'Anticipos del período. Déjelo vacío si no corresponde un anticipo.',
    'other_deductions' => 'Otros descuentos del período. Déjelo vacío si no corresponde otro descuento.',
    'net_pay' => 'Monto líquido a pagar después de descuentos legales y manuales.',
    'afc_employer' => 'Seguro de Cesantía de cargo del empleador según tipo de contrato.',
    'employer_pension' => 'Cotización adicional de cargo del empleador según la vigencia legal del período.',
    'accident_insurance' => 'Seguro de accidentes del trabajo: tasa básica más eventual tasa adicional de la empresa.',
    'sanna' => 'Cotización de cargo del empleador correspondiente al seguro SANNA.',
    'vacation_provision_amount' => 'Costo provisionado para análisis financiero. No corresponde a un descuento del trabajador ni a una salida real de caja.',
    'employer_cost' => 'Costo económico total de la remuneración, incluyendo aportes del empleador y provisiones.',
    'calculation_notes' => 'Observaciones o alertas del cálculo que el sistema detectó para este período.',
    'status' => 'Estado de pago o control operacional del documento.',
]);
@php($payrollAutoFields = ['code', 'base_salary', 'taxable_gross', 'employee_retention', 'afp_mandatory', 'afp_commission', 'health_employee', 'afc_employee', 'iusc_amount', 'net_pay', 'afc_employer', 'employer_pension', 'accident_insurance', 'sanna', 'vacation_provision_amount', 'employer_cost', 'calculation_status', 'calculation_notes'])
@php($payrollManualFields = ['person_id', 'project_id', 'period_date', 'payment_date', 'amount_basis', 'monthly_value', 'hourly_value', 'project_value', 'bonuses', 'non_taxable_allowances', 'advances', 'other_deductions'])
@php($payrollSections = [
    'Datos base' => ['code', 'person_id', 'project_id', 'period_date', 'amount_basis', 'payment_date'],
    'Referencia de la remuneración' => [],
    'Ajustes / overrides' => ['monthly_value', 'hourly_value', 'project_value'],
    'Adicionales' => ['bonuses', 'non_taxable_allowances'],
    'Descuentos' => ['advances', 'other_deductions'],
    'Resultado' => ['base_salary', 'taxable_gross', 'pension_health_base', 'afc_base', 'employee_retention', 'afp_mandatory', 'afp_commission', 'health_employee', 'afc_employee', 'iusc_amount', 'net_pay', 'afc_employer', 'employer_pension', 'accident_insurance', 'sanna', 'vacation_provision_amount', 'employer_cost'],
    'Control del cálculo' => ['calculation_status', 'calculation_notes', 'status'],
])
@php($payrollSummary = [
    ['label' => 'Datos base', 'field' => 'code', 'text' => 'Persona, proyecto y período que definen el cálculo de la remuneración.'],
    ['label' => 'Referencia de la remuneración', 'field' => 'hours_approved', 'text' => 'Valores automáticos provenientes de Horas, Asignaciones, Personal y novedades del período.'],
    ['label' => 'Ajustes / overrides', 'field' => 'monthly_value', 'text' => 'Valores manuales que reemplazan la referencia automática solo para esta remuneración.'],
    ['label' => 'Adicionales', 'field' => 'bonuses', 'text' => 'Bonos y asignaciones que se agregan al período según novedades o ingreso manual.'],
    ['label' => 'Descuentos', 'field' => 'advances', 'text' => 'Anticipos y otros descuentos aplicados al período.'],
    ['label' => 'Resultado', 'field' => 'net_pay', 'text' => 'Totales y aportes calculados para el período.'],
    ['label' => 'Control del cálculo', 'field' => 'calculation_status', 'text' => 'Estado y observaciones para revisar el cierre del período.'],
])
@php($projectHelp = [
    'sales_currency_id' => 'Moneda utilizada para cotizar y registrar las ventas del proyecto.',
    'sale_net' => 'Venta antes de IVA, expresada en la moneda definida para el proyecto.',
    'sale_total' => 'Total con IVA en la misma moneda comercial del proyecto.',
])
@php($rateUnitHelp = [
    'hourly_rate_unit_type' => 'Unidad en que se pactó la tarifa por hora. UF usa la unidad de cuenta del período; las monedas usan su conversión configurada.',
    'hourly_value' => 'Valor por hora utilizado para costear la participación de una persona. La unidad se muestra al lado del monto.',
    'project_value' => 'Monto utilizado para remunerar a la persona cuando la modalidad contractual corresponde a proyecto o hito.',
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
        'hourly_value' => 'Valor HH base de costeo utilizado como referencia cuando una asignación no informa un valor específico.',
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
            'Define cómo se costea y remunera la participación de esta persona durante la vigencia indicada en el proyecto.',
            'Valor HH de costeo del proyecto es específico de la asignación cuando se informa. Si se deja vacío y la Persona tiene un Valor HH base de costeo, el sistema usa esa referencia.',
            'Usa Monto pactado de remuneración por proyecto/hito cuando existe un monto fijo para pagar la participación o un hito acordado.',
            'Horas mensuales representan el compromiso mensual de costeo y planificación de esta asignación.',
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
        'hourly_rate_unit_type' => 'Unidad monetaria usada para expresar el Valor HH de costeo de esta asignación.',
        'hourly_value' => 'Valor utilizado para estimar el costo comprometido de esta persona en el proyecto. Puede diferir de su remuneración y puede variar entre asignaciones.',
        'project_value' => 'Monto utilizado para remunerar a la persona cuando su modalidad contractual corresponde a pago por proyecto o hito. No se utiliza para calcular el compromiso de HH del proyecto.',
        'monthly_hours' => 'Horas mensuales comprometidas de esta persona para efectos de costeo y planificación del proyecto.',
    ],
    'time-entries' => [
        'person_id' => 'Persona a la que corresponde este registro de horas.',
        'project_id' => 'Proyecto asociado a una asignación válida para la persona y la fecha seleccionadas.',
        'client_id' => 'Cliente derivado automáticamente desde el proyecto seleccionado.',
        'entry_date' => 'Fecha en que se trabajaron las horas. Debe quedar dentro de la vigencia de la asignación aplicable.',
        'activity_id' => 'Actividad registrada para identificar el trabajo realizado.',
        'hours_worked' => 'Horas efectivamente registradas para esta persona, proyecto y fecha. Cada registro diario debe ser mayor que 0 y no puede superar 24 horas.',
        'hours_approved' => 'Horas finalmente aprobadas para control, cálculo y procesos posteriores. No pueden superar las horas trabajadas.',
        'hourly_value' => 'Valor HH de costeo del proyecto obtenido automáticamente desde la asignación vigente o, si falta, desde el valor base de la persona.',
        'cost_center_id' => 'Centro de costo asociado al registro. Si la asignación ya lo define, se propone automáticamente como referencia.',
        'approval_status_id' => 'Estado actual de revisión del registro de horas.',
        'payment_status' => 'Estado manual de pago del registro. Solo corresponde marcarlo como pagado cuando la aprobación ya está resuelta.',
    ],
])
@php($resourceLayoutOverrides = [
    'payroll-records' => [
        'code' => 'col-12 col-md-6 col-xl-4',
        'person_id' => 'col-12 col-md-6 col-xl-4',
        'project_id' => 'col-12 col-md-6 col-xl-4',
        'period_date' => 'col-12 col-md-6 col-xl-4',
        'payment_date' => 'col-12 col-md-6 col-xl-4',
        'amount_basis' => 'col-12 col-md-6 col-xl-4',
        'hours_approved' => 'col-12 col-md-6 col-xl-3',
        'monthly_value' => 'col-12 col-md-6 col-xl-3',
        'hourly_value' => 'col-12 col-md-6 col-xl-3',
        'project_value' => 'col-12 col-md-6 col-xl-3',
        'bonuses' => 'col-12 col-md-6 col-xl-3',
        'non_taxable_allowances' => 'col-12 col-md-6 col-xl-3',
        'advances' => 'col-12 col-md-6 col-xl-3',
        'other_deductions' => 'col-12 col-md-6 col-xl-3',
    ],
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
@php($timeEntryRatePreview = $resource === 'time-entries' ? app(\App\Services\HourlyRateService::class)->resolveCostingForEntry($item) : null)
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
@php($assignmentSelectedPersonId = $resource === 'assignments' ? old('person_id', $item->person_id ?? null) : null)
@php($assignmentSelectedPerson = $assignmentSelectedPersonId !== null ? ($options['person_id'][$assignmentSelectedPersonId] ?? null) : null)
@php($assignmentProjectSaleNet = data_get($assignmentSelectedProject, 'project_sale_net'))
@php($assignmentProjectSaleCurrencyCode = data_get($assignmentSelectedProject, 'project_sale_currency_code', 'CLP'))
@php($assignmentProjectSaleCurrencySymbol = data_get($assignmentSelectedProject, 'project_sale_currency_symbol', '$'))
@php($assignmentProjectSaleMinorUnits = (int) data_get($assignmentSelectedProject, 'project_sale_minor_units', 0))
@php($assignmentProjectRateAmount = data_get($assignmentSelectedProject, 'project_rate_amount'))
@php($assignmentProjectRateCurrencyCode = data_get($assignmentSelectedProject, 'project_rate_currency_code', 'CLP'))
@php($assignmentProjectRateCurrencySymbol = data_get($assignmentSelectedProject, 'project_rate_currency_symbol', '$'))
@php($assignmentProjectRateMinorUnits = (int) data_get($assignmentSelectedProject, 'project_rate_minor_units', 0))
@php($assignmentProjectStartDate = data_get($assignmentSelectedProject, 'project_start_date'))
@php($assignmentProjectEndDate = data_get($assignmentSelectedProject, 'project_end_date'))
@php($assignmentProjectSaleDisplay = match (true) {
    $assignmentSelectedProject === null => 'Venta neta proyecto: Seleccione un proyecto.',
    $assignmentProjectSaleNet !== null => 'Venta neta proyecto: '.\App\Support\UiFormatter::formatMoney($assignmentProjectSaleNet, $assignmentProjectSaleCurrencyCode),
    default => 'Venta neta proyecto: No informada',
})
@php($assignmentHourlyValue = old('hourly_value', $item->hourly_value ?? null))
@php($assignmentProjectValue = old('project_value', $item->project_value ?? null))
@php($assignmentSpecificHourlyValueActive = is_numeric($assignmentHourlyValue) && (float) $assignmentHourlyValue > 0)
@php($assignmentSpecificRateCurrency = $selectedRateUnitType === 'UF'
    ? 'UF'
    : ($selectedRateCurrency
        ? [
            'code' => (string) ($selectedRateCurrency['currency_code'] ?? 'CLP'),
            'symbol' => (string) ($selectedRateCurrency['currency_symbol'] ?? '$'),
            'minor_units' => (int) ($selectedRateCurrency['minor_units'] ?? 0),
        ]
        : 'CLP'))
@php($assignmentProjectRateReferenceDisplay = is_numeric($assignmentProjectRateAmount) && (float) $assignmentProjectRateAmount > 0
    ? 'Valor HH contractual referencia: '.\App\Support\UiFormatter::formatMoney($assignmentProjectRateAmount, $assignmentProjectRateCurrencyCode).' / HH'
    : null)
@php($assignmentPersonRateAmount = data_get($assignmentSelectedPerson, 'person_rate_amount'))
@php($assignmentPersonRateUnitType = strtoupper((string) data_get($assignmentSelectedPerson, 'person_rate_unit_type', 'CURRENCY')))
@php($assignmentPersonRateCurrencyCode = data_get($assignmentSelectedPerson, 'person_rate_currency_code', 'CLP'))
@php($assignmentPersonRateMinorUnits = (int) data_get($assignmentSelectedPerson, 'person_rate_minor_units', 0))
@php($assignmentPersonRateReferenceDisplay = is_numeric($assignmentPersonRateAmount) && (float) $assignmentPersonRateAmount > 0
    ? 'Valor HH base Persona: '.\App\Support\UiFormatter::formatMoney($assignmentPersonRateAmount, $assignmentPersonRateUnitType === 'UF' ? 'UF' : $assignmentPersonRateCurrencyCode).' / HH'
    : null)
@php($assignmentEffectiveHourlyDisplay = match (true) {
    $assignmentSpecificHourlyValueActive => 'Efectivo: '.\App\Support\UiFormatter::formatMoney($assignmentHourlyValue, $assignmentSpecificRateCurrency).' / HH · Asignación',
    is_numeric($assignmentPersonRateAmount) && (float) $assignmentPersonRateAmount > 0 => 'Efectivo: '.\App\Support\UiFormatter::formatMoney($assignmentPersonRateAmount, $assignmentPersonRateUnitType === 'UF' ? 'UF' : $assignmentPersonRateCurrencyCode).' / HH · Persona',
    default => 'Efectivo: No configurado',
})
@php($assignmentEffectiveProjectValueDisplay = is_numeric($assignmentProjectValue)
    ? 'Efectivo: '.\App\Support\UiFormatter::formatMoney($assignmentProjectValue, $assignmentSpecificRateCurrency).' · Asignación'
    : 'Efectivo: No informado')
@php($assignmentHasBothValues = is_numeric($assignmentHourlyValue) && (float) $assignmentHourlyValue > 0 && is_numeric($assignmentProjectValue) && (float) $assignmentProjectValue > 0)
@php($assignmentProjectExceedsSale = $assignmentProjectSaleNet !== null && is_numeric($assignmentProjectValue) && (float) $assignmentProjectValue > (float) $assignmentProjectSaleNet)
@php($assignmentStartDate = old('start_date', optional($item->start_date)->format('d/m/Y')))
@php($assignmentEndDate = old('end_date', optional($item->end_date)->format('d/m/Y')))
@php($assignmentCommitmentPreview = $assignmentCommitmentPreview ?? null)
@php($assignmentCommitmentSaleCurrencyCode = is_array($assignmentCommitmentPreview) ? ($assignmentCommitmentPreview['sale_net_currency_code'] ?? 'CLP') : 'CLP')
@php($assignmentCommitmentSaleContractualDisplay = match (true) {
    !is_array($assignmentCommitmentPreview) => 'Venta contractual: Seleccione una persona y un proyecto.',
    ($assignmentCommitmentPreview['sale_net_contractual'] ?? null) !== null => 'Venta contractual: '.\App\Support\UiFormatter::formatMoney($assignmentCommitmentPreview['sale_net_contractual'], $assignmentCommitmentSaleCurrencyCode),
    default => 'Venta contractual: No disponible',
})
@php($assignmentCommitmentSaleEquivalentDisplay = match (true) {
    !is_array($assignmentCommitmentPreview) => null,
    $assignmentCommitmentSaleCurrencyCode !== 'CLP' && ($assignmentCommitmentPreview['sale_net_clp'] ?? null) !== null => 'Equivalente para proyección: '.\App\Support\UiFormatter::formatMoney($assignmentCommitmentPreview['sale_net_clp']),
    default => null,
})
@php($assignmentCommitmentCurrentDisplay = match (true) {
    !is_array($assignmentCommitmentPreview) => 'Personal comprometido actualmente: —',
    ($assignmentCommitmentPreview['current_personnel_committed_cost'] ?? null) !== null => 'Personal comprometido actualmente: '.\App\Support\UiFormatter::formatMoney($assignmentCommitmentPreview['current_personnel_committed_cost']),
    default => 'Personal comprometido actualmente: No disponible',
})
@php($assignmentCommitmentEstimateDisplay = match (true) {
    !is_array($assignmentCommitmentPreview) => 'Costo estimado de esta asignación: —',
    ($assignmentCommitmentPreview['assignment_estimated_cost'] ?? null) !== null => 'Costo estimado de esta asignación: '.\App\Support\UiFormatter::formatMoney($assignmentCommitmentPreview['assignment_estimated_cost']),
    default => 'Costo estimado de esta asignación: No disponible',
})
@php($assignmentCommitmentAfterDisplay = match (true) {
    !is_array($assignmentCommitmentPreview) => 'Compromiso después de guardar: —',
    ($assignmentCommitmentPreview['after_save_personnel_committed_cost'] ?? null) !== null => 'Compromiso después de guardar: '.\App\Support\UiFormatter::formatMoney($assignmentCommitmentPreview['after_save_personnel_committed_cost']),
    default => 'Compromiso después de guardar: No disponible',
})
@php($assignmentCommitmentMarginDisplay = match (true) {
    !is_array($assignmentCommitmentPreview) => 'Margen proyectado después de guardar: —',
    ($assignmentCommitmentPreview['projected_personnel_margin'] ?? null) !== null => 'Margen proyectado después de guardar: '.\App\Support\UiFormatter::formatMoney($assignmentCommitmentPreview['projected_personnel_margin']),
    default => 'Margen proyectado después de guardar: No disponible',
})
@php($assignmentCommitmentPercentageDisplay = match (true) {
    !is_array($assignmentCommitmentPreview) => 'Comprometido: —',
    ($assignmentCommitmentPreview['committed_percentage'] ?? null) !== null => 'Comprometido: '.\App\Support\UiFormatter::formatPercent(($assignmentCommitmentPreview['committed_percentage'] ?? 0) / 100, 1),
    default => 'Comprometido: No disponible',
})
@php($assignmentCommitmentExchangeRateInfo = is_array($assignmentCommitmentPreview) ? ($assignmentCommitmentPreview['exchange_rate_info'] ?? null) : null)
@php($assignmentCommitmentUsesProjectedExchangeRate = is_array($assignmentCommitmentPreview) ? (bool) ($assignmentCommitmentPreview['uses_projected_exchange_rate'] ?? false) : false)
@php($assignmentCommitmentExchangeRateNote = is_array($assignmentCommitmentPreview) ? ($assignmentCommitmentPreview['exchange_rate_note'] ?? null) : null)
@php($assignmentCommitmentExchangeRateNote = filled($assignmentCommitmentExchangeRateNote)
    ? $assignmentCommitmentExchangeRateNote
    : ($assignmentCommitmentUsesProjectedExchangeRate && is_array($assignmentCommitmentExchangeRateInfo) && ! empty($assignmentCommitmentExchangeRateInfo['value']) && ! empty($assignmentCommitmentExchangeRateInfo['value_date'])
        ? 'Proyección calculada con UF de referencia de '.\App\Support\UiFormatter::formatMoney($assignmentCommitmentExchangeRateInfo['value'], 'CLP').' correspondiente al '.\Illuminate\Support\Carbon::parse($assignmentCommitmentExchangeRateInfo['value_date'])->format('d/m/Y').', última UF oficial disponible.'
        : null))
@php($assignmentCommitmentNegativeWarning = is_array($assignmentCommitmentPreview) && !empty($assignmentCommitmentPreview['negative_margin']) && ($assignmentCommitmentPreview['negative_margin_amount'] ?? null) !== null
    ? 'El costo de personal comprometido superaría la venta neta del proyecto en '.\App\Support\UiFormatter::formatMoney($assignmentCommitmentPreview['negative_margin_amount']).'. El proyecto quedaría con margen proyectado de personal negativo.'
    : null)
@php($assignmentCommitmentDisplayWarnings = collect($assignmentCommitmentPreview['warnings'] ?? [])
    ->filter(fn ($warning) => $warning !== 'El costo de personal comprometido supera la venta neta del proyecto.')
    ->values()
    ->all())
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
<?php
    $formTitle = match ($resource) {
        'assignments' => $editing ? 'Editar asignación' : 'Nueva asignación',
        default => $editing ? ($config['edit_title'] ?? ('Editar '.$config['title'])) : ($config['create_title'] ?? ('Nuevo '.$config['title'])),
    };

    $formSubtitle = match ($resource) {
        'time-entries' => 'La asignación vigente, el cliente y la tarifa aplicable se validan y actualizan automáticamente antes de guardar.',
        default => 'Los cálculos financieros asociados se actualizan al guardar.',
    };
?>
@php($timeEntrySelectedPersonId = $resource === 'time-entries' ? old('person_id', $item->person_id ?? null) : null)
@php($timeEntrySelectedProjectId = $resource === 'time-entries' ? old('project_id', $item->project_id ?? null) : null)
@php($timeEntrySelectedProject = $resource === 'time-entries' && $timeEntrySelectedProjectId !== null ? ($options['project_id'][$timeEntrySelectedProjectId] ?? null) : null)
@php($timeEntrySelectedProjectRanges = collect(data_get($timeEntrySelectedProject, 'assignment_ranges', [])))
@php($timeEntryEntryMode = $resource === 'time-entries' && ! $editing ? old('entry_mode', 'daily') : 'daily')
@php($timeEntryEntryDate = $resource === 'time-entries' ? old('entry_date', optional($item->entry_date)->format('d/m/Y')) : null)
@php($timeEntryEntryDateParsed = $resource === 'time-entries' ? \App\Support\UiFormatter::parseDateInput($timeEntryEntryDate) : null)
@php($timeEntryPeriodStartDate = $resource === 'time-entries' ? old('period_start_date') : null)
@php($timeEntryPeriodEndDate = $resource === 'time-entries' ? old('period_end_date') : null)
@php($timeEntryPeriodDistributionMode = $resource === 'time-entries' ? old('period_distribution_mode', 'equal') : 'equal')
@php($timeEntryPeriodHoursPerDay = $resource === 'time-entries' ? old('period_hours_per_day') : null)
@php($timeEntryPeriodTotalHours = $resource === 'time-entries' ? old('period_total_hours') : null)
@php($timeEntryPeriodRowsPayload = $resource === 'time-entries' ? old('period_rows_payload', '') : '')
@php($timeEntryPeriodAuthorizationFields = ['approval_status_id', 'payment_status'])
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
@php($normalizeEditableNumericValue = function (mixed $input): ?string {
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
})
@php($payrollEditableValue = function (string $field, array $definition, mixed $value) use ($item, $normalizeEditableNumericValue) {
    if ($value === null || $value === '') {
        return '';
    }

    $type = $definition['type'] ?? 'text';
    $presentation = $definition['presentation'] ?? null;

    if ($item instanceof \App\Models\PayrollRecord && in_array($field, ['status', 'calculation_status'], true)) {
        return \App\Support\UiFormatter::display($item, $field, $definition);
    }

    return match (true) {
        $type === 'money' => $normalizeEditableNumericValue($value) ?? '',
        $presentation === 'rut' => \App\Support\ChileanRut::format((string) $value) ?? '',
        $presentation === 'percent' => \App\Support\UiFormatter::formatPercent($value),
        $presentation === 'hours' => \App\Support\UiFormatter::formatHours($value),
        $type === 'date' => \App\Support\UiFormatter::formatDate($value),
        in_array($type, ['decimal', 'number'], true) => \App\Support\UiFormatter::formatNumber($value),
        default => (string) $value,
    };
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
    is_array($selectedPayrollPerson) && filled($selectedPayrollPerson['payroll_hourly_value'] ?? null) ? 'Valor HH base de Persona: '.\App\Support\UiFormatter::formatMoney($selectedPayrollPerson['payroll_hourly_value'], $selectedPayrollPerson['payroll_hourly_currency'] ?? 'CLP').' / HH' : null,
])->filter()->implode(' · '))
@php($payrollPeriodLabel = $item->period_date ? ucfirst(\Illuminate\Support\Carbon::parse($item->period_date)->locale('es')->isoFormat('MMMM YYYY')) : 'Pendiente de selección')
@php($payrollCalculationSections = collect($payrollCalculationBreakdown['sections'] ?? []))
@php($payrollSourceSection = $payrollCalculationSections->firstWhere('title', 'Fuentes aplicadas'))
@php($payrollSourceRows = collect(data_get($payrollSourceSection, 'rows', []))->keyBy('label'))
@php($payrollTariffRowValue = data_get($payrollSourceRows->get('Tarifa pactada'), 'value'))
@php($payrollTariffParts = $payrollTariffRowValue ? preg_split('/\s+·\s+/u', (string) $payrollTariffRowValue, 2) : [])
@php($payrollTariffAutoValue = $payrollTariffParts[0] ?? null)
@php($payrollTariffAutoOrigin = $payrollTariffParts[1] ?? null)
@php($payrollProjectValueRowValue = data_get($payrollSourceRows->get('Valor proyecto/hito pactado'), 'value'))
@php($payrollProjectValueParts = $payrollProjectValueRowValue ? preg_split('/\s+·\s+/u', (string) $payrollProjectValueRowValue, 2) : [])
@php($payrollProjectValueAutoValue = $payrollProjectValueParts[0] ?? null)
@php($payrollProjectValueAutoOrigin = $payrollProjectValueParts[1] ?? null)
@php($payrollFormState = $payrollFormState ?? [])
@php($payrollFieldStates = $payrollFormState['fields'] ?? [])
@php($payrollHoursState = $payrollFieldStates['hours_approved'] ?? ['automatic' => null, 'override' => null, 'effective' => null, 'has_override' => false])
@php($payrollMonthlyValueState = $payrollFieldStates['monthly_value'] ?? ['automatic' => null, 'override' => null, 'effective' => null, 'has_override' => false])
@php($payrollHourlyValueState = $payrollFieldStates['hourly_value'] ?? ['automatic' => null, 'override' => null, 'effective' => null, 'has_override' => false])
@php($payrollProjectValueState = $payrollFieldStates['project_value'] ?? ['automatic' => null, 'override' => null, 'effective' => null, 'has_override' => false])
@php($payrollOverrideInputs = [
    'monthly_value' => $payrollMonthlyValueState['override'] ?? null,
    'hourly_value' => $payrollHourlyValueState['override'] ?? null,
    'project_value' => $payrollProjectValueState['override'] ?? null,
])
@php($payrollHoursApprovedDisplay = \App\Support\UiFormatter::formatHours($payrollHoursState['effective'] ?? 0))
@php($payrollHourlyValueConvertedDisplay = ($payrollHourlyValueState['effective'] ?? null) !== null ? \App\Support\UiFormatter::formatMoney($payrollHourlyValueState['effective'], 'CLP').' / HH' : 'No configurada')
@php($payrollHourlyValueOverrideDisplay = ($payrollHourlyValueState['override'] ?? null) !== null ? \App\Support\UiFormatter::formatMoney($payrollHourlyValueState['override'], 'CLP').' / HH' : 'No informado')
@php($payrollProjectValueConvertedDisplay = ($payrollProjectValueState['effective'] ?? null) !== null ? \App\Support\UiFormatter::formatMoney($payrollProjectValueState['effective'], 'CLP') : 'No configurado')
@php($payrollProjectValueOverrideDisplay = ($payrollProjectValueState['override'] ?? null) !== null ? \App\Support\UiFormatter::formatMoney($payrollProjectValueState['override'], 'CLP') : 'No informado')
@php($payrollModeReference = mb_strtolower((string) (is_array($selectedPayrollPerson) ? ($selectedPayrollPerson['payroll_mode_label'] ?? $selectedPayrollPerson['payroll_modality'] ?? '') : ($item->person?->modality ?? ''))))
@php($payrollIsHourlyMode = str_contains($payrollModeReference, 'hora'))
@php($payrollIsProjectMode = str_contains($payrollModeReference, 'proyecto'))
@php($payrollCalculationReview = ($item->calculation_status ?? null) && $item->calculation_status !== 'OK')
@php($payrollCalculationStatusLabel = match (strtoupper((string) ($item->calculation_status ?? 'OK'))) { 'REQUIERE_REVISION' => 'Requiere revisión', default => ($item->calculation_status ?? 'OK') })
@php($payrollOutputsAllZero = collect([(float) ($item->base_salary ?? 0), (float) ($item->taxable_gross ?? 0), (float) ($item->employee_retention ?? 0), (float) ($item->net_pay ?? 0), (float) ($item->employer_cost ?? 0)])->every(fn (float $value): bool => abs($value) < 0.00001))
@php($payrollNotCalculable = $isPayroll && $editing && $payrollCalculationReview && $payrollOutputsAllZero)
@php($payrollMoneyOrUnavailable = fn ($value) => $payrollNotCalculable ? 'No calculable' : \App\Support\UiFormatter::formatMoney($value))
@php($payrollMonthlyAutoValue = data_get($payrollSourceRows->get('Base mensual automática'), 'value'))
@php($payrollHealthAutoValue = data_get($payrollSourceRows->get('Salud adicional automática'), 'value'))
@php($payrollBonusesAutoValue = data_get($payrollSourceRows->get('Bonos automáticos'), 'value'))
@php($payrollAllowancesAutoValue = data_get($payrollSourceRows->get('Asignaciones no imponibles automáticas'), 'value'))
@php($payrollAdvancesAutoValue = data_get($payrollSourceRows->get('Anticipos automáticos'), 'value'))
@php($payrollOtherDeductionsAutoValue = data_get($payrollSourceRows->get('Otros descuentos automáticos'), 'value'))
@php($payrollSelectedProjectId = old('project_id', $item->project_id ?? null))
@php($payrollSelectedProjectOption = $payrollSelectedProjectId !== null ? ($options['project_id'][$payrollSelectedProjectId] ?? null) : null)
@php($payrollAutomaticSourceLabels = [
    'monthly_value' => 'Base mensual automática',
    'hourly_value' => 'Tarifa pactada',
    'project_value' => 'Valor proyecto/hito pactado',
    'bonuses' => 'Bonos automáticos',
    'non_taxable_allowances' => 'Asignaciones no imponibles automáticas',
    'advances' => 'Anticipos automáticos',
    'other_deductions' => 'Otros descuentos automáticos',
])
@if ($isPayroll)
    @if (($item->calculation_status ?? null) && $item->calculation_status !== 'OK' && ! filled($item->calculation_notes))
        @php($payrollWarnings[] = $payrollCalculationStatusLabel)
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
                <li>Seleccione primero Persona, Proyecto y Período. El sistema completa la referencia automática del período.</li>
                <li>Los campos marcados como override reemplazan un valor calculado solo para esta remuneración.</li>
                <li>La referencia automática proviene de Horas, Asignaciones, Personal y Novedades remuneración cuando existe información válida.</li>
                <li>Los bloques de Resultado y Control muestran lo que el sistema calculó para el período.</li>
                <li>Revise la referencia, el costo HH y el resultado antes de guardar o recalcular.</li>
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
             data-empty-meta="La modalidad, el contrato, AFP, salud y valores base se obtienen desde la ficha de Personal."
             data-empty-extra="Luego revise el período y complete solo conceptos extraordinarios si corresponde.">
            <div class="payroll-person-title">{{ $selectedPayrollTitle ?: 'Seleccione una persona' }}</div>
            <div class="payroll-person-meta">{{ $selectedPayrollSegments ?: 'La modalidad, contrato, AFP, salud y valores base se obtienen desde la ficha de Personal.' }}</div>
            <div class="payroll-person-extra">{{ $selectedPayrollExtras ?: 'Luego revise el período y complete solo conceptos extraordinarios si corresponde.' }}</div>
        </div>
        <div class="small text-muted mt-2">
            Los campos marcados como override reemplazan la referencia automática del período. Déjelos vacíos solo cuando corresponda usar el valor automático.
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

@if ($isPayroll)
    <div class="app-panel p-3 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <div class="section-title mb-1">Referencia de la remuneración</div>
                <div class="small text-muted">Información automática del período y del origen de cálculo.</div>
            </div>
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

        <div class="row g-3 payroll-base-grid">
            <div class="col-12 col-md-6 col-xl-4">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">Persona</div>
                    <div class="fw-semibold">{{ $selectedPayrollTitle ?: 'Seleccione una persona' }}</div>
                    <div class="small text-muted">{{ $selectedPayrollSegments ?: 'La modalidad se completará desde la ficha de Personal.' }}</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">Proyecto</div>
                    <div class="fw-semibold">{{ $item->project?->name ?: 'Pendiente de selección' }}</div>
                    <div class="small text-muted">Cliente: {{ $item->project?->client?->legal_name ?: 'No informado' }}</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">Período</div>
                    <div class="fw-semibold">{{ $payrollPeriodLabel }}</div>
                    <div class="small text-muted">Mes al que corresponde esta remuneración.</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">Tipo contractual</div>
                    <div class="fw-semibold">{{ is_array($selectedPayrollPerson) ? ($selectedPayrollPerson['payroll_contract_label'] ?? 'No informado') : 'No informado' }}</div>
                    <div class="small text-muted">Modalidad: {{ is_array($selectedPayrollPerson) ? ($selectedPayrollPerson['payroll_mode_label'] ?? 'No informada') : 'No informada' }}</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">Asignación</div>
                    <div class="fw-semibold">{{ data_get($payrollSourceRows->get('Asignación'), 'value') ?: 'No configurada' }}</div>
                    <div class="small text-muted">{{ data_get($payrollSourceRows->get('Vigencia asignación'), 'value') ?: 'Vigencia no disponible' }}</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">Tarifa pactada</div>
                    @if ($payrollIsHourlyMode)
                        <div class="fw-semibold">{{ $payrollTariffAutoValue ?: 'No configurada' }}</div>
                        <div class="small text-muted">Origen: {{ $payrollTariffAutoOrigin ?: 'No configurado' }}</div>
                        <div class="small text-muted mt-1">Valor convertido: {{ $payrollHourlyValueConvertedDisplay }}</div>
                        <div class="small text-muted">Override manual: {{ $payrollHourlyValueOverrideDisplay }}</div>
                        <div class="small text-muted">Valor efectivo: {{ $payrollHourlyValueConvertedDisplay }}</div>
                    @else
                        <div class="fw-semibold">No aplica</div>
                        <div class="small text-muted">
                            {{ $payrollIsProjectMode ? 'La modalidad por proyecto/hito usa el valor proyecto/hito pactado.' : 'La modalidad actual no utiliza tarifa por hora para calcular esta remuneración.' }}
                        </div>
                    @endif
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">Valor proyecto / hito pactado</div>
                    <div class="fw-semibold">{{ $payrollProjectValueAutoValue ?: 'No configurado' }}</div>
                    <div class="small text-muted">Origen: {{ $payrollProjectValueAutoOrigin ?: 'No configurado' }}</div>
                    <div class="small text-muted mt-1">Valor convertido: {{ $payrollProjectValueConvertedDisplay }}</div>
                    <div class="small text-muted">Override manual: {{ $payrollProjectValueOverrideDisplay }}</div>
                    <div class="small text-muted">Valor efectivo: {{ $payrollProjectValueConvertedDisplay }}</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">Horas aprobadas del período</div>
                    <div class="fw-semibold">{{ $payrollHoursApprovedDisplay }}</div>
                    <div class="small text-muted">Origen: módulo Horas.</div>
                    @if ($payrollHoursState['has_override'] ?? false)
                        <div class="small text-muted mt-1">Override horas: {{ \App\Support\UiFormatter::formatHours($payrollHoursState['override']) }}</div>
                        <div class="small text-muted">Horas efectivas: {{ \App\Support\UiFormatter::formatHours($payrollHoursState['effective']) }}</div>
                    @endif
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">Valor HH base de Persona</div>
                    <div class="fw-semibold">{{ is_array($selectedPayrollPerson) && filled($selectedPayrollPerson['payroll_hourly_value'] ?? null) ? \App\Support\UiFormatter::formatMoney($selectedPayrollPerson['payroll_hourly_value'], $selectedPayrollPerson['payroll_hourly_currency'] ?? 'CLP').' / HH' : 'No configurado' }}</div>
                    <div class="small text-muted">{{ $payrollIsHourlyMode ? 'Referencia de la ficha de Personal para remuneración por hora.' : 'Referencia. No participa en el cálculo de esta remuneración.' }}</div>
                </div>
            </div>
        </div>

        @if (filled($payrollMonthlyAutoValue) || filled($payrollHealthAutoValue) || filled($payrollBonusesAutoValue) || filled($payrollAllowancesAutoValue) || filled($payrollAdvancesAutoValue) || filled($payrollOtherDeductionsAutoValue))
            <div class="small text-muted mt-3">
                Novedades automáticas:
                @if (filled($payrollMonthlyAutoValue)) <span class="me-2">Base mensual {{ $payrollMonthlyAutoValue }}</span> @endif
                @if (filled($payrollHealthAutoValue)) <span class="me-2">Salud adicional {{ $payrollHealthAutoValue }}</span> @endif
                @if (filled($payrollBonusesAutoValue)) <span class="me-2">Bonos {{ $payrollBonusesAutoValue }}</span> @endif
                @if (filled($payrollAllowancesAutoValue)) <span class="me-2">Asignaciones no imponibles {{ $payrollAllowancesAutoValue }}</span> @endif
                @if (filled($payrollAdvancesAutoValue)) <span class="me-2">Anticipos {{ $payrollAdvancesAutoValue }}</span> @endif
                @if (filled($payrollOtherDeductionsAutoValue)) <span class="me-2">Otros descuentos {{ $payrollOtherDeductionsAutoValue }}</span> @endif
            </div>
        @endif
    </div>
@endif

@if ($isPayroll && $editing && ! empty($payrollHourlyCost))
    <div class="app-panel p-3 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="section-title mb-0">Costo HH del período</div>
        </div>
        <div class="row g-3">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="text-muted small">Costo empresa</div>
                <div class="fw-semibold">{{ $payrollNotCalculable ? 'No calculable' : \App\Support\UiFormatter::formatMoney($payrollHourlyCost['company_cost']) }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="text-muted small">Horas período</div>
                <div class="fw-semibold">{{ \App\Support\UiFormatter::formatHours($payrollHourlyCost['worked_hours']) }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="text-muted small">Costo HH real</div>
                <div class="fw-semibold">{{ $payrollNotCalculable ? 'No calculable' : ($payrollHourlyCost['real_hourly_cost'] !== null ? \App\Support\UiFormatter::formatMoney($payrollHourlyCost['real_hourly_cost']) : '—') }}</div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="text-muted small">Costo HH ref.</div>
                <div class="fw-semibold">{{ $payrollNotCalculable ? 'No calculable' : ($payrollHourlyCost['reference_hourly_cost'] !== null ? \App\Support\UiFormatter::formatMoney($payrollHourlyCost['reference_hourly_cost']) : '—') }}</div>
            </div>
        </div>
        <div class="small text-muted mt-3">
            Costo HH real = costo empresa del período / horas productivas aprobadas.
            @if (filled($payrollHourlyCost['reference_capacity_label'] ?? null))
                {{ $payrollHourlyCost['reference_capacity_label'] }}
            @endif
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

<form method="POST" action="{{ $editing ? route('operational.update', [$resource, $item->id]) : route('operational.store', $resource) }}" class="app-panel p-4" data-operational-form="true" @if($resource === 'assignments') data-assignment-commitment-preview-url="{{ route('operational.assignment-commitment-preview', 'assignments') }}" data-assignment-current-id="{{ $editing && $item->exists ? $item->id : '' }}" @endif @if($resource === 'time-entries' && ! $editing) data-time-entry-period-preview-url="{{ route('operational.time-entry-period-preview', 'time-entries') }}" @endif>
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    @if ($resource === 'time-entries' && ! $editing)
        <div class="section-title">Modo de carga</div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="btn-group" role="group" aria-label="Modo de carga de horas">
                    <input type="radio" class="btn-check" name="entry_mode" id="entry_mode_daily" value="daily" autocomplete="off" data-time-entry-mode-toggle="true" @checked($timeEntryEntryMode !== 'period')>
                    <label class="btn btn-outline-primary" for="entry_mode_daily">Carga diaria</label>

                    <input type="radio" class="btn-check" name="entry_mode" id="entry_mode_period" value="period" autocomplete="off" data-time-entry-mode-toggle="true" @checked($timeEntryEntryMode === 'period')>
                    <label class="btn btn-outline-primary" for="entry_mode_period">Carga por período</label>
                </div>
                <div class="small text-muted mt-2">
                    Seleccione primero la persona y la fecha o período. La carga por período genera múltiples registros diarios y mantiene la misma granularidad del módulo Horas.
                </div>
            </div>
        </div>

        <div class="{{ $timeEntryEntryMode === 'period' ? '' : 'd-none' }}" data-time-entry-period-panel>
            <div class="section-title">Carga por período</div>
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6 col-xl-3">
                    <label for="period_start_date" class="form-label">Fecha inicio</label>
                    <input id="period_start_date" name="period_start_date" type="text" class="form-control @error('period_start_date') is-invalid @enderror" value="{{ $timeEntryPeriodStartDate ? \App\Support\UiFormatter::formatDate($timeEntryPeriodStartDate) : '' }}" placeholder="dd/mm/yyyy" inputmode="numeric" data-time-entry-period-start>
                    @error('period_start_date')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label for="period_end_date" class="form-label">Fecha término</label>
                    <input id="period_end_date" name="period_end_date" type="text" class="form-control @error('period_end_date') is-invalid @enderror" value="{{ $timeEntryPeriodEndDate ? \App\Support\UiFormatter::formatDate($timeEntryPeriodEndDate) : '' }}" placeholder="dd/mm/yyyy" inputmode="numeric" data-time-entry-period-end>
                    @error('period_end_date')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label for="period_distribution_mode" class="form-label">Distribución</label>
                    <select id="period_distribution_mode" name="period_distribution_mode" class="form-select @error('period_distribution_mode') is-invalid @enderror" data-time-entry-period-distribution>
                        <option value="equal" @selected($timeEntryPeriodDistributionMode === 'equal')>Horas iguales por día</option>
                        <option value="total" @selected($timeEntryPeriodDistributionMode === 'total')>Total del período</option>
                        <option value="manual" @selected($timeEntryPeriodDistributionMode === 'manual')>Manual</option>
                    </select>
                    @error('period_distribution_mode')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-12 col-md-6 col-xl-3 {{ $timeEntryPeriodDistributionMode === 'equal' ? '' : 'd-none' }}" data-time-entry-period-hours-per-day-wrap>
                    <label for="period_hours_per_day" class="form-label">Horas por día</label>
                    <input id="period_hours_per_day" name="period_hours_per_day" type="number" min="0.01" max="24" step="0.01" class="form-control @error('period_hours_per_day') is-invalid @enderror" value="{{ $timeEntryPeriodHoursPerDay }}" data-time-entry-period-hours-per-day>
                    @error('period_hours_per_day')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-12 col-md-6 col-xl-3 {{ $timeEntryPeriodDistributionMode === 'total' ? '' : 'd-none' }}" data-time-entry-period-total-hours-wrap>
                    <label for="period_total_hours" class="form-label">Total período</label>
                    <input id="period_total_hours" name="period_total_hours" type="number" min="0.01" step="0.01" class="form-control @error('period_total_hours') is-invalid @enderror" value="{{ $timeEntryPeriodTotalHours }}" data-time-entry-period-total-hours>
                    @error('period_total_hours')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <input type="hidden" name="period_rows_payload" value="{{ $timeEntryPeriodRowsPayload }}" data-time-entry-period-rows-payload>

            <div class="app-panel p-3 mb-3" data-time-entry-period-authorization>
                <div class="section-title mb-3">AUTORIZACIÓN</div>
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="approval_status_id" class="form-label">Aprobación</label>
                        <select id="approval_status_id" name="approval_status_id" class="form-select @error('approval_status_id') is-invalid @enderror" data-time-entry-approval-status-select>
                            <option value="">Seleccione</option>
                            @foreach (($options['approval_status_id'] ?? []) as $key => $option)
                                @php($label = is_array($option) ? $option['label'] : $option)
                                <option value="{{ $key }}" @selected((string) old('approval_status_id', $item->approval_status_id ?? '') === (string) $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="small text-muted mt-1">Las condiciones seleccionadas se aplicarán a todos los días incluidos en esta carga.</div>
                        @error('approval_status_id')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="payment_status" class="form-label">Pago</label>
                        <select id="payment_status" name="payment_status" class="form-select @error('payment_status') is-invalid @enderror" data-time-entry-payment-status-select>
                            @foreach (['pending' => 'Pendiente', 'paid' => 'Pagado'] as $key => $label)
                                <option value="{{ $key }}" @selected((string) old('payment_status', $item->payment_status ?? 'pending') === (string) $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="small text-muted mt-1">El pago común del lote se propagará a cada registro diario creado.</div>
                        @error('payment_status')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="app-panel bg-light border-0 p-3 mb-3">
                <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
                    <div>
                        <div class="small fw-semibold text-muted mb-1">Vista previa</div>
                        <div class="small text-muted" data-time-entry-period-summary-assignment>Seleccione persona, proyecto y rango para preparar la carga.</div>
                        <div class="small text-muted" data-time-entry-period-summary-rate></div>
                        <div class="small text-muted" data-time-entry-period-summary-client></div>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted">Total horas período</div>
                        <div class="fw-semibold" data-time-entry-period-total-hours-display>0,00 h</div>
                    </div>
                </div>
                <div class="small text-muted mt-2 d-none" data-time-entry-period-multiple-summary></div>
                <div class="alert alert-warning py-2 px-3 mt-3 mb-0 d-none" data-time-entry-period-errors-box>
                    <ul class="small mb-0 ps-3" data-time-entry-period-errors-list></ul>
                </div>
            </div>

            <div class="table-responsive border rounded mb-4">
                <table class="table table-sm align-middle mb-0" data-time-entry-period-table>
                    <thead class="table-light">
                        <tr>
                            <th style="width: 56px;">Incluir</th>
                            <th>Fecha</th>
                            <th>Asignación</th>
                            <th style="width: 140px;">Horas</th>
                        </tr>
                    </thead>
                    <tbody data-time-entry-period-rows>
                        <tr data-time-entry-period-empty-row>
                            <td colspan="4" class="text-muted small py-3">Prepare el período para ver la distribución diaria antes de guardar.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @error('period_rows')
                <div class="invalid-feedback d-block mb-3">{{ $message }}</div>
            @enderror
        </div>
    @endif

    @if ($isPayroll)
        @foreach ($payrollSections as $sectionTitle => $sectionFields)
            @if (in_array($sectionTitle, ['Resultado', 'Control del cálculo'], true))
                @continue
            @endif
            @php($visibleFields = collect($sectionFields)->filter(fn (string $field) => array_key_exists($field, $fields))->values()->all())
            @continue(empty($visibleFields))
            <div class="section-title">{{ $sectionTitle }}</div>
            @php($fieldRows = $sectionTitle === 'Datos base'
                ? [
                    ['code', 'person_id', 'project_id'],
                    ['period_date', 'amount_basis', 'payment_date'],
                ]
                : [$visibleFields])
            @foreach ($fieldRows as $rowIndex => $fieldRow)
            <div class="row g-3 mb-3 {{ $sectionTitle === 'Datos base' ? 'payroll-base-row payroll-base-row-'.($rowIndex + 1) : '' }}">
                @foreach ($fieldRow as $field)
                    @continue(! array_key_exists($field, $fields))
                    @php($definition = $fields[$field])
                    @php($type = $definition['type'] ?? 'text')
                    @php($value = old($field, array_key_exists($field, $payrollOverrideInputs) ? $payrollOverrideInputs[$field] : $item->{$field}))
                    @if ($field === 'phone_country_code' && blank($value))
                        @php($value = '+56')
                    @endif
                    @php($colClass = $resourceColumns[$field] ?? ($definition['col'] ?? 'col-12 col-md-6'))
                    @php($label = $definition['label'])
                    @php($helpText = $payrollHelp[$field] ?? $projectHelp[$field] ?? $rateUnitHelp[$field] ?? null)
                    @php($isCalculated = in_array($field, $payrollAutoFields, true))
                    @php($isPayrollIdentityField = $editing && in_array($field, ['person_id', 'project_id', 'period_date'], true))
                    @php($isManual = in_array($field, $payrollManualFields, true) && ! $isPayrollIdentityField)
                    @php($payrollReadonlyDisplay = match ($field) {
                        'person_id' => $selectedPayrollTitle ?: 'Pendiente de selección',
                        'project_id' => is_array($payrollSelectedProjectOption) ? ($payrollSelectedProjectOption['label'] ?? 'Pendiente de selección') : ($item->project?->name ?: 'Pendiente de selección'),
                        'period_date' => $payrollPeriodLabel,
                        default => null,
                    })
                    @php($payrollReadonlyRaw = match ($field) {
                        'period_date' => old('period_date', optional($item->period_date)->toDateString()),
                        default => old($field, $item->{$field}),
                    })
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
                        </div>
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
                            @if ($isPayrollIdentityField)
                                <input type="hidden" name="{{ $field }}" value="{{ $payrollReadonlyRaw }}">
                                <input
                                    id="{{ $field }}"
                                    class="form-control payroll-calculated-field"
                                    value="{{ $payrollReadonlyDisplay }}"
                                    readonly
                                    aria-readonly="true"
                                >
                                @if ($field === 'period_date')
                                    <div class="small text-muted mt-1">Mes: {{ $payrollPeriodLabel }}</div>
                                @endif
                            @elseif (($definition['readonly'] ?? false) === true)
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
                                    value="{{ $type === 'date' ? ($displayValue ?? '') : $payrollEditableValue($field, $definition, $displayValue) }}"
                                    @if ($type === 'date') placeholder="dd/mm/yyyy" inputmode="numeric" @endif
                                    @if (($definition['presentation'] ?? null) === 'rut') placeholder="12.345.678-5" @endif
                                    @if (($definition['presentation'] ?? null) === 'phone') placeholder="+56 9 1234 5678" @endif
                                    @if (($definition['presentation'] ?? null) === 'rut') data-rut-field="true" autocomplete="off" @endif
                                    @if ($isCalculated) readonly aria-readonly="true" data-bs-toggle="tooltip" data-bs-trigger="hover focus" data-bs-title="Calculado automáticamente. Para modificar el resultado cambie los datos base o parámetros correspondientes." @endif
                                    @if ($isManual) data-bs-toggle="tooltip" data-bs-trigger="hover focus" data-bs-title="Dato de ingreso manual. Ingrese solo si corresponde al período." @endif
                                >
                                @if ($isPayroll && $field === 'period_date')
                                    <div class="small text-muted mt-1">Mes: {{ $payrollPeriodLabel }}</div>
                                @endif
                            @endif
                            @error($field)
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                            @if ($isPayroll && array_key_exists($field, $payrollAutomaticSourceLabels))
                                @php($automaticSourceLabel = $payrollAutomaticSourceLabels[$field])
                                @php($automaticSourceValue = data_get($payrollSourceRows->get($automaticSourceLabel), 'value'))
                                @if (filled($automaticSourceValue) && ! ($field === 'hourly_value' && ! $payrollIsHourlyMode))
                                    <div class="small text-muted mt-1">Automático: {{ $automaticSourceValue }}</div>
                                @endif
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
            @endforeach
        @endforeach

        <div class="section-title">Resultado</div>
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-4">
                <x-kpi-card
                    title="Base calculada"
                    :value="$payrollMoneyOrUnavailable($item->base_salary)"
                    icon="bi bi-calculator"
                    tone="primary"
                />
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <x-kpi-card
                    title="Total imponible"
                    :value="$payrollMoneyOrUnavailable($item->taxable_gross)"
                    icon="bi bi-cash"
                    tone="info"
                />
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <x-kpi-card
                    title="Retención honorarios"
                    :value="$payrollMoneyOrUnavailable($item->employee_retention)"
                    icon="bi bi-receipt"
                    tone="warning"
                />
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <x-kpi-card
                    title="Anticipos"
                    :value="\App\Support\UiFormatter::formatMoney($item->advances)"
                    icon="bi bi-arrow-down-circle"
                    tone="secondary"
                />
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <x-kpi-card
                    title="Otros descuentos"
                    :value="\App\Support\UiFormatter::formatMoney($item->other_deductions)"
                    icon="bi bi-slash-circle"
                    tone="secondary"
                />
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <x-kpi-card
                    title="Líquido"
                    :value="$payrollMoneyOrUnavailable($item->net_pay)"
                    icon="bi bi-wallet2"
                    tone="success"
                />
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <x-kpi-card
                    title="Costo empresa"
                    :value="$payrollMoneyOrUnavailable($item->employer_cost)"
                    icon="bi bi-building"
                    tone="dark"
                />
            </div>
        </div>

        <div class="section-title">Descuentos</div>
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">Base AFP/salud</div>
                    <div class="fw-semibold">{{ \App\Support\UiFormatter::formatMoney($item->pension_health_base) }}</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">Base AFC</div>
                    <div class="fw-semibold">{{ \App\Support\UiFormatter::formatMoney($item->afc_base) }}</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">AFP 10%</div>
                    <div class="fw-semibold">{{ \App\Support\UiFormatter::formatMoney($item->afp_mandatory) }}</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">Comisión AFP</div>
                    <div class="fw-semibold">{{ \App\Support\UiFormatter::formatMoney($item->afp_commission) }}</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">Salud trabajador</div>
                    <div class="fw-semibold">{{ \App\Support\UiFormatter::formatMoney($item->health_employee) }}</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">AFC trabajador</div>
                    <div class="fw-semibold">{{ \App\Support\UiFormatter::formatMoney($item->afc_employee) }}</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="app-panel bg-light border-0 p-3 h-100">
                    <div class="small text-muted">IUSC</div>
                    <div class="fw-semibold">{{ \App\Support\UiFormatter::formatMoney($item->iusc_amount) }}</div>
                </div>
            </div>
        </div>

        <div class="section-title">Control del cálculo</div>
        <div class="app-panel p-3 mb-3">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span class="small text-muted">Estado:</span>
                <x-status-badge :status="$payrollCalculationStatusLabel" />
            </div>
            <div class="small text-muted">
                Observación:
                {{ filled($item->calculation_notes) ? $item->calculation_notes : '—' }}
            </div>
        </div>
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
                @php($timeEntryDailyOnly = $resource === 'time-entries' && ! $editing && in_array($field, ['client_id', 'entry_date', 'hours_worked', 'hours_approved', 'hourly_value', 'approval_status_id', 'payment_status'], true))
                @continue($resource === 'time-entries' && ! $editing && $timeEntryEntryMode === 'period' && in_array($field, $timeEntryPeriodAuthorizationFields, true))
                @if ($field === 'code' && $autoCode)
                    <div class="{{ $colClass }}{{ $timeEntryDailyOnly ? ' time-entry-daily-only' : '' }}" @if($timeEntryDailyOnly) data-time-entry-daily-only="true" @endif>
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
                                    {{ $timeEntryRatePreviewAmount === null ? 'No existe un Valor HH de costeo aplicable para esta combinación de persona, proyecto y fecha.' : ($timeEntryRatePreviewSource ? 'Origen: '.$timeEntryRatePreviewSource : 'Valor HH de costeo obtenido automáticamente.') }}
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
                                <div class="app-panel bg-light border-0 p-2 mt-2 {{ $timeEntryEntryMode === 'period' && ! $editing ? 'd-none' : '' }}" data-time-entry-assignment-context data-time-entry-daily-context="true">
                                    <div class="small fw-semibold text-muted mb-1">Referencia de la asignación</div>
                                    <div class="small text-muted" data-time-entry-assignment-label>{{ $timeEntryAssignmentLabel }}</div>
                                    <div class="small text-muted" data-time-entry-assignment-project>{{ $timeEntryAssignmentProjectLabel }}</div>
                                    <div class="small text-muted" data-time-entry-assignment-vigency>{{ $timeEntryAssignmentVigencyLabel }}</div>
                                    <div class="small text-muted" data-time-entry-assignment-client>{{ $timeEntryContextClient }}</div>
                                    <div class="small text-muted" data-time-entry-assignment-rate>
                                        Valor HH de costeo del proyecto: {{ $timeEntryRatePreviewAmount !== null ? trim($timeEntryRatePreviewPrefix.' '.($timeEntryRatePreviewDisplay ?? '')).' / HH' : 'No aplica / No configurada' }}
                                    </div>
                                    <div class="small text-muted {{ $timeEntryContextCostCenter ? '' : 'd-none' }}" data-time-entry-assignment-cost-center>{{ $timeEntryContextCostCenter }}</div>
                                </div>
                                <div class="mt-2 {{ $timeEntryContextWarning && !($timeEntryEntryMode === 'period' && ! $editing) ? 'alert alert-warning py-2 mb-0' : 'd-none' }}" data-time-entry-context-warning-box data-time-entry-daily-context="true">
                                    <div class="{{ $timeEntryContextWarning ? '' : 'd-none' }}" data-time-entry-context-warning>{{ $timeEntryContextWarning }}</div>
                                </div>
                            @elseif ($resource === 'assignments' && $field === 'person_id')
                                <select
                                    id="{{ $field }}"
                                    name="{{ $field }}"
                                    class="form-select @error($field) is-invalid @enderror"
                                    data-assignments-person-select="true"
                                >
                                    <option value="">Seleccione</option>
                                    @foreach (($options[$field] ?? []) as $key => $option)
                                        @php($label = is_array($option) ? $option['label'] : $option)
                                        <option
                                            value="{{ $key }}"
                                            @selected((string) $value === (string) $key)
                                            @if(is_array($option))
                                                data-person-rate-amount="{{ $option['person_rate_amount'] ?? '' }}"
                                                data-person-rate-unit-type="{{ $option['person_rate_unit_type'] ?? '' }}"
                                                data-person-rate-currency-code="{{ $option['person_rate_currency_code'] ?? '' }}"
                                                data-person-rate-currency-symbol="{{ $option['person_rate_currency_symbol'] ?? '' }}"
                                                data-person-rate-minor-units="{{ $option['person_rate_minor_units'] ?? '' }}"
                                                data-person-rate-label="{{ $option['person_rate_label'] ?? '' }}"
                                            @endif
                                        >{{ $label }}</option>
                                    @endforeach
                                </select>
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
                                                data-project-rate-amount="{{ $option['project_rate_amount'] ?? '' }}"
                                                data-project-rate-unit-type="{{ $option['project_rate_unit_type'] ?? '' }}"
                                                data-project-rate-currency-code="{{ $option['project_rate_currency_code'] ?? '' }}"
                                                data-project-rate-currency-symbol="{{ $option['project_rate_currency_symbol'] ?? '' }}"
                                                data-project-rate-minor-units="{{ $option['project_rate_minor_units'] ?? '' }}"
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
                                    <div class="small text-muted {{ $assignmentProjectRateReferenceDisplay ? '' : 'd-none' }}" data-assignments-project-rate>
                                        {{ $assignmentProjectRateReferenceDisplay }}
                                    </div>
                                    <div class="small text-muted" data-assignments-project-vigency>
                                        {{ $assignmentProjectVigencyDisplay }}
                                    </div>
                                </div>
                                <div class="app-panel bg-light border-0 p-2 mt-2" data-assignments-commitment-reference>
                                    <div class="small fw-semibold text-muted mb-1">Compromiso del proyecto</div>
                                    <div class="small text-muted" data-assignments-commitment-sale-contractual>{{ $assignmentCommitmentSaleContractualDisplay }}</div>
                                    <div class="small text-muted mt-1 {{ filled($assignmentCommitmentSaleEquivalentDisplay) ? '' : 'd-none' }}" data-assignments-commitment-sale-equivalent>{{ $assignmentCommitmentSaleEquivalentDisplay }}</div>
                                    <div class="small text-muted" data-assignments-commitment-current>{{ $assignmentCommitmentCurrentDisplay }}</div>
                                    <div class="small text-muted" data-assignments-commitment-estimate>{{ $assignmentCommitmentEstimateDisplay }}</div>
                                    <div class="small text-muted" data-assignments-commitment-after>{{ $assignmentCommitmentAfterDisplay }}</div>
                                    <div class="small text-muted" data-assignments-commitment-margin>{{ $assignmentCommitmentMarginDisplay }}</div>
                                    <div class="small text-muted" data-assignments-commitment-percentage>{{ $assignmentCommitmentPercentageDisplay }}</div>
                                    <div class="small text-muted mt-1 {{ filled($assignmentCommitmentExchangeRateNote) ? '' : 'd-none' }}" data-assignments-commitment-exchange-note>{{ $assignmentCommitmentExchangeRateNote }}</div>
                                </div>
                                <div class="mt-2 {{ ($assignmentCommitmentNegativeWarning || !empty($assignmentCommitmentDisplayWarnings)) ? 'alert alert-warning py-2 mb-0' : 'd-none' }}" data-assignments-commitment-warning-box>
                                    <div class="{{ $assignmentCommitmentNegativeWarning ? '' : 'd-none' }}" data-assignments-commitment-warning-negative>{{ $assignmentCommitmentNegativeWarning }}</div>
                                    <ul class="small mb-0 ps-3 {{ !empty($assignmentCommitmentDisplayWarnings) ? '' : 'd-none' }}" data-assignments-commitment-warning-list>
                                        @foreach ($assignmentCommitmentDisplayWarnings as $warning)
                                            <li>{{ $warning }}</li>
                                        @endforeach
                                    </ul>
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
                                        <option
                                            value="{{ $key }}"
                                            @selected((string) $value === (string) $key)
                                            @if(is_array($option))
                                                data-person-rate-amount="{{ $option['person_rate_amount'] ?? '' }}"
                                                data-person-rate-unit-type="{{ $option['person_rate_unit_type'] ?? '' }}"
                                                data-person-rate-currency-code="{{ $option['person_rate_currency_code'] ?? '' }}"
                                                data-person-rate-currency-symbol="{{ $option['person_rate_currency_symbol'] ?? '' }}"
                                                data-person-rate-minor-units="{{ $option['person_rate_minor_units'] ?? '' }}"
                                                data-person-rate-label="{{ $option['person_rate_label'] ?? '' }}"
                                            @endif
                                        >{{ $label }}</option>
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
                                                    data-currency-minor-units="{{ $currencyOption['minor_units'] ?? 2 }}"
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
                            @if ($resource === 'assignments' && $field === 'hourly_value')
                                <div class="small text-muted mt-2 {{ $assignmentPersonRateReferenceDisplay ? '' : 'd-none' }}" data-assignments-hourly-reference>
                                    Referencia Persona: {{ $assignmentPersonRateReferenceDisplay ? str_replace('Valor HH base Persona: ', '', $assignmentPersonRateReferenceDisplay) : '' }}
                                </div>
                                <div class="small text-muted mt-1" data-assignments-hourly-effective>
                                    {{ $assignmentEffectiveHourlyDisplay }}
                                </div>
                            @endif
                            @if ($resource === 'assignments' && $field === 'project_value')
                                <div class="small text-muted mt-2" data-assignments-project-value-effective>
                                    {{ $assignmentEffectiveProjectValueDisplay }}
                                </div>
                            @endif
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
        <button type="submit" class="btn btn-primary" data-time-entry-submit-label>{{ $resource === 'time-entries' && ! $editing && $timeEntryEntryMode === 'period' ? 'Registrar período' : 'Guardar' }}</button>
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
        const timeEntryModeToggles = Array.from(form.querySelectorAll('[data-time-entry-mode-toggle="true"]'));
        const timeEntryPeriodPanel = form.querySelector('[data-time-entry-period-panel]');
        const timeEntryDailyOnlyFields = Array.from(form.querySelectorAll('[data-time-entry-daily-only="true"]'));
        const timeEntryPeriodPreviewUrl = form.dataset.timeEntryPeriodPreviewUrl || '';
        const timeEntryPeriodStartInput = form.querySelector('[data-time-entry-period-start]');
        const timeEntryPeriodEndInput = form.querySelector('[data-time-entry-period-end]');
        const timeEntryPeriodDistributionSelect = form.querySelector('[data-time-entry-period-distribution]');
        const timeEntryPeriodHoursPerDayWrap = form.querySelector('[data-time-entry-period-hours-per-day-wrap]');
        const timeEntryPeriodHoursPerDayInput = form.querySelector('[data-time-entry-period-hours-per-day]');
        const timeEntryPeriodTotalHoursWrap = form.querySelector('[data-time-entry-period-total-hours-wrap]');
        const timeEntryPeriodTotalHoursInput = form.querySelector('[data-time-entry-period-total-hours]');
        const timeEntryPeriodRowsPayload = form.querySelector('[data-time-entry-period-rows-payload]');
        const timeEntryPeriodSummaryAssignment = form.querySelector('[data-time-entry-period-summary-assignment]');
        const timeEntryPeriodSummaryRate = form.querySelector('[data-time-entry-period-summary-rate]');
        const timeEntryPeriodSummaryClient = form.querySelector('[data-time-entry-period-summary-client]');
        const timeEntryPeriodMultipleSummary = form.querySelector('[data-time-entry-period-multiple-summary]');
        const timeEntryPeriodErrorsBox = form.querySelector('[data-time-entry-period-errors-box]');
        const timeEntryPeriodErrorsList = form.querySelector('[data-time-entry-period-errors-list]');
        const timeEntryPeriodRows = form.querySelector('[data-time-entry-period-rows]');
        const timeEntryPeriodTotalHoursDisplay = form.querySelector('[data-time-entry-period-total-hours-display]');
        const timeEntrySubmitLabel = form.querySelector('[data-time-entry-submit-label]');
        let timeEntryPeriodRowsState = [];
        let timeEntryPeriodAbortController = null;
        let timeEntryPeriodPreviewTimer = null;

        const isTimeEntryPeriodMode = () => timeEntryModeToggles.some((toggle) => toggle.checked && toggle.value === 'period');

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

        const formatTimeEntryHours = (value) => {
            if (value === null || value === undefined || value === '') {
                return '0,00 h';
            }

            return `${new Intl.NumberFormat('es-CL', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(Number(value))} h`;
        };

        const parseTimeEntryNumber = (value) => {
            if (value === null || value === undefined || value === '') {
                return null;
            }

            const normalized = String(value).replace(/\s+/g, '').replace(',', '.');
            const parsed = Number(normalized);

            return Number.isFinite(parsed) ? parsed : null;
        };

        const escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

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

        const selectedTimeEntryPersonOption = () => {
            if (!timeEntryPersonSelect) {
                return null;
            }

            return timeEntryPersonSelect.options[timeEntryPersonSelect.selectedIndex] || null;
        };

        const resolveTimeEntryRate = () => {
            const projectOption = selectedTimeEntryProjectOption();
            const personOption = selectedTimeEntryPersonOption();
            if (!projectOption) {
                return { amount: null, prefix: '—', decimals: 2, source: null, clientId: '', clientLabel: '', matchedRange: null, projectOption: null };
            }

            const matchedRanges = timeEntryMatchingRanges(projectOption);
            const matchedRange = matchedRanges.length === 1 ? matchedRanges[0] : null;

            const personRateAmount = parseTimeEntryNumber(personOption?.dataset?.personRateAmount || '');
            const personRateUnitType = String(personOption?.dataset?.personRateUnitType || 'CURRENCY').toUpperCase();
            const personRateCurrencyCode = String(personOption?.dataset?.personRateCurrencyCode || (personRateUnitType === 'UF' ? 'UF' : 'CLP')).toUpperCase();
            const personRateCurrencySymbol = personOption?.dataset?.personRateCurrencySymbol || (personRateCurrencyCode === 'CLP' ? '$' : personRateCurrencyCode);
            const personRateMinorUnits = Number.parseInt(personOption?.dataset?.personRateMinorUnits || (personRateCurrencyCode === 'CLP' ? '0' : '2'), 10);
            const sourceType = matchedRange && Number(matchedRange.hourly_value) > 0
                ? 'assignment'
                : (personRateAmount !== null && personRateAmount > 0 ? 'person' : null);
            const amount = sourceType === 'assignment'
                ? matchedRange.hourly_value
                : (sourceType === 'person' ? personRateAmount : null);
            const unitType = sourceType === 'assignment'
                ? (matchedRange.hourly_rate_unit_type || 'CURRENCY')
                : (sourceType === 'person' ? personRateUnitType : 'CURRENCY');
            const currencyCode = sourceType === 'assignment'
                ? (matchedRange.currency_code || (unitType === 'UF' ? 'UF' : 'CLP'))
                : (sourceType === 'person' ? personRateCurrencyCode : 'CLP');
            const currencySymbol = sourceType === 'assignment'
                ? (matchedRange.currency_symbol || (currencyCode === 'CLP' ? '$' : currencyCode))
                : (sourceType === 'person' ? personRateCurrencySymbol : '$');
            const decimals = sourceType === 'assignment'
                ? (unitType === 'UF' ? 2 : (currencyCode === 'CLP' ? 0 : 2))
                : (sourceType === 'person'
                    ? (unitType === 'UF' ? 2 : (Number.isNaN(personRateMinorUnits) ? (currencyCode === 'CLP' ? 0 : 2) : personRateMinorUnits))
                    : 2);
            const sourceLabel = sourceType === 'assignment'
                ? (matchedRange?.source_label || `Asignación · ${projectOption.dataset.projectName || projectOption.textContent.trim()}`)
                : (sourceType === 'person'
                    ? (personOption?.dataset?.personRateLabel || `Persona · ${personOption?.textContent?.trim() || 'No informada'}`)
                    : null);

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
            const periodMode = isTimeEntryPeriodMode();

            Array.from(timeEntryProjectSelect.options).forEach((option, index) => {
                if (index === 0) {
                    option.hidden = false;
                    return;
                }

                const visible = periodMode
                    ? timeEntryRangesForPerson(option).length > 0
                    : timeEntryMatchingRanges(option).length > 0;
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
            } else if (!periodMode && !timeEntryDateInput?.value) {
                timeEntryProjectSelect.disabled = true;
                placeholder.textContent = 'Seleccione una fecha primero';
            } else if (visibleOptions === 0) {
                timeEntryProjectSelect.disabled = true;
                placeholder.textContent = periodMode
                    ? 'No existen proyectos asignados para esta persona.'
                    : 'No existen proyectos asignados para esta persona en la fecha indicada.';
            } else {
                timeEntryProjectSelect.disabled = false;
                placeholder.textContent = 'Seleccione';
            }
        };

        const syncTimeEntryContext = () => {
            syncTimeEntryProjects();

            if (isTimeEntryPeriodMode()) {
                const projectOption = selectedTimeEntryProjectOption();
                const clientId = projectOption?.dataset?.clientId || '';
                const clientLabel = projectOption?.dataset?.clientLabel || '';

                if (timeEntryClientHidden && timeEntryClientDisplay) {
                    timeEntryClientHidden.value = clientId;
                    timeEntryClientDisplay.value = clientLabel || '—';
                }

                if (timeEntryRateRaw && timeEntryRateDisplay && timeEntryRatePrefix && timeEntryRateMessage) {
                    timeEntryRateRaw.value = '';
                    timeEntryRateDisplay.value = '';
                    timeEntryRateDisplay.placeholder = 'Se resolverá por fecha en la vista previa';
                    timeEntryRatePrefix.textContent = '—';
                    timeEntryRateMessage.textContent = 'La carga por período resuelve la asignación y el Valor HH por cada fecha antes de guardar.';
                    timeEntryRateMessage.className = 'small mt-1 text-muted';
                }

                if (timeEntryContextWarningBox && timeEntryContextWarning) {
                    timeEntryContextWarning.textContent = '';
                    timeEntryContextWarning.classList.add('d-none');
                    timeEntryContextWarningBox.classList.add('d-none');
                }

                if (timeEntryDateValidationBox && timeEntryDateValidation) {
                    timeEntryDateValidation.textContent = '';
                    timeEntryDateValidationBox.classList.add('d-none');
                }

                return;
            }

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
                    timeEntryRateMessage.textContent = 'No existe un Valor HH de costeo aplicable para esta combinación de persona, proyecto y fecha.';
                    timeEntryRateMessage.className = 'small mt-1 text-warning';
                } else {
                    timeEntryRateRaw.value = resolution.amount;
                    timeEntryRateDisplay.value = formatRateValue(resolution.amount, resolution.decimals);
                    timeEntryRateDisplay.placeholder = '';
                    timeEntryRatePrefix.textContent = resolution.prefix || '—';
                    timeEntryRateMessage.textContent = resolution.source ? `Origen: ${resolution.source}` : 'Valor HH de costeo obtenido automáticamente.';
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
                timeEntryAssignmentRate.textContent = `Valor HH de costeo del proyecto: ${resolution.amount !== null && resolution.amount !== '' ? `${resolution.prefix || '—'} ${formatRateValue(resolution.amount, resolution.decimals)} / HH` : 'No aplica / No configurada'}`;
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

        const syncTimeEntryPeriodDistributionUi = () => {
            const distributionMode = String(timeEntryPeriodDistributionSelect?.value || 'equal');

            if (timeEntryPeriodHoursPerDayWrap) {
                timeEntryPeriodHoursPerDayWrap.classList.toggle('d-none', distributionMode !== 'equal');
            }

            if (timeEntryPeriodTotalHoursWrap) {
                timeEntryPeriodTotalHoursWrap.classList.toggle('d-none', distributionMode !== 'total');
            }
        };

        const syncTimeEntryPeriodRowsPayload = () => {
            if (!timeEntryPeriodRowsPayload) {
                return;
            }

            timeEntryPeriodRowsPayload.value = JSON.stringify(timeEntryPeriodRowsState);
        };

        const renderTimeEntryPeriodPreview = (preview = null) => {
            const rows = Array.isArray(preview?.rows) ? preview.rows : [];
            const fieldErrors = preview?.field_errors && typeof preview.field_errors === 'object'
                ? Object.values(preview.field_errors).flat()
                : [];
            const summary = preview?.summary || {};
            const distributionMode = String(timeEntryPeriodDistributionSelect?.value || 'equal');

            timeEntryPeriodRowsState = rows.map((row) => ({
                entry_date: row.entry_date,
                included: Boolean(row.included),
                hours_worked: row.hours_worked,
            }));
            syncTimeEntryPeriodRowsPayload();

            if (timeEntryPeriodSummaryAssignment) {
                timeEntryPeriodSummaryAssignment.textContent = summary.shared_assignment_label
                    ? `Asignación: ${summary.shared_assignment_label}`
                    : (summary.multiple_assignments ? 'Asignación: El período utiliza más de una asignación vigente.' : 'Asignación: Seleccione persona, proyecto y rango para preparar la carga.');
            }

            if (timeEntryPeriodSummaryRate) {
                timeEntryPeriodSummaryRate.textContent = summary.shared_rate_display
                    ? `Valor HH de costeo del proyecto: ${summary.shared_rate_display}${summary.shared_rate_source ? ` · ${summary.shared_rate_source}` : ''}`
                    : (summary.multiple_rates ? 'Valor HH de costeo del proyecto: El período utiliza más de un valor HH.' : '');
            }

            if (timeEntryPeriodSummaryClient) {
                timeEntryPeriodSummaryClient.textContent = summary.client_label ? `Cliente: ${summary.client_label}` : '';
            }

            if (timeEntryPeriodMultipleSummary) {
                const notes = [];
                if (summary.multiple_assignments) {
                    notes.push('El período cruza más de una asignación válida.');
                }
                if (summary.multiple_rates) {
                    notes.push('El Valor HH de costeo cambia durante el período.');
                }
                timeEntryPeriodMultipleSummary.textContent = notes.join(' ');
                timeEntryPeriodMultipleSummary.classList.toggle('d-none', notes.length === 0);
            }

            if (timeEntryPeriodTotalHoursDisplay) {
                timeEntryPeriodTotalHoursDisplay.textContent = formatTimeEntryHours(preview?.total_hours ?? 0);
            }

            if (timeEntryPeriodErrorsBox && timeEntryPeriodErrorsList) {
                timeEntryPeriodErrorsList.innerHTML = fieldErrors.map((message) => `<li>${escapeHtml(message)}</li>`).join('');
                timeEntryPeriodErrorsBox.classList.toggle('d-none', fieldErrors.length === 0);
            }

            if (!timeEntryPeriodRows) {
                return;
            }

            if (rows.length === 0) {
                timeEntryPeriodRows.innerHTML = `
                    <tr data-time-entry-period-empty-row>
                        <td colspan="4" class="text-muted small py-3">Prepare el período para ver la distribución diaria antes de guardar.</td>
                    </tr>
                `;
                return;
            }

            timeEntryPeriodRows.innerHTML = rows.map((row, index) => {
                const rowMessages = [...(row.errors || []), ...(row.warnings || [])];
                const hoursControl = distributionMode === 'manual'
                    ? `<input type="number" min="0.01" max="24" step="0.01" class="form-control form-control-sm" data-time-entry-period-row-hours value="${row.hours_worked ?? ''}" ${row.included ? '' : 'disabled'}>`
                    : `<span>${escapeHtml(row.hours_display || '—')}</span>`;

                return `
                    <tr data-time-entry-period-row data-row-index="${index}" data-entry-date="${escapeHtml(row.entry_date || '')}">
                        <td>
                            <input type="checkbox" class="form-check-input" data-time-entry-period-row-included ${row.included ? 'checked' : ''}>
                        </td>
                        <td>
                            <div class="small fw-semibold">${escapeHtml(row.date_display || '')}</div>
                        </td>
                        <td>
                            <div class="small">${escapeHtml(row.assignment_label || 'No disponible')}</div>
                            <div class="text-muted small">${escapeHtml(row.hourly_value_display || '')}${row.hourly_value_source ? ` · ${escapeHtml(row.hourly_value_source)}` : ''}</div>
                        </td>
                        <td>${hoursControl}</td>
                    </tr>
                `;
            }).join('');
        };

        const collectTimeEntryPeriodRowsFromDom = () => {
            if (!timeEntryPeriodRows) {
                return;
            }

            timeEntryPeriodRowsState = Array.from(timeEntryPeriodRows.querySelectorAll('[data-time-entry-period-row]')).map((row) => {
                const included = row.querySelector('[data-time-entry-period-row-included]')?.checked ?? false;
                const hoursValue = row.querySelector('[data-time-entry-period-row-hours]')?.value ?? '';

                return {
                    entry_date: row.dataset.entryDate || '',
                    included,
                    hours_worked: included ? parseTimeEntryNumber(hoursValue) : null,
                };
            });

            syncTimeEntryPeriodRowsPayload();
        };

        const requestTimeEntryPeriodPreview = () => {
            if (!timeEntryPeriodPreviewUrl || !timeEntryPeriodPanel || !isTimeEntryPeriodMode()) {
                return;
            }

            const payload = new URLSearchParams();
            payload.set('_token', csrfToken);
            payload.set('entry_mode', 'period');
            payload.set('person_id', timeEntryPersonSelect?.value || '');
            payload.set('project_id', timeEntryProjectSelect?.value || '');
            payload.set('activity_id', form.querySelector('#activity_id')?.value || '');
            payload.set('cost_center_id', timeEntryCostCenterSelect?.value || '');
            payload.set('approval_status_id', timeEntryApprovalStatusSelect?.value || '');
            payload.set('payment_status', timeEntryPaymentStatusSelect?.value || '');
            payload.set('period_start_date', timeEntryPeriodStartInput?.value || '');
            payload.set('period_end_date', timeEntryPeriodEndInput?.value || '');
            payload.set('period_distribution_mode', timeEntryPeriodDistributionSelect?.value || 'equal');
            payload.set('period_hours_per_day', timeEntryPeriodHoursPerDayInput?.value || '');
            payload.set('period_total_hours', timeEntryPeriodTotalHoursInput?.value || '');
            payload.set('period_rows_payload', timeEntryPeriodRowsPayload?.value || '');

            timeEntryPeriodAbortController?.abort();
            timeEntryPeriodAbortController = new AbortController();

            fetch(timeEntryPeriodPreviewUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: payload.toString(),
                signal: timeEntryPeriodAbortController.signal,
            })
                .then((response) => response.ok ? response.json() : Promise.reject(new Error(`preview-${response.status}`)))
                .then((preview) => renderTimeEntryPeriodPreview(preview))
                .catch((error) => {
                    if (error.name === 'AbortError') {
                        return;
                    }

                    renderTimeEntryPeriodPreview({
                        rows: [],
                        total_hours: 0,
                        field_errors: {
                            period_rows: ['No fue posible preparar la carga por período en este momento.'],
                        },
                        summary: {},
                    });
                });
        };

        const scheduleTimeEntryPeriodPreview = () => {
            if (!timeEntryPeriodPanel || !isTimeEntryPeriodMode()) {
                return;
            }

            window.clearTimeout(timeEntryPeriodPreviewTimer);
            timeEntryPeriodPreviewTimer = window.setTimeout(requestTimeEntryPeriodPreview, 180);
        };

        const syncTimeEntryMode = () => {
            const periodMode = isTimeEntryPeriodMode();

            if (timeEntryPeriodPanel) {
                timeEntryPeriodPanel.classList.toggle('d-none', !periodMode);
            }

            timeEntryDailyOnlyFields.forEach((element) => {
                element.classList.toggle('d-none', periodMode);
            });

            if (timeEntrySubmitLabel) {
                timeEntrySubmitLabel.textContent = periodMode ? 'Registrar período' : 'Guardar';
            }

            syncTimeEntryPeriodDistributionUi();
            syncTimeEntryContext();

            if (periodMode) {
                scheduleTimeEntryPeriodPreview();
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

            if (isTimeEntryPeriodMode() && ['person_id', 'project_id', 'approval_status_id', 'payment_status'].includes(event.target?.id || '')) {
                scheduleTimeEntryPeriodPreview();
            }
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
        timeEntryModeToggles.forEach((toggle) => toggle.addEventListener('change', syncTimeEntryMode));
        timeEntryPeriodStartInput?.addEventListener('input', scheduleTimeEntryPeriodPreview);
        timeEntryPeriodStartInput?.addEventListener('change', scheduleTimeEntryPeriodPreview);
        timeEntryPeriodEndInput?.addEventListener('input', scheduleTimeEntryPeriodPreview);
        timeEntryPeriodEndInput?.addEventListener('change', scheduleTimeEntryPeriodPreview);
        timeEntryPeriodDistributionSelect?.addEventListener('change', () => {
            timeEntryPeriodRowsState = [];
            syncTimeEntryPeriodRowsPayload();
            syncTimeEntryPeriodDistributionUi();
            scheduleTimeEntryPeriodPreview();
        });
        timeEntryPeriodHoursPerDayInput?.addEventListener('input', scheduleTimeEntryPeriodPreview);
        timeEntryPeriodHoursPerDayInput?.addEventListener('change', scheduleTimeEntryPeriodPreview);
        timeEntryPeriodTotalHoursInput?.addEventListener('input', scheduleTimeEntryPeriodPreview);
        timeEntryPeriodTotalHoursInput?.addEventListener('change', scheduleTimeEntryPeriodPreview);
        form.querySelector('#activity_id')?.addEventListener('change', scheduleTimeEntryPeriodPreview);
        timeEntryCostCenterSelect?.addEventListener('change', scheduleTimeEntryPeriodPreview);
        timeEntryApprovalStatusSelect?.addEventListener('change', scheduleTimeEntryPeriodPreview);
        timeEntryPaymentStatusSelect?.addEventListener('change', scheduleTimeEntryPeriodPreview);
        timeEntryPeriodRows?.addEventListener('change', (event) => {
            if (!event.target?.matches?.('[data-time-entry-period-row-included], [data-time-entry-period-row-hours]')) {
                return;
            }

            collectTimeEntryPeriodRowsFromDom();
            scheduleTimeEntryPeriodPreview();
        });
        timeEntryPeriodRows?.addEventListener('input', (event) => {
            if (!event.target?.matches?.('[data-time-entry-period-row-hours]')) {
                return;
            }

            collectTimeEntryPeriodRowsFromDom();
            if (String(timeEntryPeriodDistributionSelect?.value || 'equal') === 'manual') {
                scheduleTimeEntryPeriodPreview();
            }
        });
        syncTimeEntryMode();
        if (!isTimeEntryPeriodMode()) {
            syncTimeEntryContext();
        }

        const assignmentPersonSelect = form.querySelector('[data-assignments-person-select="true"]');
        const assignmentProjectSelect = form.querySelector('[data-assignments-project-select="true"]');
        const assignmentProjectSaleNet = form.querySelector('[data-assignments-project-sale-net]');
        const assignmentProjectRate = form.querySelector('[data-assignments-project-rate]');
        const assignmentProjectVigency = form.querySelector('[data-assignments-project-vigency]');
        const assignmentTariffWarningBox = form.querySelector('[data-assignments-tariff-warning-box]');
        const assignmentVigencyWarningBox = form.querySelector('[data-assignments-vigency-warning-box]');
        const assignmentWarningDouble = form.querySelector('[data-assignments-warning-double]');
        const assignmentWarningSale = form.querySelector('[data-assignments-warning-sale]');
        const assignmentWarningVigency = form.querySelector('[data-assignments-warning-vigency]');
        const assignmentHourlyReference = form.querySelector('[data-assignments-hourly-reference]');
        const assignmentHourlyEffective = form.querySelector('[data-assignments-hourly-effective]');
        const assignmentProjectValueEffective = form.querySelector('[data-assignments-project-value-effective]');
        const assignmentCommitmentBox = form.querySelector('[data-assignments-commitment-reference]');
        const assignmentCommitmentWarningBox = form.querySelector('[data-assignments-commitment-warning-box]');
        const assignmentCommitmentSaleContractual = form.querySelector('[data-assignments-commitment-sale-contractual]');
        const assignmentCommitmentSaleEquivalent = form.querySelector('[data-assignments-commitment-sale-equivalent]');
        const assignmentCommitmentCurrent = form.querySelector('[data-assignments-commitment-current]');
        const assignmentCommitmentEstimate = form.querySelector('[data-assignments-commitment-estimate]');
        const assignmentCommitmentAfter = form.querySelector('[data-assignments-commitment-after]');
        const assignmentCommitmentMargin = form.querySelector('[data-assignments-commitment-margin]');
        const assignmentCommitmentPercentage = form.querySelector('[data-assignments-commitment-percentage]');
        const assignmentCommitmentExchangeNote = form.querySelector('[data-assignments-commitment-exchange-note]');
        const assignmentCommitmentNegative = form.querySelector('[data-assignments-commitment-warning-negative]');
        const assignmentCommitmentWarningList = form.querySelector('[data-assignments-commitment-warning-list]');
        const assignmentPersonInput = form.querySelector('#person_id');
        const assignmentClientInput = form.querySelector('#client_id');
        const assignmentMonthlyHoursInput = form.querySelector('#monthly_hours');
        const assignmentStatusInput = form.querySelector('#assignment_status_id');
        const assignmentCommitmentPreviewUrl = form.dataset.assignmentCommitmentPreviewUrl || '';
        const assignmentCurrentId = form.dataset.assignmentCurrentId || '';
        const assignmentHourlyInput = form.querySelector('#hourly_value');
        const assignmentProjectInput = form.querySelector('#project_value');
        const assignmentStartInput = form.querySelector('#start_date');
        const assignmentEndInput = form.querySelector('#end_date');
        const csrfToken = form.querySelector('input[name="_token"]')?.value || '';
        let assignmentCommitmentTimer = null;
        let assignmentCommitmentAbortController = null;

        const selectedAssignmentPersonOption = () => {
            if (!assignmentPersonSelect) {
                return null;
            }

            return assignmentPersonSelect.options[assignmentPersonSelect.selectedIndex] || null;
        };

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

        const projectRateText = (rateAmount, rateCurrencyCode, rateMinorUnits) => {
            if (rateAmount === null || rateAmount <= 0) {
                return '';
            }

            return `Valor HH contractual referencia: ${formatAssignmentMoney(rateAmount, rateCurrencyCode, Number.isNaN(rateMinorUnits) ? 0 : rateMinorUnits)} / HH`;
        };

        const personRateText = (rateAmount, rateUnitType, rateCurrencyCode, rateMinorUnits) => {
            if (rateAmount === null || rateAmount <= 0) {
                return '';
            }

            const currencyCode = String(rateUnitType || 'CURRENCY').toUpperCase() === 'UF'
                ? 'UF'
                : (rateCurrencyCode || 'CLP');

            return `Valor HH base Persona: ${formatAssignmentMoney(rateAmount, currencyCode, Number.isNaN(rateMinorUnits) ? (currencyCode === 'CLP' ? 0 : 2) : rateMinorUnits)} / HH`;
        };

        const assignmentSpecificRateMeta = () => {
            if (!rateUnitTypeField) {
                return { currencyCode: 'UF', decimals: 2 };
            }

            if (String(rateUnitTypeField.value || 'UF').toUpperCase() === 'UF') {
                return { currencyCode: 'UF', decimals: 2 };
            }

            const selectedOption = rateUnitSelector?.options?.[rateUnitSelector.selectedIndex];
            const currencyCode = String(selectedOption?.dataset?.currencyCode || 'CLP').toUpperCase();
            const decimals = Number.parseInt(selectedOption?.dataset?.currencyMinorUnits || (currencyCode === 'CLP' ? '0' : '2'), 10);

            return {
                currencyCode,
                decimals: Number.isNaN(decimals) ? (currencyCode === 'CLP' ? 0 : 2) : decimals,
            };
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

        const assignmentCommitmentText = (label, value) => `${label}: ${value}`;

        const renderAssignmentCommitmentPreview = (preview) => {
            if (!assignmentCommitmentBox) {
                return;
            }

            const sale = preview?.sale_net_clp;
            const saleContractual = preview?.sale_net_contractual;
            const saleCurrencyCode = String(preview?.sale_net_currency_code || 'CLP').toUpperCase();
            const current = preview?.current_personnel_committed_cost;
            const estimate = preview?.assignment_estimated_cost;
            const after = preview?.after_save_personnel_committed_cost;
            const margin = preview?.projected_personnel_margin;
            const percentage = preview?.committed_percentage;
            const negativeAmount = preview?.negative_margin_amount;
            const exchangeNote = preview?.exchange_rate_note;
            const warnings = Array.isArray(preview?.warnings)
                ? preview.warnings.filter((warning) => warning && warning !== 'El costo de personal comprometido supera la venta neta del proyecto.')
                : [];

            if (assignmentCommitmentSaleContractual) {
                assignmentCommitmentSaleContractual.textContent = assignmentCommitmentText('Venta contractual', saleContractual !== null && saleContractual !== undefined
                    ? formatAssignmentMoney(saleContractual, saleCurrencyCode, saleCurrencyCode === 'CLP' ? 0 : 2)
                    : 'No disponible');
            }

            if (assignmentCommitmentSaleEquivalent) {
                const equivalentText = saleCurrencyCode !== 'CLP' && sale !== null && sale !== undefined
                    ? `Equivalente para proyección: ${formatAssignmentMoney(sale, 'CLP', 0)}`
                    : '';
                assignmentCommitmentSaleEquivalent.textContent = equivalentText;
                assignmentCommitmentSaleEquivalent.classList.toggle('d-none', equivalentText === '');
            }

            if (assignmentCommitmentCurrent) {
                assignmentCommitmentCurrent.textContent = assignmentCommitmentText('Personal comprometido actualmente', current !== null && current !== undefined ? formatAssignmentMoney(current, 'CLP', 0) : 'No disponible');
            }

            if (assignmentCommitmentEstimate) {
                assignmentCommitmentEstimate.textContent = assignmentCommitmentText('Costo estimado de esta asignación', estimate !== null && estimate !== undefined ? formatAssignmentMoney(estimate, 'CLP', 0) : 'No disponible');
            }

            if (assignmentCommitmentAfter) {
                assignmentCommitmentAfter.textContent = assignmentCommitmentText('Compromiso después de guardar', after !== null && after !== undefined ? formatAssignmentMoney(after, 'CLP', 0) : 'No disponible');
            }

            if (assignmentCommitmentMargin) {
                assignmentCommitmentMargin.textContent = assignmentCommitmentText('Margen proyectado después de guardar', margin !== null && margin !== undefined ? formatAssignmentMoney(margin, 'CLP', 0) : 'No disponible');
            }

            if (assignmentCommitmentPercentage) {
                assignmentCommitmentPercentage.textContent = assignmentCommitmentText('Comprometido', percentage !== null && percentage !== undefined
                    ? `${new Intl.NumberFormat('es-CL', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(Number(percentage))} %`
                    : 'No disponible');
            }

        if (assignmentCommitmentExchangeNote) {
                const projectedExchangeNote = !exchangeNote && preview?.uses_projected_exchange_rate && preview?.exchange_rate_info?.value && preview?.exchange_rate_info?.value_date
                    ? `Proyección calculada con UF de referencia de ${formatAssignmentMoney(preview.exchange_rate_info.value, 'CLP', 0)} correspondiente al ${formatAssignmentDate(preview.exchange_rate_info.value_date)}, última UF oficial disponible.`
                    : '';
                assignmentCommitmentExchangeNote.textContent = exchangeNote || projectedExchangeNote;
                assignmentCommitmentExchangeNote.classList.toggle('d-none', !(exchangeNote || projectedExchangeNote));
            }

            if (assignmentCommitmentNegative) {
                const text = preview?.negative_margin && negativeAmount !== null && negativeAmount !== undefined
                    ? `El costo de personal comprometido superaría la venta neta del proyecto en ${formatAssignmentMoney(negativeAmount, 'CLP', 0)}. El proyecto quedaría con margen proyectado de personal negativo.`
                    : '';
                assignmentCommitmentNegative.textContent = text;
                assignmentCommitmentNegative.classList.toggle('d-none', text === '');
            }

            if (assignmentCommitmentWarningList) {
                assignmentCommitmentWarningList.innerHTML = warnings.map((warning) => `<li>${warning}</li>`).join('');
                assignmentCommitmentWarningList.classList.toggle('d-none', warnings.length === 0);
            }

            if (assignmentCommitmentWarningBox) {
                assignmentCommitmentWarningBox.classList.toggle('d-none', (!preview?.negative_margin && warnings.length === 0));
            }
        };

        const requestAssignmentCommitmentPreview = () => {
            if (!assignmentCommitmentPreviewUrl || !assignmentCommitmentBox) {
                return;
            }

            const personId = assignmentPersonInput?.value || '';
            const projectId = assignmentProjectSelect?.value || '';

            if (!personId || !projectId) {
                renderAssignmentCommitmentPreview({
                    sale_net_clp: null,
                    sale_net_contractual: null,
                    sale_net_currency_code: null,
                    current_personnel_committed_cost: null,
                    assignment_estimated_cost: null,
                    after_save_personnel_committed_cost: null,
                    projected_personnel_margin: null,
                    committed_percentage: null,
                    calculation_complete: false,
                    warnings: ['Seleccione una persona y un proyecto para estimar el compromiso.'],
                    negative_margin: false,
                    negative_margin_amount: null,
                });
                return;
            }

            const payload = new URLSearchParams();
            payload.set('_token', csrfToken);
            payload.set('person_id', personId);
            payload.set('client_id', assignmentClientInput?.value || '');
            payload.set('project_id', projectId);
            payload.set('assignment_status_id', assignmentStatusInput?.value || '');
            payload.set('hourly_rate_unit_type', rateUnitTypeField?.value || 'UF');
            payload.set('hourly_rate_currency_id', rateCurrencyField?.value || '');
            payload.set('hourly_value', assignmentHourlyInput?.value || '');
            payload.set('project_value', assignmentProjectInput?.value || '');
            payload.set('monthly_hours', assignmentMonthlyHoursInput?.value || '');
            payload.set('start_date', assignmentStartInput?.value || '');
            payload.set('end_date', assignmentEndInput?.value || '');
            payload.set('exclude_assignment_id', assignmentCurrentId);

            assignmentCommitmentAbortController?.abort();
            assignmentCommitmentAbortController = new AbortController();

            fetch(assignmentCommitmentPreviewUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: payload.toString(),
                signal: assignmentCommitmentAbortController.signal,
            })
                .then((response) => response.ok ? response.json() : Promise.reject(new Error(`preview-${response.status}`)))
                .then((preview) => renderAssignmentCommitmentPreview(preview))
                .catch((error) => {
                    if (error.name === 'AbortError') {
                        return;
                    }

                    renderAssignmentCommitmentPreview({
                        sale_net_clp: null,
                        sale_net_contractual: null,
                        sale_net_currency_code: null,
                        current_personnel_committed_cost: null,
                        assignment_estimated_cost: null,
                        after_save_personnel_committed_cost: null,
                        projected_personnel_margin: null,
                        committed_percentage: null,
                        calculation_complete: false,
                        warnings: ['No fue posible actualizar el compromiso del proyecto en este momento.'],
                        negative_margin: false,
                        negative_margin_amount: null,
                    });
                });
        };

        const scheduleAssignmentCommitmentPreview = () => {
            if (!assignmentCommitmentBox) {
                return;
            }

            window.clearTimeout(assignmentCommitmentTimer);
            assignmentCommitmentTimer = window.setTimeout(requestAssignmentCommitmentPreview, 180);
        };

        const syncAssignmentContext = () => {
            if (!assignmentProjectSelect || !assignmentProjectSaleNet) {
                return;
            }

            const option = assignmentProjectSelect.options[assignmentProjectSelect.selectedIndex];
            const personOption = selectedAssignmentPersonOption();
            const hasProject = Boolean(option?.value);
            const saleNetRaw = option?.dataset?.projectSaleNet || '';
            const saleCurrencyCode = option?.dataset?.projectSaleCurrencyCode || 'CLP';
            const saleMinorUnits = Number.parseInt(option?.dataset?.projectSaleMinorUnits || '0', 10);
            const saleNet = parseAssignmentNumber(saleNetRaw);
            const projectRateAmount = parseAssignmentNumber(option?.dataset?.projectRateAmount || '');
            const projectRateCurrencyCode = option?.dataset?.projectRateCurrencyCode || 'CLP';
            const projectRateMinorUnits = Number.parseInt(option?.dataset?.projectRateMinorUnits || '0', 10);
            const projectStartDate = option?.dataset?.projectStartDate || '';
            const projectEndDate = option?.dataset?.projectEndDate || '';
            const personRateAmount = parseAssignmentNumber(personOption?.dataset?.personRateAmount || '');
            const personRateUnitType = String(personOption?.dataset?.personRateUnitType || 'CURRENCY').toUpperCase();
            const personRateCurrencyCode = String(personOption?.dataset?.personRateCurrencyCode || (personRateUnitType === 'UF' ? 'UF' : 'CLP')).toUpperCase();
            const personRateMinorUnits = Number.parseInt(personOption?.dataset?.personRateMinorUnits || (personRateCurrencyCode === 'CLP' ? '0' : '2'), 10);
            assignmentProjectSaleNet.textContent = projectSaleText(saleNet, saleCurrencyCode, saleMinorUnits, hasProject);

            if (assignmentProjectRate) {
                const projectRateLabel = hasProject ? projectRateText(projectRateAmount, projectRateCurrencyCode, projectRateMinorUnits) : '';
                assignmentProjectRate.textContent = projectRateLabel;
                assignmentProjectRate.classList.toggle('d-none', projectRateLabel === '');
            }

            if (assignmentProjectVigency) {
                assignmentProjectVigency.textContent = projectVigencyText(projectStartDate, projectEndDate, hasProject);
            }

            const hourlyValue = parseAssignmentNumber(assignmentHourlyInput?.value);
            const projectValue = parseAssignmentNumber(assignmentProjectInput?.value);
            const assignmentRateMeta = assignmentSpecificRateMeta();
            const assignmentStartDate = parseChileanDate(assignmentStartInput?.value || '');
            const assignmentEndDate = parseChileanDate(assignmentEndInput?.value || '');
            const projectStart = projectStartDate ? new Date(`${projectStartDate}T00:00:00`) : null;
            const projectEnd = projectEndDate ? new Date(`${projectEndDate}T00:00:00`) : null;
            const hasBothValues = (hourlyValue ?? 0) > 0 && (projectValue ?? 0) > 0;
            const exceedsSaleNet = saleNet !== null && projectValue !== null && projectValue > saleNet;
            const vigencyWarning = assignmentVigencyWarningText(projectStart, projectEnd, assignmentStartDate, assignmentEndDate);

            if (assignmentHourlyReference) {
                const referenceLabel = personRateText(personRateAmount, personRateUnitType, personRateCurrencyCode, personRateMinorUnits);
                assignmentHourlyReference.textContent = referenceLabel ? `Referencia Persona: ${referenceLabel.replace('Valor HH base Persona: ', '')}` : '';
                assignmentHourlyReference.classList.toggle('d-none', referenceLabel === '');
            }

            if (assignmentHourlyEffective) {
                if ((hourlyValue ?? 0) > 0) {
                    assignmentHourlyEffective.textContent = `Efectivo: ${formatAssignmentMoney(hourlyValue, assignmentRateMeta.currencyCode, assignmentRateMeta.decimals)} / HH · Asignación`;
                } else if ((personRateAmount ?? 0) > 0) {
                    assignmentHourlyEffective.textContent = `Efectivo: ${formatAssignmentMoney(personRateAmount, personRateUnitType === 'UF' ? 'UF' : personRateCurrencyCode, Number.isNaN(personRateMinorUnits) ? (personRateCurrencyCode === 'CLP' ? 0 : 2) : personRateMinorUnits)} / HH · Persona`;
                } else {
                    assignmentHourlyEffective.textContent = 'Efectivo: No configurado';
                }
            }

            if (assignmentProjectValueEffective) {
                assignmentProjectValueEffective.textContent = projectValue === null
                    ? 'Efectivo: No informado'
                    : `Efectivo: ${formatAssignmentMoney(projectValue, assignmentRateMeta.currencyCode, assignmentRateMeta.decimals)} · Asignación`;
            }

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

            scheduleAssignmentCommitmentPreview();
        };

        const assignmentReactiveFieldIds = new Set([
            'person_id',
            'project_id',
            'hourly_value',
            'project_value',
            'monthly_hours',
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

        assignmentPersonSelect?.addEventListener('change', syncAssignmentContext);
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
        rateUnitSelector?.addEventListener('change', syncAssignmentContext);
        syncAssignmentContext();
    })();
</script>
@endpush
