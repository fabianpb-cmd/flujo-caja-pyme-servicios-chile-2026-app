# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-07.

ÚNICA fuente de continuidad. No crear otro handoff. No repetir tareas cerradas salvo incidente, nueva release o evidencia nueva.

## Producción

- URL: `https://licitaciones.tdatconsulting.cl`
- APP_ROOT: `/home/tdatcons/apps/flujo-caja-staging`
- PUBLIC_ROOT: `/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`
- BD productiva: `tdatcons_flujo_stg`
- `.env`, APP_KEY, credenciales, `storage/`, `vendor/` y `public/` preservados.
- Rama histórica `production-20260905` sigue en `f18c368065bf134412e12d3ae87d17bb058772a2`; no mover sin instrucción explícita.

## Estado de BD

Payroll:
- unique empresa/persona/período presente;
- migración correspondiente registrada con `batch=3`.

Hitos de facturación:
- `project_billing_milestones` existe;
- `sales_documents.project_billing_milestone_id` existe;
- unique `(project_id, sequence)` presente;
- índice `sales_docs_milestone_idx` presente;
- FKs company/project/sales document verificadas;
- migración `2026_09_06_000500_create_project_billing_milestones` registrada con `batch=4`;
- `MAX(batch)=4`.

**No ejecutar más DDL/SQL para hitos.** El patch actual no requiere migraciones nuevas.

## Lógica funcional cerrada previa

- Integridad transaccional financiera y períodos cerrados: PASS.
- Movimientos de caja: selector dependiente; `Otro` con referencia libre; estados solo `Borrador` y `Contabilizado`.
- Payroll: horas/tarifa derivadas, vigencia laboral, unicidad y flujo `Borrador -> Confirmado -> Parcial/Pagado`.
- Seguridad/IDOR/campos controlados por servidor: QA dirigida PASS.
- Money/percent UX general desplegado y validado: PASS.

## Estrategia de facturación — diseño autoritativo

Todo proyecto debe tener estrategia comercial reconocida:

- `Proyecto cerrado` => 1..N hitos de facturación porcentuales sobre `projects.sale_net` + moneda de venta.
- `Por Hora` => HH aprobadas no facturadas × `projects.contracted_hourly_rate` comercial; nunca usar tarifas de costo de Persona/Asignación/TimeEntry.
- Otros tipos => no inventar estrategia; impedir facturación automática.
- Condición de pago es distinta de estrategia de facturación: determina vencimiento posterior a la emisión.

Proyecto cerrado:
- monto de cada hito derivado: `sale_net * percentage / 100`;
- suma nunca >100%; proyecto vigente exige 100%;
- hito facturado inmutable/no eliminable;
- facturación por hito no consume HH;
- factura convierte monto contractual a CLP a la fecha de emisión y conserva snapshot;
- cobertura acumulada usa facturas de hitos realmente emitidas + hito actual, no secuencia ni revalorización histórica;
- emisión revalida dentro de transacción con lock para evitar doble factura concurrente.

Por Hora:
- `contracted_hourly_rate` obligatorio y separado del costo del consultor;
- todas las HH del documento usan tasa/UF de `issue_date`;
- `conversion_date` de líneas = `issue_date`;
- suma de subtotales CLP coincide con neto antes de ajuste, resolviendo residual de redondeo en última línea.

## Implementación aprobada y desplegada

Commit funcional base del rediseño:
- `fe7a358d6c5e2ef2d16a76472fef45c3e34469d4` — `feat: centralize project billing strategies`.

Archivos subidos manualmente a producción:
- `app/Http/Controllers/OperationalCrudController.php`
- `app/Services/BillingStrategyService.php`
- `app/Services/ProjectBillingMilestoneService.php`
- `app/Services/SalesPrefacturationService.php`
- `config/operational.php`
- `resources/views/operational/form.blade.php`
- `resources/views/operational/show.blade.php`

`bootstrap/cache/config.php` **no existe** en producción; no se borró ningún cache.

Validación local relevante antes de deploy:
- `ProjectBillingMilestoneServiceTest`: PASS, 7 tests / 22 assertions.
- `SalesPrefacturationTest`: PASS, 11 tests / 32 assertions.
- `ProjectBillingPlanHttpTest`: PASS, 2 tests / 14 assertions.
- HTTP CREATE Proyecto cerrado + hitos: PASS.
- rollback plan >100%: PASS.
- HTTP UPDATE/sync hitos no facturados: PASS.
- `git diff --check`: PASS.

## UX porcentajes de hitos

Commit:
- `4eac04d0b4f23c1d26840c67d313a73c96b0824c` — `fix: improve billing milestone percentage feedback`.

Solo modifica `resources/views/operational/form.blade.php`, ya subido manualmente a producción.

Comportamiento:
- porcentajes mostrados en formato humano;
- total programado y pendiente se recalculan en vivo;
- si total >100%, muestra warning de exceso y deshabilita `Guardar`;
- backend conserva validación autoritativa.

### Smoke visual productivo — PASS 2026-09-06

Usuario confirmó:
- escenario `30 / 40 / 30` => total programado `100%`, pendiente `0%`, Guardar habilitado: PASS;
- escenario temporal `30 / 40 / 40` => total `110%`, warning de exceso `10%`, Guardar deshabilitado: PASS;
- escenario inválido no se persistió.

Estado UX porcentajes: **CERRADO / PASS**.

## Datos QA productivos a mantener hasta cierre

Proyecto `Alerta Matrículas` (`PRY-000012`), cliente DuocUC:
- tipo contrato: Proyecto cerrado;
- venta neta: UF 180;
- IVA 19%; total UF 214,20;
- plan actual QA: 30% / 40% / 30% => UF 54 / UF 72 / UF 54;
- existen HH aprobadas históricas usadas para validar separación ingreso/costo.

No limpiar estos datos hasta cerrar el smoke funcional.

## Hallazgo fechas de facturación — 2026-09-06

Antes de emitir en producción se revisó la lógica de fechas y se detectaron brechas reales. **Pausar el smoke de emisión hasta corregir/probar estas reglas.**

Hallazgos:
- `planned_invoice_date` de hitos solo valida ser fecha; no existe regla server-side de orden cronológico por `sequence` ni coherencia del plan.
- El detalle del proyecto precarga `issue_date` con `planned_invoice_date` cuando existe. Esto confunde fecha planificada con fecha real de emisión y puede generar una factura histórica/futura por un clic si no se corrige manualmente.
- La ruta de emisión de hito valida `issue_date` solo como `date`; hoy permite fechas futuras sin regla funcional.
- `ProjectBillingMilestoneService::issue()` usa `issue_date` para UF/tasa e IVA, pero no calcula `due_date` ni `projected_collection_date` desde la condición de pago del proyecto/cliente.
- `PaymentTerm` posee `days`; `Project` y `Client` poseen relación `paymentTerm`, por lo que la información existe pero no se usa al generar borradores por hito.
- Prefacturación Por Hora recibe `period` e `issue_date` independientes. Hoy no impide que `issue_date` sea anterior a HH incluidas en el documento ni define explícitamente la coherencia con la periodicidad mensual.

Reglas recomendadas para implementar con tests antes de continuar:
- `planned_invoice_date` es fecha planificada, no fecha real; en el detalle mostrarla aparte y precargar la fecha real de emisión con `hoy`, no con la fecha planificada.
- Rechazar `issue_date` futura para documentos reales/borradores de factura.
- No bloquear emisión temprana/tardía respecto de `planned_invoice_date`; permitir desviación porque la fecha planificada es forecast. Conservar ambas fechas y, si se desea, mostrar diferencia como warning informativo.
- Para plan de hitos activo, exigir fechas planificadas y orden no decreciente por `sequence`; no imponer que la última fecha sea <= `project.end_date` porque aceptación/facturación final puede ocurrir después del término operativo.
- `due_date = issue_date + payment_term.days`, usando primero condición del Proyecto y fallback al Cliente. Si ninguna existe, dejar `due_date` nula y advertir/configurar, no inventar días.
- `projected_collection_date` debe seguir `due_date` inicialmente salvo que exista una regla explícita distinta.
- En Por Hora mensual, impedir facturar HH cuya `entry_date` sea posterior a `issue_date`. Definir con test si además se exige `issue_date >= fin del período`; recomendación actual: sí, mientras la modalidad siga declarada como `Mensual`, para evitar incluir horas futuras del mismo mes.
- Conversión UF/moneda continúa usando exclusivamente `issue_date`; nunca planned/due/entry date.

Próximo paso: TDD focalizado de fechas, sin suite completa, sin deploy y sin tocar producción. Ejecutar tests rojos primero, corrección mínima y luego PASS. Actualizar este mismo HANDOFF con resultados.

## Pendiente inmediato

**Smoke funcional de emisión PAUSADO por hallazgo de fechas.** No repetir suite completa ni UAT amplio.

1. Implementar y ejecutar set focalizado de pruebas automatizadas de fechas para Proyecto cerrado + Por Hora.
2. Corregir únicamente las reglas reveladas por esos tests.
3. Revisar diff una vez; después deploy incremental solo de archivos afectados.
4. Recién entonces retomar emisión controlada del Hito 1 de `Alerta Matrículas`.

Antes de cualquier nueva operación riesgosa o cambio de código, checkpoint aquí. Ante incidente, revisar primero `storage/logs` y comparar contra `main`.

## Continuidad Git

Los checkpoints documentales se realizan directamente en `main`; antes de volver a trabajar localmente/Codex ejecutar `git fetch origin` y rebasear sobre `origin/main` si corresponde. No usar force push.

Prompt de continuidad entre cuentas:
`Lee docs/HANDOFF.md del repositorio y continúa desde el estado actual. No repitas tareas ya completadas.`

## Corrección de semántica de fechas — validación local 2026-09-06

Patch local de fechas de facturación completado y validado.

Reglas implementadas:
- planned_invoice_date permanece como fecha planificada/forecast y no se reutiliza como fecha real de emisión.
- En Proyecto cerrado vigente, los hitos requieren fecha prevista y mantienen orden cronológico no decreciente por secuencia.
- issue_date futura es rechazada.
- Un hito puede emitirse antes o después de su fecha prevista; la conversión UF/FX usa exclusivamente issue_date.
- due_date se calcula desde la condición de pago del Proyecto y, si falta, desde la del Cliente.
- projected_collection_date se inicializa con due_date.
- Sin condición de pago no se inventan días; vencimiento/proyección quedan nulos.
- En Por Hora, primero se valida la estrategia: CLOSED_PROJECT es rechazado antes de aplicar reglas mensuales.
- Para Por Hora mensual, issue_date debe ser al menos el cierre del período y no se permiten HH posteriores a la fecha de emisión.
- Conversión de todas las líneas HH continúa usando issue_date.

Validación focalizada:
- BillingDateRulesTest: 13 tests / 22 assertions — PASS.
- ProjectBillingMilestoneServiceTest: 7 tests / 22 assertions — PASS.
- SalesPrefacturationTest: 11 tests / 32 assertions — PASS.
- git diff --check: PASS.
- Sin migraciones nuevas.
- Producción todavía NO contiene este patch.

Estado: **PATCH DE FECHAS VALIDADO LOCALMENTE / PUSH Y DEPLOY PENDIENTES**.

## Revisión estática post-push de fechas — BLOQUEADOR 2026-09-06

Commit revisado en `main`: `f0096a74733a5b537cb45565c4d6126adb5b0c3a`.

Hallazgo: `BillingStrategyService::validateProject()` comprueba la cronología de `planned_invoice_date` en el orden en que llegan las filas del formulario, no en el orden definido por `sequence`. Por lo tanto, un payload con filas fuera de orden puede aceptar un plan cronológicamente inválido por secuencia o rechazar uno válido. La regla autoritativa es que las fechas deben ser no decrecientes **al ordenar por `sequence`**, independientemente del orden físico de las filas enviadas.

El set `BillingDateRulesTest` actual no cubre explícitamente payloads con filas desordenadas por `sequence`.

Estado: **NO DESPLEGAR `f0096a7` TODAVÍA**. Corrección mínima requerida: validar fechas sobre una copia de los hitos ordenada por `sequence` y agregar tests focalizados que cubran (a) payload desordenado pero cronología válida por secuencia => PASS y (b) payload desordenado con cronología inválida por secuencia => rechazo controlado. No repetir suite completa; ejecutar solo `BillingDateRulesTest`, y por seguridad `ProjectBillingMilestoneServiceTest` si cambia la validación compartida. Sin migraciones.

## Corrección orden cronológico de hitos — 2026-09-06

Corregido el bloqueador detectado en BillingStrategyService::validateProject().

La cronología de planned_invoice_date ahora se valida según sequence, independientemente del orden físico de las filas recibidas.

Cobertura agregada:
- payload desordenado con cronología válida por sequence: PASS;
- payload desordenado con cronología inválida por sequence: rechazo controlado.

Validación:
- BillingDateRulesTest: 15 tests / 25 assertions — PASS.
- ProjectBillingMilestoneServiceTest: 7 tests / 22 assertions — PASS.
- git diff --check: PASS.
- Sin migraciones nuevas.
- Producción todavía NO contiene este patch.

Estado: **BLOQUEADOR DE ORDEN DE FECHAS CORREGIDO / PUSH Y DEPLOY PENDIENTES**.

## Deploy productivo patch de fechas — 2026-09-07

Commit funcional acumulado en `main`: `2601bd1631888b3ec08890a77abf28a2ccdf22e4`.

Usuario confirmó upload manual a producción de exactamente estos 4 archivos:
- `app/Services/BillingStrategyService.php`
- `app/Services/ProjectBillingMilestoneService.php`
- `app/Services/SalesPrefacturationService.php`
- `resources/views/operational/show.blade.php`

No se subieron tests ni documentación; no hay migraciones ni SQL para este patch. Producción queda con las reglas de fecha y orden cronológico desplegadas.

Estado: **PATCH DE FECHAS SUBIDO / SMOKE PRODUCTIVO NO DESTRUCTIVO PENDIENTE**. No emitir facturas hasta completar este smoke. Verificar primero en `Alerta Matrículas`: (1) detalle muestra `Fecha prevista:` separada de fecha de emisión; (2) el input de emisión precarga la fecha actual; (3) al intentar guardar el plan existente con fechas cronológicamente inconsistentes se obtiene error controlado y no se persiste ningún cambio. Después corregir las fechas previstas usando el calendario comercial real, no fechas inventadas, antes de retomar la emisión controlada.

## Corrección CSP resumen plan de facturación — 2026-09-07

Smoke productivo posterior al patch de fechas:
- detalle muestra Fecha prevista: separada de la fecha de emisión: PASS;
- fecha de emisión precargada con la fecha actual: PASS;
- plan visible de Alerta Matrículas: 05/08/2026, 20/08/2026, 01/10/2026, cronológicamente ordenado.

Hallazgo UX:
- Editar Proyecto mostraba Total programado: 0% y Pendiente: 100% pese a tener 30/40/30.
- causa confirmada: el script de data-project-billing-plan no tenía nonce CSP y era bloqueado por el navegador.
- corrección: agregar el nonce CSP al script específico del plan, sin cambiar su lógica.

Validación:
- ProjectBillingPlanHttpTest::test_billing_plan_percentage_script_has_csp_nonce: 1 test / 3 assertions — PASS.
- git diff --check: PASS.
- Sin migraciones.
- Producción todavía NO contiene esta corrección CSP.

Estado: **FIX CSP VALIDADO LOCALMENTE / PUSH Y DEPLOY PENDIENTES**.

## Confirmación explícita de facturas — patch local

Hallazgo: la edición genérica podía forzar implícitamente `Borrador -> Pendiente`. Se implementó `POST operational.sales-documents.confirm` (`/{record}/emitir`) con `SalesDocumentService::confirm()`, transacción, lock, validación de tipo/número/fecha, auditoría y transición exclusiva `Borrador -> Pendiente`. El update genérico conserva el status actual y el detalle muestra `Emitir factura` solo para borradores no anulados.

Validación: `SalesDocumentConfirmationTest` — 4 tests / 14 assertions — PASS; sin migraciones ni SQL. Producción aún no contiene este fix; `ING-000007` y Hito 2 permanecen intactos. Push y deploy pendientes.

Cobertura ampliada de confirmación de facturas: preservación de IVA, vencimientos, proyección, origen, snapshot y hito; ausencia de vínculos HH; edición de documentos Pendiente; y bloqueo por movimiento de caja `posted`. `SalesDocumentConfirmationTest` queda en 6 tests / 26 assertions — PASS. Sin migraciones ni SQL; producción aún no contiene este fix.

## Deploy CSP + smoke productivo final — 2026-09-07

Commit funcional desplegado: `95fb757a10e814ae41457fd26d1dac0edcec851a` — `fix: apply csp nonce to billing plan script`.

Usuario confirmó upload manual a producción de solo:
- `resources/views/operational/form.blade.php`

Smoke productivo del resumen porcentual reportado **OK** después del upload. Quedan también confirmados los checks previos de fechas: `Fecha prevista` separada de emisión, fecha real precargada con la fecha actual y plan de `Alerta Matrículas` cronológicamente ordenado (`05/08/2026`, `20/08/2026`, `01/10/2026`).

No hubo migraciones ni SQL. No se subieron tests ni documentación a producción.

Estado: **PATCH DE FECHAS + FIX CSP DESPLEGADOS / SMOKE NO DESTRUCTIVO CERRADO PASS**.

Próximo paso autorizado técnicamente: retomar una emisión controlada del Hito 1 de `Alerta Matrículas`. Al crear el borrador, verificar antes de avanzar que monto contractual, conversión a CLP, `issue_date`, `due_date`, `projected_collection_date`, IVA y vínculo al hito sean coherentes; confirmar además que no se consumieron HH por tratarse de `Proyecto cerrado`. No ejecutar acciones adicionales irreversibles sin validación.

## Emisión controlada Hito 1 — checkpoint 2026-09-07

Se generó en producción el borrador `ING-000007` desde el Hito 1 de `Alerta Matrículas` con fecha de emisión `07/09/2026`.

Valores observados en pantalla:
- cliente DuocUC;
- proyecto `Alerta Matrículas`;
- emisión `07/09/2026`;
- vencimiento `07/10/2026`;
- cobro proyectado `07/10/2026`;
- neto `$ 2.207.682`;
- IVA `19%`, monto IVA `$ 419.460`;
- total `$ 2.627.142`;
- estado `Borrador`;
- anulado `No`;
- N° documento vacío, esperado mientras es borrador.

La conversión observada equivale exactamente a UF 54,00 × `$40.883,00` por UF, consistente con el valor UF del `07/09/2026`.

**Nuevo hallazgo bloqueador antes de continuar:** `Tipo de documento` aparece vacío. La causa de código es consistente con una desalineación entre el campo legacy y el catálogo: `ProjectBillingMilestoneService::issue()` escribe `document_type = 'Factura hito'`, pero la ficha de Facturas/Ingresos muestra y exige `document_type_id`; el catálogo de ventas contiene `FACTURA` / `Factura`. La tabla conserva ambos campos (`document_type` y `document_type_id`).

Estado: **BORRADOR ING-000007 CREADO / IMPORTES Y FECHAS PASS / TIPO DE DOCUMENTO CATALOGADO PENDIENTE**. No editar, cobrar, anular ni emitir otro hito hasta corregir y validar `document_type_id`. El vínculo al hito y la ausencia de consumo HH aún deben verificarse como parte del cierre de este smoke.

## Corrección tipo de documento en facturación por hito — 2026-09-07

Se corrigió la emisión automática por hito para asignar el catálogo de ventas
FACTURA en document_type_id, manteniendo compatibilidad con el campo legacy.

Si el catálogo FACTURA de ventas no existe, la emisión falla controladamente y
no crea el documento.

Validación:
- ProjectBillingMilestoneServiceTest: 9 tests / 26 assertions — PASS.
- git diff --check: PASS.
- Sin migraciones nuevas.
- ING-000007 productivo permanece intacto.
- Producción todavía NO contiene esta corrección.

Estado: **FIX DOCUMENT_TYPE VALIDADO LOCALMENTE / PUSH Y DEPLOY PENDIENTES**.

## Cierre productivo Hito 1 — 2026-09-07

Commit funcional desplegado: `9a05d11d78434f00dd21297fa533b6174b645d67` — `fix: catalog milestone invoice document type`.

Upload manual a producción confirmado de solo:
- `app/Services/ProjectBillingMilestoneService.php`

Corrección puntual de datos aplicada en `tdatcons_flujo_stg` sobre el borrador existente `ING-000007`:
- `document_type_id` pasó de `NULL` a `1` (`FACTURA` / `Factura`);
- exactamente 1 fila afectada;
- `project_billing_milestone_id = 1`;
- estado permanece `Borrador`;
- neto `2207682.00`, IVA `419459.58`, total `2627141.58` sin cambios.

Verificación final de trazabilidad:
- `billing_source = PROJECT_MILESTONE`;
- hito vinculado: secuencia `1`, nombre `hito 1`;
- `linked_time_entries = 0`;
- `linked_hours = 0.0000`.

Conclusión: la emisión del Hito 1 de `Alerta Matrículas` quedó correctamente vinculada al hito, con tipo documental catalogado, fechas/montos correctos y sin consumir HH. La corrección de código evita que nuevas facturas por hito queden sin `document_type_id`.

Estado: **SMOKE PRODUCTIVO HITO 1 CERRADO / PASS**. No emitir Hito 2 ni registrar cobros/estados adicionales sin una validación funcional separada. Antes de cualquier nuevo cambio local ejecutar `git fetch origin` y rebasear sobre `origin/main` si corresponde, ya que este checkpoint documental se escribió directamente en `main`.
