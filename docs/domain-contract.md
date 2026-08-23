# Domain Contract

Referencia funcional persistente basada únicamente en reglas confirmadas por código y tests.

## Proyecto

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `sale_net` | `projects.sale_net` | No aplica | Sí | No | `>= 0` y escala monetaria según moneda del proyecto | Proyecto, rentabilidad, compromiso proyectado, ventas |
| `contracted_hourly_rate` | `projects.contracted_hourly_rate` | No aplica | Sí | No | `>= 0` y moneda comercial del proyecto | Referencia comercial del proyecto, prefacturación/ventas HH cuando el flujo comercial lo usa |
| `start_date` / `end_date` | `projects.start_date` / `projects.end_date` | No aplica | Sí | No | `end_date >= start_date` cuando ambas existen | Asignaciones, vistas operativas |
| `sales_currency_id` | `projects.sales_currency_id` | CLP/base company cuando corresponde al alta | Sí | No | Moneda válida de la empresa | Venta contractual, conversión de `sale_net`, servicios comerciales |

## Personal

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `hourly_value` | `people.hourly_value` | No aplica | Sí | No | `>= 0` | Fallback de costeo de Asignaciones, remuneración por hora, UI de Personal |
| `hourly_rate_unit_type` / `hourly_rate_currency_id` | `people.hourly_rate_unit_type` / `people.hourly_rate_currency_id` | CLP/CURRENCY cuando el flujo lo normaliza | Sí | No | Deben ser compatibles con la moneda configurada | Conversión de tarifa de Personal, remuneración por hora, fallback de costeo |
| `monthly_value` | `people.monthly_value` | No aplica | Sí | No | `>= 0` | Remuneración mensual |
| `employment_mode_id` / `contract_type_id` | Catálogos laborales de la persona | No aplica | Sí | No | Deben existir en la empresa | Payroll, remuneración y reglas laborales |

## Asignaciones

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `hourly_value` | `project_assignments.hourly_value` | `people.hourly_value` para costeo cuando está vacío | Sí | Sí, como valor específico de costeo por proyecto | `>= 0` | ProjectCommitmentService, Horas (valor HH de costeo), vistas operativas |
| `hourly_rate_unit_type` / `hourly_rate_currency_id` | `project_assignments.hourly_rate_unit_type` / `project_assignments.hourly_rate_currency_id` | Unidad/currency de Personal solo cuando el valor específico está vacío y el servicio usa fallback | Sí | Sí, junto al valor específico | Deben ser compatibles con la moneda configurada | Costeo por asignación, remuneración por proyecto/hito |
| `project_value` | `project_assignments.project_value` | No usa `sale_net` como fallback | Sí | No como concepto separado; es el dato específico de la asignación | `>= 0` | Remuneración por proyecto/hito |
| `monthly_hours` | `project_assignments.monthly_hours` | No aplica | Sí | No | `0 <= monthly_hours <= 744` | Compromiso/costeo proyectado, planificación operativa |
| `start_date` / `end_date` | `project_assignments.start_date` / `project_assignments.end_date` | No aplica | Sí | No | `required_with` entre fechas y `end_date >= start_date` | Vigencia de Asignaciones, ProjectCommitmentService, vistas |

## Horas

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `hours_worked` | `time_entries.hours_worked` | No aplica | Sí | No | `> 0` y suma diaria `<= 24` | Ejecución real, productividad, controles operativos |
| `hours_approved` | `time_entries.hours_approved` | No aplica | Sí | No | `0 <= hours_approved <= hours_worked` | Remuneraciones, costo real, productividad |
| `hourly_value` | Valor HH de costeo resuelto para la entrada | `assignment.hourly_value`, luego `person.hourly_value` | Derivado en el flujo actual | No | Depende de la fuente resuelta | Cálculo del monto de la hora registrada, UI de Horas |
| `person_id` / `project_id` / `assignment_id` | Relación operativa de la entrada | No aplica | Sí | No | Deben mantener integridad persona-proyecto-asignación | Ejecución real, remuneraciones, costo real |

## Remuneraciones

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `hours_approved` | Horas aprobadas del período desde Horas | Ajustes explícitos solo si existen en Novedades remuneración | Derivado en Edit/Show | Solo cuando un ajuste de remuneración lo define explícitamente | `>= 0` | Payroll, snapshots de remuneración |
| `hourly_value` | Tarifa de remuneración por hora desde Personal/contrato | No usa automáticamente `assignment.hourly_value` ni `project.contracted_hourly_rate` como tarifa de pago | Depende del flujo de payroll | Sí, si el override existe explícitamente en la remuneración | `>= 0` | Payroll por hora |
| `project_value` | `assignment.project_value` convertido según la moneda vigente | No aplica | Depende del flujo de payroll | Sí, si el override existe explícitamente en la remuneración | `>= 0` | Payroll por proyecto/hito |
| `monthly_value` | `people.monthly_value` | No aplica | Depende del flujo de payroll | Sí, si el override existe explícitamente en la remuneración | `>= 0` | Payroll mensual |
| `employer_cost` / `net_pay` | Snapshot calculado del período | No aplica | No como dato fuente | No | Debe derivarse del cálculo del período | Rentabilidad real, costo real, caja/pagos |

## Rentabilidad

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `personnel_committed_cost` | `ProjectCommitmentService` | No aplica | No | No | No sustituye costo real ni presupuesto | Proyecto, rentabilidad proyectada |
| `projected_personnel_margin` | `sale_net_clp - personnel_committed_cost` | No aplica | No | No | Si falta información, el cálculo queda incompleto | Proyecto, rentabilidad proyectada |
| `real_cost` | Remuneraciones, Horas reales y egresos según el servicio vigente | No aplica | No | No | No mezclar con compromiso | Rentabilidad real |
| `real_margin` | Venta real/facturación real vs costo real | No aplica | No | No | No mezclar con proyectado | Rentabilidad real |
| `ProjectCommitmentService` UF proyectada | Última UF oficial disponible cuando la fecha de proyección es futura y aún no existe UF oficial exacta | No aplica | No | No | Solo planificación proyectada; no altera Payroll ni transacciones reales | Compromiso de personal, rentabilidad proyectada |

## Presupuesto

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `personnel_budget` y demás componentes | `budgets.*` | No aplica | Sí | No | `>= 0` | Presupuesto, planificación, variaciones |

## Flujo de caja

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| Caja real/proyectada | `CashFlowService` y movimientos de caja | No aplica | Según el origen del movimiento | No | No confundir con rentabilidad ni presupuesto | Tesorería, dashboard, escenarios |

## Relaciones confirmadas

| Relación | Regla confirmada |
|---|---|
| `assignment.hourly_value` → costeo de proyecto | Prevalece como valor HH específico de costeo del proyecto. |
| Fallback de costeo de Asignación | Si `assignment.hourly_value` está vacío, el fallback confirmado es `people.hourly_value`. |
| `project.contracted_hourly_rate` | Es referencia comercial/contractual del proyecto. No es fallback individual de costo de Asignación ni tarifa automática de remuneración. |
| `assignment.project_value` | Pertenece a la Asignación y se usa para remuneración por proyecto/hito. `sale_net` no es su fallback. |
| `assignment.monthly_hours` | Pertenece a la Asignación. No existe límite de horas confirmado a nivel Proyecto. |
| ProjectCommitmentService | Calcula costo comprometido de personal desde HH comprometidas y valor HH de costeo; no depende de la modalidad de payroll y no usa `project_value` como base del compromiso. |
| ProjectCommitmentService temporalidad mensual | Un intervalo desde una fecha hasta la misma fecha del mes siguiente equivale exactamente a un mes de compromiso; la fecha término no genera un período parcial adicional. |
| Horas | Representa ejecución real. No es compromiso ni presupuesto. |
| Remuneraciones | Es snapshot/costo real del período. No es compromiso ni presupuesto. |
| Costo HH real | Se obtiene desde costo empresa real del período / horas productivas aprobadas. No equivale al valor HH base de Persona ni al valor HH de costeo de la Asignación. |
| Presupuesto | Es planificación manual y no reemplaza compromiso ni costo real. |
| Cash Flow | Representa timing de caja y no debe mezclarse con compromiso, presupuesto o costo real. |
| `sale_net` | Es referencia contractual/comercial de venta del Proyecto. |
| Compromiso, costo real, presupuesto y caja | Son conceptos separados y no deben sumarse indiscriminadamente. |

## Reglas deliberadamente no incorporadas

- No se documenta una herencia automática de `project.contracted_hourly_rate` hacia el costo individual de una persona.
- No se documenta que `project_value` determine el costo comprometido por HH del proyecto.
- No se documenta límite de horas a nivel Proyecto porque no existe campo confirmado para ello.
- No se documenta una equivalencia entre valor HH de costeo, tarifa de remuneración y costo HH real.
- No se documenta ninguna limpieza retroactiva global de datos históricos; solo reclasificación segura cuando un valor coincide inequívocamente con una fuente automática conocida.
