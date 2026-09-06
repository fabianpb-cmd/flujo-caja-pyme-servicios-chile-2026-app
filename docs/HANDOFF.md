# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-06.

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

## Pendiente inmediato

**Smoke funcional mínimo pendiente; no repetir suite completa ni UAT amplio.**

1. Proyecto cerrado: emitir de forma controlada un solo hito con fecha de emisión explícita y verificar:
   - neto deriva del porcentaje contractual (no de HH/costo);
   - conversión UF/moneda usa fecha de emisión;
   - documento queda vinculado al hito;
   - hito pasa a facturado y queda inmutable;
   - HH no se consumen como fuente de ingreso.
2. Por Hora: smoke mínimo con un proyecto/escenario QA válido para confirmar `contracted_hourly_rate` comercial y tasa de `issue_date`.
3. Tras PASS, actualizar este mismo HANDOFF y cerrar la release.

Antes de cualquier nueva operación riesgosa o cambio de código, checkpoint aquí. Ante incidente, revisar primero `storage/logs` y comparar contra `main`.

## Continuidad Git

Los checkpoints documentales se realizan directamente en `main`; antes de volver a trabajar localmente/Codex ejecutar `git fetch origin` y rebasear sobre `origin/main` si corresponde. No usar force push.

Prompt de continuidad entre cuentas:
`Lee docs/HANDOFF.md del repositorio y continúa desde el estado actual. No repitas tareas ya completadas.`
