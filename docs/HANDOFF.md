# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-06.

ÚNICA fuente de continuidad. No repetir tareas cerradas salvo incidente, nueva release o evidencia nueva.

## Estado productivo

- Producción: `https://licitaciones.tdatconsulting.cl`
- APP_ROOT: `/home/tdatcons/apps/flujo-caja-staging`
- PUBLIC_ROOT: `/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`
- Release funcional base desplegada: `f18c368065bf134412e12d3ae87d17bb058772a2`
- Marcador histórico: rama `production-20260905` -> `f18c368065bf134412e12d3ae87d17bb058772a2`
- `.env`, APP_KEY, credenciales, `storage/`, `vendor/` y `public/` preservados.
- Backups frescos previos al deploy principal: COMPLETADOS.
- BD productiva: ALINEADA/PASS; migración Payroll unique registrada con `batch=3` y unique index presente.

## Cambios funcionales cerrados

- Integridad transaccional financiera y períodos cerrados: PASS.
- Movimientos de caja: selector dependiente; `Otro` con referencia libre; estados solo `Borrador` y `Contabilizado`.
- Payroll: horas/tarifa derivadas, vigencia laboral, unicidad empresa/persona/período y flujo `Borrador -> Confirmado -> Parcial/Pagado`.
- Seguridad/IDOR/campos controlados por servidor: QA dirigida PASS.
- Smoke de la release principal: PASS.

## Limpieza de datos QA

Se eliminaron los datos operacionales de prueba preservando empresa, usuario(s), migraciones, parámetros legales/económicos, catálogos, geografía, escenarios y configuración base. Verificación manual previa: tablas operacionales principales en `0` filas.

## Ajuste UX money/percent — desplegado y validado 2026-09-06

Hallazgo: inputs editables mostraban números crudos (`306688,00`, `1.000000`) y los montos editables no mostraban unidad monetaria.

Corrección en `resources/views/operational/partials/field-input.blade.php`:
- `money` editable usa formato `es-CL` y separador de miles;
- muestra prefijo/unidad: `$`, `US$`, `€`, `UF` o código correspondiente;
- resuelve moneda desde `currency`, `currency_relation` o `currency_field`, con CLP por defecto;
- `presentation => percent` muestra porcentaje humano (`1` -> `100 %`);
- antes del submit normaliza dinero a número crudo y porcentaje a fracción para mantener backend/BD sin cambios.

Commits funcionales del ajuste:
- `186a21c3782bb1387b386b5897efaba5de1aca55` — money + percent
- `a085f2f1d187c2b15b79f6ce528cf045f0e8ef43` — prefijo/unidad monetaria

Validación productiva:
- deploy manual del partial actualizado: PASS;
- smoke visual: PASS;
- prueba mínima de persistencia/submit sin modificar valores: PASS;
- al reabrir, Neto/moneda, Probabilidad, IVA y Total conservaron valores/formato esperados.

Estado del ajuste: **CERRADO / PASS**.

## Hallazgo crítico pendiente — base de prefacturación en proyecto cerrado (2026-09-06)

Caso QA observado:
- proyecto `Alerta Matrículas`, tipo de contrato `Proyecto cerrado`;
- venta neta contractual: `UF 180`;
- persona/asignación con tarifa de costeo cercana a `UF 0,77 / HH`;
- 9,75 h aprobadas del período;
- factura generada `ING-000006` con neto aproximado `CLP 306.688`.

Causa raíz confirmada por revisión de código:
- `SalesPrefacturationService::calculate()` construye el neto sumando líneas de horas aprobadas (`subtotal_clp`) y no usa `projects.sale_net` como base contractual.
- `SalesPrefacturationService::lineForEntry()` obtiene la tarifa mediante `HourlyRateService::resolveForEntry()`.
- `HourlyRateService::resolveForTimeEntry()` prioriza `project_assignments.hourly_value` cuando es > 0; solo si no existe usa `projects.contracted_hourly_rate`.
- Ese `project_assignments.hourly_value` es tarifa de costeo y hoy puede terminar reutilizado como tarifa de facturación.

Estado: **BUG DE LÓGICA DE NEGOCIO CONFIRMADO / NO CERRAR PROYECTO TODAVÍA**.

## Diseño acordado — hitos de facturación para Proyecto cerrado

Objetivo: separar definitivamente ingreso contractual de costo de personal y soportar uno o más hitos de facturación con porcentaje del total del proyecto.

Reglas funcionales acordadas:
- `projects.sale_net` + moneda de venta son la fuente contractual para `Proyecto cerrado`.
- Cada proyecto cerrado puede tener 1..N hitos con: nombre/descripción, fecha prevista, porcentaje y orden.
- El monto del hito NO se ingresa manualmente: se calcula `sale_net * porcentaje / 100` en la moneda contractual.
- La suma de hitos puede quedar temporalmente bajo 100% y se mostrará `% pendiente por programar`; nunca puede superar 100%.
- Un hito facturado no se puede editar ni borrar.
- Si existe facturación activa de hitos, se bloquea el cambio retroactivo de `sale_net` y moneda del proyecto.
- La factura de un hito usa el monto contractual del hito y lo convierte a CLP a la fecha de emisión con los servicios de conversión/parámetros existentes. La factura conserva snapshot de porcentaje, monto contractual, moneda, tasa/UF y fecha de conversión.
- La facturación por hito NO consume ni vincula horas como base de ingreso; las horas permanecen como trazabilidad/costo.
- Si una factura de hito es anulada, debe poder reemitirse el hito; la aplicación debe impedir más de una factura activa no anulada por hito.

Warning de cobertura/caja:
- No bloquear la facturación si el hito no cubre los costos devengados.
- El warning debe comparar de forma **acumulada** la facturación contractual acumulada hasta el hito vs. el costo referencial acumulado de HH aprobadas del proyecto hasta la fecha del hito/emisión. Esto evita falsos warnings en hitos posteriores.
- Costo referencial de HH aprobadas: usar la ruta de costeo (asignación/persona), nunca la tarifa comercial del proyecto. Debe poder calcularse aun si Payroll aún no está confirmado/calculable.
- Mensaje esperado si cobertura negativa: indicar facturación acumulada, costo HH aprobado acumulado y brecha, aclarando que puede implicar financiar/adelantar pago al consultor hasta hitos futuros y que no bloquea el proceso.
- Este warning es de timing/cobertura, no altera el margen contractual final.

Separación con remuneraciones:
- Los hitos de facturación al cliente NO deben crear/modificar automáticamente el `Monto pactado remuneración por proyecto/hito` del consultor.
- Facturación del cliente y pago del consultor son conceptos distintos. El warning solo informa cobertura; no acopla ambos flujos.

Contratos por hora:
- Mantener flujo por HH separado.
- La tarifa comercial por HH debe usar `projects.contracted_hourly_rate` + moneda de venta; nunca `project_assignments.hourly_value`/tarifa de costeo.
- El modelo `Project` ya posee `contracted_hourly_rate`; falta exponer/usar correctamente este valor en el flujo comercial.

Diseño técnico mínimo recomendado:
- nueva tabla `project_billing_milestones` (`company_id`, `project_id`, `sequence`, `name`, `planned_invoice_date`, `percentage`, `notes`, timestamps);
- nuevo FK nullable `project_billing_milestone_id` en `sales_documents` para trazabilidad histórica y reemisión tras anulación;
- monto del hito derivado, no persistido, para evitar divergencia mientras no esté facturado;
- snapshot contractual en `sales_documents.billing_snapshot` al emitir;
- servicio dedicado pequeño para plan/hitos + rama `PROJECT_MILESTONE` en `SalesPrefacturationService`;
- UI aislada en detalle de Proyecto (`Plan de facturación`) para no complejizar el CRUD genérico.

## Implementación de hitos de facturación — 2026-09-06

Implementado y pusheado a `main`, **NO desplegado a producción**:
- `e6f5d77` — `feat: add closed-project billing milestones`;
- `829fb4e` — `fix: scope milestone actions to company`.

Incluye:
- migración `2026_09_06_000500_create_project_billing_milestones` y FK opcional desde `sales_documents`;
- `ProjectBillingMilestoneService` para porcentajes, inmutabilidad, conversión/snapshot contractual, reemisión y cobertura;
- `Plan de facturación` aislado en detalle del Proyecto;
- emisión por hito sin enlaces a HH;
- protección de venta neta/moneda con factura activa de hito;
- rutas de hitos con control de pertenencia a empresa.

Validación local reportada por Work/Codex: sintaxis PHP, migración en simulación, rutas, `SalesPrefacturationTest` (8 PASS) y pruebas HH focalizadas de `FinancialCoreTest` (4 PASS). No se ejecutó suite completa.

## Revisión post-push — BLOQUEADORES ANTES DE DEPLOY (2026-09-06)

Revisión estática de `main` tras `829fb4e`: **NO desplegar todavía**. Se requiere una corrección final pequeña y focalizada.

1. **La facturación HH aún puede reutilizar tarifa de costeo.** Aunque `HourlyRateService::resolveForTimeEntry()` dejó de priorizar la asignación, `SalesPrefacturationService::lineForEntry()` conserva fallback a `$assignment->hourly_value` / `$entry->hourly_value` cuando no existe `projects.contracted_hourly_rate`. Esto viola la separación comercial/costo y hace que los 8 tests existentes sigan pasando con la lógica antigua. Debe eliminarse ese fallback y exigir tarifa comercial del proyecto para facturación HH.
2. **Proyecto cerrado aún puede entrar al flujo de prefacturación por HH.** `SalesPrefacturationService::calculate()` no bloquea contratos cerrados. Debe rechazar de forma controlada un Proyecto cerrado e indicar que se factura mediante hitos.
3. **Cobertura acumulada sobreestima ingresos si hay hitos anteriores programados pero no facturados.** `coverage()` suma todos los hitos con `sequence <= actual` reconvertidos a la fecha actual. Debe sumar netos CLP de facturas de hitos activas ya emitidas + el hito que se está emitiendo, respetando snapshots históricos. No asumir que un hito programado está facturado ni revalorizar facturas anteriores con UF/tasa actual.
4. **Riesgo de doble factura concurrente del mismo hito.** `isInvoiced()` se valida antes de abrir la transacción. Dos solicitudes simultáneas podrían pasar el check. En `issue()` se debe abrir transacción, bloquear el hito (`lockForUpdate`), recargar/revalidar factura activa y recién crear el documento.
5. **Fecha de emisión no seleccionable en UI.** El botón `Facturar` envía `issue_date = now()` oculto. Como la fecha determina UF/tasa, la UI debe mostrar un campo de fecha explícito (por defecto fecha prevista si corresponde o hoy) antes de emitir.
6. **Secuencia duplicada puede terminar en error SQL/500.** Existe unique `(project_id, sequence)`, pero falta validación/control antes de guardar. Validar unicidad por proyecto excluyendo el hito actual y devolver error funcional.
7. **Plan visible en proyectos no cerrados.** El detalle renderiza el plan para todo proyecto aunque el servicio rechaza guardar. Mostrar `Plan de facturación` solo cuando el contrato sea Proyecto cerrado.
8. **Las pruebas reportadas no prueban los hitos.** No se agregó ningún test nuevo en los dos commits. `SalesPrefacturationTest` existente todavía espera facturación desde `assignment.hourly_value`, lo que confirma que la separación HH no quedó cubierta. Agregar SOLO pruebas focalizadas para: hito UF 180 30/40/30; >100%; emisión UF54 sin HH; cobertura basada en facturas reales + actual; no consumo HH; reemisión tras anulación; doble emisión serializada/revalidada; proyecto cerrado bloqueado en prefacturación HH; contrato HH exige `contracted_hourly_rate` y nunca usa costeo.

Recomendación de costo mínimo: una sola iteración correctiva sobre estos puntos, ejecutar únicamente el nuevo test de hitos + tests HH actualizados. No suite completa, no UAT amplio, no deploy. Tras PASS, revisar diff final una vez y desplegar migración + archivos afectados.

Mantener los datos QA actuales (`Alerta Matrículas` / Jaime Soriano / UF 180) hasta validar el fix final.

## Política de pruebas / continuidad

## Corrección de bloqueadores de prefacturación — 2026-09-06

Implementada localmente y **NO DESPLEGADA / PRODUCCIÓN NO TOCADA**:
- HH comercial usa exclusivamente `projects.contracted_hourly_rate` + `projects.salesCurrency`; desaparecieron los fallbacks de asignación/TimeEntry.
- `Proyecto cerrado` queda excluido de prefacturación HH y debe usar Plan de facturación / hitos.
- Cobertura acumula facturas de hitos anteriores activas por su neto histórico, más el hito actual convertido a su fecha de emisión; hitos no facturados no cuentan.
- Emisión de hitos bloquea el registro con `lockForUpdate()` y revalida factura activa dentro de la transacción.
- La vista muestra fecha de emisión editable, prellenada con fecha prevista o fecha actual.
- Secuencias duplicadas devuelven error funcional antes del índice SQL.
- Plan de facturación solo se muestra para contratos `Proyecto cerrado`.

Tests focalizados ejecutados:
- `php artisan test tests/Feature/ProjectBillingMilestoneServiceTest.php` — PASS.
- `php artisan test tests/Feature/SalesPrefacturationTest.php` — PASS.

Archivos relevantes: `app/Services/SalesPrefacturationService.php`, `app/Services/ProjectBillingMilestoneService.php`, `app/Http/Controllers/OperationalCrudController.php`, `resources/views/operational/show.blade.php`, `tests/Feature/SalesPrefacturationTest.php`, `tests/Feature/ProjectBillingMilestoneServiceTest.php`.

Pasos mínimos posteriores de deploy: aplicar la migración de hitos antes del código, desplegar los archivos relevantes, limpiar cache de configuración/rutas/vistas y ejecutar smoke de hitos/HH. No se ejecutó deploy.

No repetir UAT, suite completa, QA de seguridad ni smoke ya cerrados. Reabrir solo ante incidente real, nueva release, cambio de esquema/código o evidencia nueva.

Ante incidente: revisar primero `storage/logs`; comparar código contra la release productiva correspondiente.
Antes de cualquier nueva release o DDL: backup fresco de BD y archivos afectados.
Para continuidad en otra cuenta: `Lee docs/HANDOFF.md y continúa desde el estado actual. No repitas tareas ya completadas.`
