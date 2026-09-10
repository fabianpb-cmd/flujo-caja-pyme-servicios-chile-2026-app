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

## Deploy confirmaci�n expl�cita de facturas � 2026-09-07

Commit funcional desplegado:
`006ac05b3293575c2cb6b991fa86e41aae7bb9ba` � `fix: add explicit sales invoice confirmation`.

Upload manual a producci�n confirmado de exactamente:
- `app/Http/Controllers/OperationalCrudController.php`
- `app/Services/SalesDocumentService.php`
- `routes/web.php`
- `resources/views/operational/show.blade.php`

No se subieron tests ni documentaci�n. Sin migraciones y sin SQL.

La edici�n gen�rica de Facturas/Ingresos ya no transforma impl�citamente `Borrador -> Pendiente`. La transici�n se realiza �nicamente mediante la acci�n dedicada `Emitir factura`.

Validaci�n local previa:
- `SalesDocumentConfirmationTest`: 6 tests / 26 assertions � PASS.
- `git diff --check`: PASS.

Producci�n contiene el c�digo, pero todav�a NO se ha ejecutado la nueva acci�n sobre `ING-000007`.

Estado: **FIX CONFIRMACI�N FACTURA DESPLEGADO / SMOKE PRODUCTIVO PENDIENTE**. Antes de emitir `ING-000007`, verificar visualmente bot�n, estado Borrador y completar de forma controlada el N� de documento. No emitir Hito 2.

## UX emisi�n con N� documento � validaci�n local 2026-09-07

Se corrigi� la UX de confirmaci�n de Facturas/Ingresos para permitir ingresar o reutilizar `document_number` directamente en el flujo dedicado de `Emitir factura`, sin pasar por la edici�n gen�rica.

La operaci�n guarda `document_number` y realiza la transici�n `Borrador -> Pendiente` dentro de la misma transacci�n y bajo lock. Si falla una validaci�n, no se persiste el n�mero ni cambia el estado.

Se preservan montos, IVA, fechas, billing_source, billing_snapshot, v�nculo al hito y ausencia de v�nculos HH.

Validaci�n focalizada:
- `SalesDocumentConfirmationTest`: 7 tests / 30 assertions � PASS.
- `git diff --check`: PASS.
- Sin migraciones.
- Sin SQL.
- Producci�n todav�a NO contiene este ajuste UX.
- `ING-000007` permanece en Borrador en producci�n.
- Hito 2 no tocado.

Estado: **UX EMISI�N CON N� DOCUMENTO VALIDADA LOCALMENTE / PUSH Y DEPLOY PENDIENTES**.

## Cierre productivo UX emisi�n de factura � 2026-09-07

Commit funcional desplegado:
`b35314be942610d45ec0bafc6b999ce366081035` � `fix: collect invoice number during confirmation`.

Upload manual confirmado de:
- `app/Http/Controllers/OperationalCrudController.php`
- `app/Services/SalesDocumentService.php`
- `resources/views/operational/show.blade.php`

Smoke productivo sobre `ING-000007`:
- campo N� documento visible junto a Emitir factura: PASS;
- emisi�n sin n�mero fue rechazada y mantuvo Borrador: PASS;
- n�mero QA utilizado: `QA-0001`;
- emisi�n con n�mero: PASS;
- estado `Borrador -> Pendiente`: PASS;
- tipo Factura preservado;
- fechas y montos preservados;
- bot�n Emitir factura desaparece despu�s de confirmar.

Sin migraciones ni SQL para este ajuste.

Estado: **FLUJO DE EMISI�N HITO 1 CERRADO / PASS**. Pr�ximo paso: validar ciclo de cobro de `QA-0001` antes de emitir Hito 2.

## UX de cobros y pagos — patch local

Se corrigió la etiqueta Fecha de cobro/pago en Movimientos de caja, el formateo humano de Ingreso/Egreso usa el componente monetario existente sin cambiar el valor numérico enviado, y Probabilidad NULL se muestra como 100 % solo en presentación, sin escribir 100 en BD. Tests: CashMovementUxTest + SalesDocumentConfirmationTest, 9 tests / 36 assertions PASS; git diff --check PASS. Sin migraciones ni SQL. Producción todavía NO contiene este ajuste; ING-000007 / QA-0001 quedó Pagado en producción y Hito 2 no fue tocado.

## Revisión post-push: money inputs localizados - 2026-09-07

La revisión detectó que el evento focus todavía mostraba los montos en formato crudo (2627141.58) aunque el valor inicial y el blur estaban localizados. Se corrigió únicamente el componente money genérico para conservar formato es-CL en focus y blur, manteniendo la normalización numérica al enviar el formulario. CashMovementUxTest: 1 test / 6 assertions PASS; git diff --check PASS. Sin migraciones ni SQL. Producción todavía NO contiene esta corrección.

## UX moneda y probabilidad - patch local 2026-09-07

La revisión confirmó que payment_probability NULL seguía vacío en edición y que el componente money no respetaba minor_units. El componente ahora presenta CLP con 0 decimales y otras monedas según minor_units, manteniendo normalización numérica al submit. En sales-documents, NULL se muestra como 100 % mediante un valor visual no persistible si no se modifica. CashMovementUxTest: 1 test / 7 assertions PASS; SalesDocumentConfirmationTest: 8 tests / 32 assertions PASS; git diff --check PASS. Sin migraciones ni SQL. Producción todavía NO contiene este ajuste.

## Preservación de probabilidad NULL en edición - 2026-09-07

Se corrigió el bloqueador por el que payment_probability NULL se mostraba como 100 pero se enviaba al backend como 100. El input visual se excluye del submit mientras no sea modificado y recupera su nombre al editarlo; así NULL permanece NULL y un valor explícito como 0.75 se conserva. También se evitó refrescar estados de SalesDocument en Borrador durante una edición genérica, necesario para completar el flujo sin convertirlo implícitamente a Pendiente. SalesDocumentConfirmationTest: 11 tests / 42 assertions PASS; sin migraciones ni SQL; producción todavía NO contiene este ajuste.

## Cierre UX cobros, moneda y probabilidad - producción 2026-09-07

Deploy productivo completado y smoke PASS sobre los ajustes acumulados hasta 74b85bf.

Validado en producción:
- Movimientos de caja muestra Fecha de cobro/pago.
- Campos monetarios respetan la precisión de la moneda; CLP se presenta sin decimales.
- payment_probability NULL se presenta como 100 %.
- Guardar una factura sin modificar la probabilidad visual no altera el NULL persistido.
- Sin migraciones ni SQL.

Estado: **UX COBROS / MONEDA / PROBABILIDAD CERRADO EN PRODUCCIÓN / PASS**.

## Revisión cobranza y estado de facturación de proyecto - 2026-09-07

El ciclo SalesDocument Pendiente -> Parcial -> Pagado queda cubierto y PASS por CashMovementSourceDocumentSelectorTest: cobros 400/600, collected_amount y saldo se actualizan, el documento parcial sigue disponible y el documento Pagado desaparece del selector; el sobrepago/selección inválida permanece rechazado.

billing_status_id de Project no se deriva automáticamente de hitos, SalesDocument ni CashMovement. El catálogo domain=billing contiene Pendiente, Parcial y Pagado, pero el campo se mantiene por CRUD/sincronización de catálogo/importación. No se implementó cambio: para un proyecto cerrado 30/40/30, el estado de cobranza de Hito 1 no debe marcar el proyecto completo como Pagado; se recomienda definir posteriormente una semántica agregada de plan facturado separada de cobranza antes de emitir Hito 2. Hito 2 sigue sin tocarse.

Tests ejecutados: CashMovementSourceDocumentSelectorTest::test_cash_movements_use_functional_codes_allow_partial_and_total_payments_and_validate_invalid_documents, 1 test / 15 assertions PASS; git diff --check PASS. Sin cambios funcionales, migraciones ni SQL.

## Cobertura automatizada Hito 2 - 2026-09-07

Se agregó `ProjectBillingMilestoneServiceTest::test_issue_hito_two_uses_its_contractual_amount_and_preserves_plan_integrity`. La prueba construye un proyecto cerrado UF 180 con hitos 30/40/30, emite exclusivamente el Hito 2, valida UF 72 a CLP 3.600.000 usando UF 50.000 de la issue_date 2026-09-02, IVA 19 %, total CLP 4.284.000, due/projected 2026-10-02, estado Borrador, trazabilidad y ausencia de documentos/Hito 1/Hito 3, HH links, CashMovements y cambios en billing_status_id. Resultado: 1 test / 20 assertions PASS. Hito 2 productivo todavía no emitido; sin SQL ni migraciones.

## Validación generación borrador Hito 2 - 2026-09-07

Se amplió `ProjectBillingMilestoneServiceTest::test_issue_hito_two_uses_its_contractual_amount_and_preserves_plan_integrity` con validación HTTP del detalle del SalesDocument generado. Confirma estado Borrador, document_number NULL permitido y visible en detalle, manteniendo issue/due/projected dates, trazabilidad del Hito 2, aislamiento de Hitos 1/3, ausencia de HH/CashMovements y billing_status_id. Resultado: 1 test / 24 assertions PASS. Sin producción, SQL ni migraciones; Hito 2 productivo sigue sin emitir.

## UX selector de cobros y moneda - 2026-09-07

Se corrigió la etiqueta de documentos origen para incluir código, N° documento cuando existe, cliente, proyecto, saldo y estado, omitiendo limpiamente el N° cuando es NULL. La precarga de Ingreso/Egreso usa metadata de moneda/minor_units del proyecto; CLP se presenta sin decimales y el valor se mantiene normalizable al backend. El residual 0,44 observado es presentación de un saldo CLP, no un cambio contable. CashMovementSourceDocumentSelectorTest: 5 tests / 72 assertions PASS; CashMovementUxTest: 1 test / 7 assertions PASS; git diff --check PASS. Sin migraciones ni SQL, producción no tocada.

## Selector de cobranza: moneda de liquidación del documento - 2026-09-09

Se confirmó en esquema y servicios que SalesDocument no tiene moneda propia y que net_amount, IVA, gross_amount, collected_amount y balance son importes monetarios CLP. El selector ya no usa la moneda contractual UF del proyecto: etiqueta, saldo sugerido, currency_code, minor_units y prefijo usan CLP; el valor se mantiene interno y normalizable sin reconversión. También incluye N° documento cuando existe y omite el segmento cuando es NULL. CashMovementSourceDocumentSelectorTest: 5 tests / 72 assertions PASS; CashMovementUxTest: 1 test / 7 assertions PASS; git diff --check PASS. Sin SQL ni migraciones; producción no tocada.

## Corrección final de prefijo monetario en selector de cobranza - 2026-09-09

La revisión confirmó que el cambio anterior actualizaba currency_code y minor_units, pero no el span visible del prefijo. Se añadió actualización explícita de [data-money-currency-prefix="true"] al seleccionar cada documento, con CLP=$, USD=US$, EUR=€, UF=UF y fallback al código; el valor sugerido mantiene minor_units. CashMovementSourceDocumentSelectorTest: 5 tests / 74 assertions PASS; CashMovementUxTest: 1 test / 7 assertions PASS; git diff --check PASS. Sin SQL ni migraciones; producción no tocada.

## Precisión monetaria CLP en facturas de hitos - 2026-09-09

Se confirmó la causa: SalesDocument almacena liquidación CLP sin currency_id; ProjectBillingMilestoneService convierte UF a CLP, pero ReceivablesService redondeaba neto/IVA/bruto/saldo a 2 decimales. Ahora esos importes usan UiFormatter::roundAmount(..., CLP), manteniendo columnas decimal(2) sin migración. Regresión focalizada: UF 72 convertido a net CLP 2.943.576, IVA 559.279, gross 3.502.855; cobro 1.000.000 deja Parcial/saldo 2.502.855, sobrepago 2.502.856 se rechaza y pago exacto deja Pagado/saldo 0. ProjectBillingMilestoneServiceTest: 11 tests / 59 assertions PASS; CashMovementSourceDocumentSelectorTest: 5 tests / 74 assertions PASS; git diff --check PASS. Documentos históricos no fueron modificados; detectar fracciones con consultas posteriores antes de reparar. Sin SQL ni migraciones; producción no tocada.

## Cierre productivo incidente precisión CLP - 2026-09-09

Causa raíz: ReceivablesService permitía centavos en IVA/bruto de SalesDocument CLP, generando saldos residuales menores a $1. Fix desplegado: `cd45034d15cd84e82d1fd8ccda349d6c971199b0` (`fix: enforce clp precision on milestone invoices`), con ReceivablesService redondeando según precisión CLP.

Reparación controlada ejecutada únicamente sobre `ING-000008` / `QA-0002`: SQL correctivo de una fila ajustó `vat_amount` de 559279.44 a 559279.00, `gross_amount` de 3502855.44 a 3502855.00 y estado a `Pagado`. Resultado UPDATE: exactamente 1 fila afectada. Verificación final: net 2943576.00, IVA 559279.00, gross 3502855.00, collected 3502855.00, saldo 0.00, estado `Pagado`.

`MOV-000011` permaneció posted e intacto. `ING-000007` permaneció intacto. No hubo migraciones. Pendiente: auditoría histórica de otros SalesDocument CLP con fracciones y saldos residuales antes de cualquier reparación.

Estado: **INCIDENTE PRECISIÓN CLP CERRADO / AUDITORÍA HISTÓRICA PENDIENTE**.

## Checkpoint previo reparación histórica CLP - 2026-09-09

Fix funcional desplegado: `cd45034d15cd84e82d1fd8ccda349d6c971199b0`. SalesDocument liquida en CLP y usa precisión CLP entera.

Reparación productiva ya realizada únicamente sobre `ING-000008` / `QA-0002`: residual histórico $0,44; `vat_amount` 559279.44 -> 559279.00; `gross_amount` 3502855.44 -> 3502855.00; `collected_amount` quedó 3502855.00; `Parcial` -> `Pagado`; UPDATE afectó exactamente 1 fila; saldo final 0.00. `MOV-000011` e `ING-000007` no fueron modificados.

Auditoría histórica previa: `ING-000006` / documento 1, `billing_source=TIME_ENTRIES`, `project_billing_milestone_id=NULL`, issue_date 2026-09-03, net 306688.00, IVA histórico 58270.72, gross histórico 364958.72, collected 0, estado `Pendiente`. El snapshot ya contiene net=306688, IVA=58271, gross=364959. Existen 20 vínculos HH, 9.75 horas y subtotal_clp total 306688.

Reparación planificada para `ING-000006`: modificar únicamente `vat_amount` y `gross_amount`; no tocar snapshot, HH, estado ni otros documentos. Este checkpoint es previo a esa reparación controlada.

## Cierre final intervención productiva precisión CLP - 2026-09-09

Causa raíz: los SalesDocument liquidados en CLP permitían centavos históricos en IVA/bruto por redondeo a 2 decimales, generando inconsistencias visuales y saldos residuales menores a $1. El fix `cd45034d15cd84e82d1fd8ccda349d6c971199b0` corrigió ReceivablesService para usar precisión CLP entera y ya está desplegado. No requiere migraciones.

Reparación controlada `ING-000008` / `QA-0002`: `vat_amount` 559279.44 -> 559279.00 y `gross_amount` 3502855.44 -> 3502855.00; `collected_amount` 3502855.00 quedó sin cambio; `Parcial` -> `Pagado`; saldo final 0.00; UPDATE afectó exactamente 1 fila. `MOV-000011` e `ING-000007` no fueron modificados.

Reparación controlada `ING-000006` (id 6, documento 1, TIME_ENTRIES, sin hito): snapshot correcto net=306688, IVA=58271, gross=364959; 20 vínculos HH, 9.75 horas aprobadas y subtotal_clp 306688. Se ajustó únicamente `vat_amount` 58270.72 -> 58271.00 y `gross_amount` 364958.72 -> 364959.00; net_amount 306688.00, collected_amount 0.00 y estado `Pendiente` permanecieron sin cambio. Snapshot y vínculos HH no fueron modificados. UPDATE afectó exactamente 1 fila.

`ING-000007` se preservó: histórico pagado, gross y cobro coinciden exactamente y saldo real 0; no se modificó por ser consistente. Incidente de precisión CLP cerrado. Sin migraciones y sin cambios adicionales de código.

## Cobertura automatizada Hito 3 - 2026-09-09

Se agrego `test_issue_hito_three_is_independent_and_can_be_confirmed_without_changing_prior_milestones` en `ProjectBillingMilestoneServiceTest`. La prueba construye localmente un proyecto cerrado UF 180 con plan 30/40/30, emite Hitos 1 y 2, emite de forma independiente el Hito 3 con fecha prevista 2026-10-01 y emision real 2026-09-09, y confirma el borrador mediante el flujo existente.

Resultado: PASS, 1 test y 33 assertions. Hito 3 queda en UF 54, neto CLP 2700000, IVA 513000 y bruto 3213000; due/projected 2026-10-09. Se preservan Hitos 1 y 2, no se crean vinculos HH ni movimientos de caja y `billing_status_id` permanece sin cambios. No se tocaron datos productivos, no hay SQL ni migraciones nuevas.

## UX plan cerrado: lectura al completar facturacion - 2026-09-10

El detalle de Proyecto cerrado ahora muestra `Editar plan` mientras exista al menos un hito sin factura activa. Cuando todos los hitos estan facturados, muestra `Ver plan` y mantiene el plan en el detalle como solo lectura, sin formularios ni controles de mantenimiento.

Pruebas focalizadas nuevas: `test_partially_invoiced_plan_keeps_edit_plan_action` y `test_fully_invoiced_plan_is_read_only_and_exposes_only_ver_plan`: PASS, 2 tests / 8 assertions. La proteccion server-side de hitos facturados permanece intacta. Sin cambios de facturacion, documentos, SQL ni migraciones; produccion no fue tocada.

## Cierre productivo: plan de facturacion completado en solo lectura - 2026-09-10

El cambio `4a9db02` (`fix: show completed billing plan read only`) fue validado en produccion para el proyecto cerrado con todos sus hitos facturados. El detalle muestra `Ver plan`, ya no muestra `Editar plan`, mantiene visible el plan y los hitos con estado `Facturado`, sin inputs ni controles de modificacion.

No se ejecutaron SQL ni migraciones, no hubo cambios de datos y no se modifico codigo durante este cierre.

## Cierre local facturacion Por Hora / TIME_ENTRIES - 2026-09-10

Auditoria focalizada: las reglas de estrategia, HH aprobadas no facturadas, tarifa comercial del proyecto, fechas mensuales, conversion por issue_date, precision CLP, vencimiento, trazabilidad y no duplicacion ya estaban cubiertas por `SalesPrefacturationTest` y `BillingDateRulesTest`.

Blocker corregido: `SalesPrefacturationService::generateDraft()` no asignaba `document_type_id`, por lo que el borrador TIME_ENTRIES no podia pasar por `SalesDocumentService::confirm()`. Ahora resuelve el catalogo sales/FACTURA activo de la misma empresa y falla con mensaje controlado si falta.

Prueba E2E agregada: `test_hourly_billing_draft_can_be_confirmed_without_recalculating_or_reusing_hours`, con 12 tests / 58 assertions PASS en `SalesPrefacturationTest`. Valida HH aprobadas, tarifa comercial UF, conversion issue_date, CLP entero, IVA, due/projected, borrador, links HH, confirmacion Borrador -> Pendiente sin recalculo y exclusion de segunda facturacion. Sin SQL, sin migraciones, produccion no tocada.

Smoke productivo pendiente, maximo 6 pasos:
1. Seleccionar un Proyecto Por Hora QA con moneda UF, tarifa comercial definida, payment term y periodo mensual cerrado.
2. Verificar antes de generar: dos HH aprobadas dentro del periodo, una HH no aprobada, y UF/FX disponible para la issue_date; confirmar que ninguna aprobada ya tenga factura activa.
3. Generar el borrador con issue_date no futura y al cierre del periodo; revisar HH aprobadas solamente, tarifa del proyecto, conversion a CLP entero, IVA, total, due/projected y estado Borrador.
4. Confirmar con un numero QA; revisar Pendiente, document_type Factura y que montos, fechas, snapshot y links HH no cambien.
5. Volver a abrir el flujo del mismo periodo y verificar que las HH ya vinculadas no aparecen como facturables.
6. STOP si aparece tarifa de Persona/Asignacion, HH no aprobada, fecha futura/anterior al cierre mensual, recalculo al confirmar, duplicacion de links o cualquier CashMovement creado.

## Cierre productivo smoke Por Hora / TIME_ENTRIES - 2026-09-10

Smoke productivo PASS sobre datos QA. Se validó un proyecto Por Hora en UF con HH aprobadas elegibles y HH no aprobadas excluidas; se utilizo exclusivamente la tarifa comercial del proyecto y la conversion correspondiente a `issue_date`. El borrador genero documento tipo FACTURA con `document_type_id` correcto, neto/IVA/gross CLP enteros y due/projected coherentes.

La confirmacion `Borrador -> Pendiente` con numero QA preservo montos, fechas, snapshot y vinculos HH. Las HH ya facturadas dejaron de estar disponibles para una segunda facturacion. No se creo `CashMovement`. El blocker corregido en `d02d94a272cba4dab81a0960579727b99624ea2b` queda validado en produccion QA y el bloque TIME_ENTRIES queda cerrado.

Sin migraciones, sin SQL y sin cambios de codigo en este cierre documental.
## Gate final de release: fixture de fechas alineado - 2026-09-10

La auditoria final mantuvo **0 blockers productivos**. El grupo financiero focalizado permanece en **53 tests / 421 assertions PASS**. `BillingDateRulesTest` inicialmente presentaba 9 PASS y 6 errores porque su fixture no creaba el `DocumentType` activo `sales/FACTURA` requerido legitimamente por los servicios de facturacion.

Se corrigio exclusivamente el fixture de `BillingDateRulesTest`, agregando ese catalogo para la empresa del test, sin cambios de codigo productivo ni de expectativas funcionales. Resultado final: **15 tests / 25 assertions PASS**.

Sin migraciones, sin SQL y sin deploy. **RELEASE STATUS: READY**; puede cerrarse release.
## QA focalizado de Proyectos - 2026-09-10

QA productivo realizado con datos temporales `QA-PROJ-20260910-CLOSED` y `QA-PROJ-20260910-HOURLY`, ambos eliminados al finalizar. `PRY-000012` no fue modificado.

Se verifico carga de lista, detalle, edicion, persistencia, busqueda sin resultados y 404 controlado. El proyecto cerrado QA se creo, mostro el plan y total 100%; al probar 101% se mostro warning y se bloqueo Guardar. El proyecto Por Hora QA se creo con UF 1,20 y pago a 30 dias; tarifa cero fue rechazada server-side y se restauro el valor valido. Cambiar un proyecto cerrado con hitos a Por Hora fue rechazado con mensaje funcional. No se crearon facturas ni movimientos de caja.

Se detecto un blocker UX en la version productiva: el formulario de Proyecto cerrado no exponia controles para agregar/eliminar filas, impidiendo construir 30/40/30 desde la UI. Se implemento localmente un control minimo para agregar hitos y eliminar solo hitos no facturados; el modo de plan completamente facturado permanece solo lectura. La correccion local fue validada por `ProjectBillingPlanHttpTest` (7 tests / 35 assertions PASS) y `php artisan view:cache`.

Estado: **QA LOCAL PASS / PRODUCCION PENDIENTE DE DEPLOY DEL FIX DE UI**. No se hizo deploy, push, SQL ni migracion; el blocker de multi-hito debe verificarse nuevamente despues del despliegue.
## Revision pre-deploy: formulario de hitos robustecido - 2026-09-10

Se corrigieron dos defectos de la UI de planes de facturacion: el template de filas usa indices numericos para que `renumber()` nunca deje `__INDEX__` en nombres enviables, y los totales de un plan solo lectura se inicializan desde los porcentajes reales y no son sobrescritos por JavaScript cuando no existen inputs.

La vista editable mantiene `Agregar hito` y `Eliminar` solo para hitos no facturados; el plan completamente facturado mantiene ausencia de inputs y controles. `ProjectBillingPlanHttpTest` paso con 7 tests / 38 assertions; `php artisan view:cache` y `git diff --check` PASS. Sin migraciones, sin SQL y sin deploy.
## Sincronizacion de moneda comercial en formulario de Proyectos - 2026-09-10

Se corrigio la UX del formulario: las opciones de `sales_currency_id` ahora exponen codigo, simbolo y `minor_units`, y un script CSP sincroniza en vivo esa metadata y el prefijo visible de `contracted_hourly_rate`, `sale_net` y `sale_total` sin convertir los valores numericos. La tarifa comercial HH queda de solo lectura para Proyecto cerrado, preservando su valor historico; continua editable para Por Hora.

`ProjectBillingPlanHttpTest`: 9 tests / 49 assertions PASS. `php artisan view:cache` y `git diff --check` PASS. Sin migraciones, sin SQL y sin deploy; produccion no contiene este ajuste.
## Interacciones finales de moneda comercial en Proyectos - 2026-09-10

Se corrigieron dos regresiones de la UI del formulario: los eventos focus/blur de inputs monetarios leen `data-money-minor-units` dinamicamente, por lo que un cambio CLP -> UF conserva los decimales correctos; y el cambio de tipo de contrato actualiza `contracted_hourly_rate.readOnly` dentro de `sync`, sin depender del orden de seleccion. La tarifa HH queda de solo lectura en Proyecto cerrado y editable en Por Hora, preservando valores historicos.

`ProjectBillingPlanHttpTest`: 9 tests / 51 assertions PASS; `php artisan view:cache` y `git diff --check` PASS. Sin migraciones, sin SQL y sin deploy.
## Edicion estructural atomica del plan de hitos - 2026-09-10

Se corrigio la edicion de planes de facturacion: al agregar un hito la UI elige el menor entero positivo disponible e inserta la fila en orden, evitando duplicados tras eliminar filas intermedias. `ProjectBillingMilestoneService::syncPlan()` valida pertenencia e invariabilidad de hitos facturados antes de mutar, elimina primero omitidos no facturados y usa secuencias temporales unicas para swaps de hitos editables, manteniendo atomicidad e indice unico.

Los errores de plan ahora se devuelven bajo `project_billing_plan`, se conservan con `withInput()` y se muestran junto al plan. `ProjectBillingPlanHttpTest`: 12 tests / 64 assertions PASS; `php artisan view:cache` y `git diff --check` PASS. Sin SQL, sin migraciones y sin deploy.
## Aislamiento de secuencias temporales de hitos - 2026-09-10

Se corrigio `ProjectBillingMilestoneService::syncPlan()` para iniciar las secuencias temporales por encima del maximo entre las secuencias actuales y todas las secuencias finales solicitadas, evitando colisiones durante swaps o reordenamientos hacia valores altos. Las validaciones previas, eliminacion primero, atomicidad, proteccion de hitos facturados e indice unico permanecen intactos.

Se agrego `test_http_update_isolates_temporary_sequences_from_high_requested_sequences`: `ProjectBillingPlanHttpTest` pasa con 13 tests / 67 assertions; `git diff --check` PASS. Sin SQL, sin migraciones y sin deploy.
## QA productivo final de Proyectos - 2026-09-10

**PASS / CERRADO.** El deploy productivo acumulado incluyo `c93db32a4e2c1c1f9c9ccb7bbaf0dacb6b99ee1f` y `0565d16325b0d4695afdf17fc7909f59adb96e08`.

Smoke PASS: plan inicial 1/2/3; se elimino Hito 2 y quedaron secuencias 1/3 con total 70%; `Agregar hito` reutilizo automaticamente la secuencia 2 y la inserto en orden; el nuevo Hito 2 fue modificado y guardado; al reabrir persistieron secuencias 1/2/3 y los nuevos datos; el total quedo en 100%. `PRY-000012` permanecio intacto y read-only. No se ejecuto SQL, no hubo migraciones, ni se crearon facturas o movimientos por este smoke.

`ProjectBillingPlanHttpTest`: 13 tests / 67 assertions PASS. QA productivo final de Proyectos cerrado.
## QA focalizado Personal -> Asignaciones -> Horas - 2026-09-10

Se ejecuto smoke productivo con datos QA propios y controlados, sin tocar `PRY-000012` ni documentos financieros. Se valido Persona `PER-000012` con acentos, RUT aceptado, modalidad Pago por hora, unidad UF y edicion persistente de tarifa `UF 1,20`; Asignacion `ASI-000027` con vinculo Persona/Cliente/Proyecto, vigencia, unidad UF, tarifa de costeo `UF 1,20`, monto pactado `UF 54,00` y persistencia de edicion.

La carga valida de Horas genero `HOR-000065-HOR-000067` para 08/09/2026-10/09/2026: 3 dias, 8 horas trabajadas y aprobadas, tarifa `UF 1,20 / HH`, proyecto/cliente/asignacion derivados correctamente. Se verifico el detalle y la pantalla de edicion. La carga fue eliminada al finalizar, junto con la Asignacion y Persona QA creadas; no quedaron datos creados por este smoke.

Blocker local corregido: `syncRateUnitUi()` actualizaba unidad y prefijo, pero no `data-money-currency-code` ni `data-money-minor-units` de `hourly_value` y `project_value`. El parche sincroniza ambos datasets y dispara blur, evitando que una tarifa UF decimal se redondee como CLP antes del submit. Se agrego regresion al test existente de Asignaciones.

Tests focalizados: `test_assignments_use_single_hourly_rate_selector_and_share_unit_with_project_value`, `test_assignments_show_effective_project_rate_when_specific_hourly_value_is_empty`, `test_assignments_specific_hourly_value_prevails_over_project_reference`, `test_time_entries_create_view_uses_a_single_unified_batch_form` y `test_time_entries_unified_load_creates_daily_entries_from_total_hours_only`: **5 tests / 62 assertions PASS**. `git diff --check` PASS. Los fallos no ejecutados nuevamente corresponden a problemas historicos de `OperationalUiTest` (`all()` sobre array y expectativa desplazada por validaciones previas), no relacionados con este parche.

Estado: **QA LOCAL FOCALIZADO PASS / PRODUCCION NO MODIFICADA**. Sin migraciones, sin SQL, sin deploy ni push. Pendiente para una siguiente ronda: smoke negativo de RUT/duplicado y fecha de termino anterior, si se requiere ampliar cobertura.
## Correccion pre-deploy: metadata de precision en tarifa de Asignaciones - 2026-09-10

Se corrigio exclusivamente `resources/views/operational/form.blade.php`: el selector visual `hourly_rate_unit_visual` ahora usa `currency_minor_units`, que es la clave entregada por `OperationalCrudController::options()`. Asi, UF conserva 2 decimales y CLP, USD, EUR u otras monedas usan los `minor_units` reales del catalogo, sin duplicar metadata ni cambiar logica financiera.

Se agrego una regresion al test existente `test_assignments_use_single_hourly_rate_selector_and_share_unit_with_project_value`, que verifica la opcion CLP con `data-currency-code="CLP"` y `data-currency-minor-units="0"`, junto con la sincronizacion dinamica ya existente para UF. Resultado: **1 test / 7 assertions PASS**. `php artisan view:cache` y `git diff --check` PASS.

Sin migraciones, sin SQL y sin deploy adicional en esta iteracion.
## Correccion de provisioning de metadata Currency - 2026-09-10

El smoke productivo read-only de Asignaciones confirmo que el catalogo de monedas existente estaba desalineado: CLP id 1 tenia simbolo vacio, `minor_units=2` y moneda base `No`; UF id 2, USD id 3 y EUR id 4 tambien mostraban simbolo vacio, `minor_units=2` y moneda base `No`. No se editaron registros productivos.

Causa raiz confirmada: `CatalogService::upsertSimple()` usaba `firstOrCreate()` pero solo persistia atributos genericos y descartaba `symbol`, `minor_units` e `is_base_currency` de Currency. Se agrego `upsertCurrencies()` con `firstOrCreate()` y metadata completa para monedas nuevas, sin sobrescribir personalizaciones existentes al reseedear.

Se agrego `test_currency_seed_preserves_metadata_and_existing_customization` en `AdministrationBaselineSeederTest`: **1 test / 17 assertions PASS**. Verifica CLP, UF, USD y EUR, idempotencia y preservacion de personalizacion. Sin migraciones, sin SQL, sin cambios productivos y sin deploy en esta iteracion.
## QA productivo Staffing/Time: cat�logo Currency reparado y smoke cerrado - 2026-09-10

Se reparo exclusivamente mediante UI la metadata de las cuatro monedas existentes en produccion, sin crear, eliminar ni modificar otros registros. El commit de codigo desplegado `3f2e38102a8dbed5f2256e82027c921329d2a568` contiene la correccion de provisioning en `CatalogService.php`.

Catalogo final: CLP = `$`, `minor_units=0`, moneda base `Si`, activo `Si`; UF = `UF`, `2`, base `No`, activo `Si`; USD = `US$`, `2`, base `No`, activo `Si`; EUR = `�`, `2`, base `No`, activo `Si`. Existe exactamente una moneda por cada codigo y CLP es la unica moneda base.

Smoke productivo de Asignaciones PASS: en Nueva Asignacion se selecciono Persona y Proyecto QA sin guardar. UF mostro prefijo UF, `currencyCode=UF`, `minorUnits=2` y `1,20` con decimales; CLP mostro `$`, `currencyCode=CLP`, `minorUnits=0` y `1200,75` se presento como `1.201`; el retorno a UF mostro `UF`, `2` y `1,25`. No se creo ninguna Asignacion ni otro dato, no hubo errores JavaScript observados ni respuestas HTTP 500/419.

**QA PRODUCTIVO STAFFING/TIME: PASS / CERRADO.** Personal -> Asignaciones -> Horas cerrado. Sin SQL, sin migraciones, sin seeders y sin deploy adicional.
## Cierre productivo final: Currency y smoke Staffing/Time - 2026-09-10

Se completo el precheck productivo y se confirmo exactamente un registro activo por codigo CLP, UF, USD y EUR, sin duplicados. Se repararon unicamente esos cuatro registros existentes mediante la UI, sin crear ni eliminar monedas y sin cambiar sus codigos, nombres, descripciones, estados activos ni ordenes.

Metadata final verificada: CLP = $, minor_units=0, moneda base Si, activo Si; UF = UF, minor_units=2, base No, activo Si; USD = US$, minor_units=2, base No, activo Si; EUR = EUR, minor_units=2, base No, activo Si. CLP es la unica moneda base.

El smoke productivo final de Asignaciones paso sin guardar: UF mostro prefijo UF, codigo UF, minor_units=2 y 1,20 se mantuvo decimal al salir del campo; CLP mostro prefijo $, codigo CLP, minor_units=0 y 1200,75 se presento como 1.201; al volver a UF, 1,25 con minor_units=2 se mostro como 1,25. No se observaron errores JavaScript ni respuestas HTTP 500/419. No se creo ninguna Asignacion ni otro dato durante el smoke.

**QA PRODUCTIVO STAFFING/TIME: PASS / CERRADO.** Personal -> Asignaciones -> Horas cerrado. La produccion queda con la metadata Currency corregida; no se ejecutaron tests por tratarse de una reparacion productiva UI autorizada. Sin SQL, sin migraciones, sin seeders y sin deploy adicional.