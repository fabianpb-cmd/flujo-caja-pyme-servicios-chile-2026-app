# Domain Contract

Referencia funcional persistente basada únicamente en reglas ya confirmadas por código y tests.

## Proyecto

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `sale_net` | `projects.sale_net` | No aplica | Sí | No | `>= 0` y escala monetaria por moneda del proyecto | Rentabilidad, caja, compromiso proyectado, ventas, reportes de proyecto |
| `contracted_hourly_rate` | `projects.contracted_hourly_rate` | No aplica | Sí | No | `>= 0` y tarifa contractual por moneda del proyecto | Asignaciones, Horas, Remuneraciones, rentabilidad proyectada |
| `start_date` / `end_date` | `projects.start_date` / `projects.end_date` | No aplica | Sí | No | `end_date >= start_date` cuando ambas existen | Asignaciones, Horas, UI operativa |
| `project_status` | Estado del proyecto | No aplica | Sí | No | Catálogo de estado del dominio | Filtros operativos, UI, reglas de vigencia |

## Asignaciones

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `hourly_value` | `project_assignments.hourly_value` | `projects.contracted_hourly_rate` cuando está vacío | Sí | Sí, como valor específico de la asignación | `>= 0` | Horas, Remuneraciones, rentabilidad proyectada, UI operativa |
| `project_value` | `project_assignments.project_value` | No usa `sale_net` como fallback | Sí | Sí, como valor específico de la asignación | `>= 0` | Remuneraciones, rentabilidad proyectada, UI operativa |
| `monthly_hours` | `project_assignments.monthly_hours` | No aplica | Sí | No | `0 <= monthly_hours <= 744` | UI operativa, validación de integridad |
| `start_date` / `end_date` | `project_assignments.start_date` / `project_assignments.end_date` | No aplica | Sí | No | `required_with` entre fechas y `end_date >= start_date` | Horas, remuneraciones, UI operativa |
| `client_id` / `project_id` / `person_id` | Relación directa de la asignación | No aplica | Sí | No | Deben pertenecer al ámbito de empresa y mantener integridad relacional | Horas, Remuneraciones, UI operativa, integridad de dependencias |

## Horas

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `hours_worked` | Registro transaccional de la entrada de horas | No aplica | Sí | No | `> 0` en captura normal | Costo horario, prefacturación, controles operativos |
| `hours_approved` | Registro transaccional de la entrada de horas aprobadas | No aplica | Sí | No | `0 <= hours_approved <= 24` por registro | Remuneraciones, rentabilidad, costo horario, prefacturación, control operativo |
| `person_id` / `project_id` / `assignment_id` | Relación operativa de la entrada de horas | No aplica | Sí | No | Debe existir relación válida persona-proyecto-asignación y pertenecer a la empresa | Remuneraciones, costo horario, prefacturación, rentabilidad |
| `entry_date` | Fecha del registro de horas | No aplica | Sí | No | Debe caer dentro de la vigencia operativa aplicable | Remuneraciones, prefacturación, rentabilidad |

## Remuneraciones

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `hours_approved` | Snapshot o valor derivado del período según la lógica de payroll | Fuente automática de Horas cuando corresponde | Depende del flujo vigente | Solo si el formulario lo contempla explícitamente | `>= 0` y no puede superar horas trabajadas cuando aplica | Payroll, costo horario, rentabilidad, reportes de remuneración |
| `hourly_value` | Tarifa efectiva según la lógica de payroll | `project_assignments.hourly_value` o `projects.contracted_hourly_rate` según precedencia ya confirmada | Depende del flujo vigente | Sí, si existe override explícito en la remuneración | `>= 0` | Cálculo de remuneración, costo horario, rentabilidad |
| `project_value` | Valor efectivo de la remuneración por proyecto/hito | `project_assignments.project_value` cuando corresponde | Depende del flujo vigente | Sí, si existe override explícito en la remuneración | `>= 0` | Cálculo de remuneración, rentabilidad |
| `monthly_value` | Valor mensual de la persona o remuneración, según el flujo vigente | Fuente automática de payroll cuando aplica | Depende del flujo vigente | Sí, si existe override explícito en la remuneración | `>= 0` | Cálculo de remuneración, rentabilidad |
| `employer_cost` / `net_pay` | Resultado del cálculo de payroll del período | No aplica | No como fuente primaria | No como regla general | Debe derivarse de datos válidos del período | Rentabilidad, caja, reportes, dashboard |

## Rentabilidad

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `sale_net` | Venta contractual del proyecto | No aplica | Sí, en Proyecto | No | `>= 0` | Rentabilidad, compromiso proyectado, dashboard |
| `costo real` | Horas, remuneraciones y egresos reales ya registrados | No aplica | No | No | No se mezcla con compromiso ni presupuesto | Rentabilidad, dashboard, análisis histórico |
| `costo comprometido` | Asignaciones del proyecto mediante su costo económico proyectado | No aplica | No | No | No se suma como costo real | Rentabilidad proyectada, detalle de proyecto, alertas operativas |
| `margen proyectado` | `sale_net - costo comprometido` | No aplica | No | No | Si `sale_net = 0`, debe tratarse sin división inválida | Rentabilidad proyectada, detalle de proyecto, alertas operativas |
| `margen real` | Venta real vs costo real | No aplica | No | No | No mezclar con compromiso | Rentabilidad histórica, dashboard |

## Presupuesto

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `revenue_budget` | `budgets.revenue_budget` | No aplica | Sí | No | `>= 0` | Presupuesto, variaciones, planificación |
| `personnel_budget` | `budgets.personnel_budget` | No aplica | Sí | No | `>= 0` | Presupuesto, variaciones, planificación |
| `other_direct_budget` | `budgets.other_direct_budget` | No aplica | Sí | No | `>= 0` | Presupuesto, variaciones, planificación |
| `legal_budget` | `budgets.legal_budget` | No aplica | Sí | No | `>= 0` | Presupuesto, variaciones, planificación |
| `other_indirect_budget` | `budgets.other_indirect_budget` | No aplica | Sí | No | `>= 0` | Presupuesto, variaciones, planificación |
| `total_budget` | Suma de campos de presupuesto según servicio vigente | No aplica | No como fuente primaria | No | No mezclar con costo real o comprometido | Presupuesto, variaciones, reportes de planificación |

## Flujo de caja

| Campo | Fuente de verdad | Fallback | Editable | Override | Límite | Consumidores |
|---|---|---|---|---|---|---|
| `opening_balance` | Caja real histórica | No aplica | No como cálculo operativo | No | Se calcula por fecha de corte | Cash Flow, dashboard, cierres |
| `income_real` / `expense_real` | Movimientos de caja posteados | No aplica | Sí en el origen del movimiento | No | Depende del movimiento real | Cash Flow, cierres, dashboard |
| `net_real` | Ingresos reales menos egresos reales y costos reales del período | No aplica | No | No | No mezclar con presupuesto o compromiso | Cash Flow, dashboard |
| `net_projected` | Proyección separada del flujo de caja | No aplica | No | No | No sustituye caja real | Cash Flow, escenarios, dashboard |

## Relaciones confirmadas

| Relación | Regla confirmada |
|---|---|
| `assignment.hourly_value` vs `project.contracted_hourly_rate` | `assignment.hourly_value` prevalece; `project.contracted_hourly_rate` es fallback dinámico, no tarifa única por persona. |
| `assignment.project_value` vs `project.sale_net` | `project_value` pertenece a la Asignación; `sale_net` no es su fallback. |
| `assignment.monthly_hours` vs Proyecto | `monthly_hours` pertenece a la Asignación; no existe límite de horas a nivel Proyecto. |
| Horas | Es ejecución real/transaccional, no compromiso ni presupuesto. |
| Remuneraciones | Es snapshot/costo real del período, no presupuesto ni caja. |
| Budget | Es planificación manual. |
| Cash Flow | Representa timing de caja, separado de rentabilidad y presupuesto. |
| Costo comprometido, costo real, presupuesto y caja | Son conceptos separados y no deben sumarse indiscriminadamente. |
| `sale_net` | Es la referencia contractual de venta del Proyecto. |

## Reglas deliberadamente no incorporadas

- No se documenta como confirmada una herencia automática de `sale_net` hacia `project_value`.
- No se documenta un límite de horas a nivel Proyecto.
- No se documenta una única fórmula universal para costos monetarios porque la precedencia depende de la modalidad y del servicio consumidor ya existente.
- No se documenta que todos los overrides sean obligatorios o que existan en todos los formularios; solo se reconoce cuando la UI o el servicio lo exponen.
- No se documenta una equivalencia entre costo comprometido y costo real.
