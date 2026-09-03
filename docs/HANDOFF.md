# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-03.

ÚNICA fuente de continuidad entre cuentas de ChatGPT/Codex. Leer este archivo al retomar el proyecto y continuar desde el estado actual. NO repetir tareas ya cerradas. No guardar secretos.

## 1. Repositorio y entorno

Repositorio:
`fabianpb-cmd/flujo-caja-pyme-servicios-chile-2026-app`

Aplicación: Laravel.

Staging:
`https://licitaciones.tdatconsulting.cl`

APP_ROOT staging:
`/home/tdatcons/apps/flujo-caja-staging`

Hosting/deploy:
- cPanel;
- sin SSH/Composer/Artisan remoto como flujo normal;
- app privada fuera del document root público;
- preservar siempre `.env` y `storage/`;
- `staging-bootstrap.sql` es bootstrap completo, NO migración incremental. Nunca importarlo sobre staging/productivo existente con datos que se deban conservar.

Producción todavía NO desplegada.

## 2. Estado funcional actual — UAT FINAL PASS

Release funcional probado y desplegado en staging:
- commit: `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- tag: `cpanel-staging-20260903-124424`;
- build: PASS, exit code 0;
- `manifest.git_commit`: `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- ZIPs, secret scan, SQL validation y checksums: PASS;
- APP_ROOT: `/home/tdatcons/apps/flujo-caja-staging`;
- deploy realizado solo con `app-private.zip`; sin `public.zip`, sin SQL, sin migración nueva.

Main puede estar por delante del tag SOLO por commits documentales. No reconstruir release por eso.

### Remuneración A — PASS

Payroll: `REM-000013`
URL: `/operacion/payroll-records/13`
Proyecto: `QA-A-PROYECTO / PRY-000009`
Persona: `QA-A-PERSONA UAT QA`
Período: septiembre 2026

Validado:
- 10 h aprobadas;
- valor HH `$40.000`;
- bruto `$400.000`;
- bonos `$0`;
- no imponibles `$0`;
- retención honorarios `$61.000`;
- líquido `$339.000`;
- costo empresa `$400.000`;
- cálculo OK;
- trazabilidad PASS a `ASI-000022 · QA-A-PROYECTO`, vigencia `01/09/2026 al 30/09/2026`;
- sin 500.

NO repetir ni duplicar `REM-000013`.

### Remuneración B — PASS

Payroll: `REM-000014`
URL: `/operacion/payroll-records/14`
Proyecto: `QA-B-PROYECTO / PRY-000010`
Persona: `QA-B-PERSONA`
Período: septiembre 2026

Validado:
- 6 h aprobadas;
- valor HH `$50.000`;
- bruto `$300.000`;
- `QA-B-AJUSTE` aplicado automáticamente una sola vez como bono imponible `$10.000` desde Novedades remuneración;
- no imponibles `$0`;
- retención honorarios `$45.750` (15,25 %);
- líquido `$254.250`;
- costo empresa mostrado `$300.000`;
- cálculo OK;
- trazabilidad PASS a `ASI-000023 · QA-B-PROYECTO`, vigencia `01/09/2026 al 30/09/2026`;
- sin 500.

NO repetir ni duplicar `REM-000014`.

### Rentabilidad — PASS

QA-A-PROYECTO:
- ingresos `$1.000.000`;
- costo laboral `$400.000`;
- otros costos directos `$100.000`;
- costo total `$500.000`;
- margen `$500.000`;
- margen `50 %`;
- detalle: 10 h, costo HH promedio `$40.000`;
- coherente con `REM-000013`.

QA-B-PROYECTO:
- ingresos `$500.000`;
- costo laboral `$300.000`;
- otros costos directos `$200.000`;
- costo total `$500.000`;
- margen `$0`;
- margen `0 %`;
- detalle: 6 h, costo HH promedio `$50.000`;
- coherente con `REM-000014`;
- el bono `QA-B-AJUSTE` no aplica al costo laboral según la definición visible de Rentabilidad.

Conclusión formal:
- Remuneración A: PASS;
- Remuneración B: PASS;
- Rentabilidad: PASS;
- Escenario A E2E: PASS;
- Escenario B E2E: PASS;
- UAT FINAL: PASS;
- funcionalmente apto para preparar producción: SI.

## 3. Blockers resueltos — no reabrir sin evidencia nueva

### Selector Proyecto en Remuneraciones

Causa: `period_date` es input de texto y faltaba escuchar evento `input` además de `change`.
Fix:
`payrollPeriodInput?.addEventListener('input', syncPayrollProjects);`
Staging PASS.

### 500 al guardar payroll horario/honorarios

Causa comprobada en log:
`SQLSTATE[23000]: Column 'bonuses' cannot be null` durante `MassAssignment::create(PayrollRecord)`, antes de `syncHourlyTimeEntryTrace()`.

Fix funcional en `app/Services/PayrollService.php`:
normalizar `bonuses`, `non_taxable_allowances`, `advances` y `other_deductions` a valores numéricos también para modalidad honorarios/pago por hora.

Test dirigido:
`php artisan test tests\Feature\PayrollTimeEntryTraceTest.php`
PASS: 10 tests / 92 assertions.

### Build cPanel Windows

El secret scan antiguo abría el ZIP una vez por archivo y se quedaba colgado. Se cambió a PHP `ZipArchive`, abriendo cada ZIP una vez y conservando los mismos patrones de seguridad. Build final PASS. No deshacer.

### Otros issues UAT ya resueltos

PASS y cerrados:
- Responsable Proyecto;
- Presupuesto / botón Nuevo presupuesto;
- labels Centros de costo / Horas;
- rutas correctas `/crear` (el antiguo `/create` era URL inválida, no blocker funcional).

## 4. Módulos ya PASS — NO repetir en producción salvo smoke mínimo

Ya validados en staging:
- Clientes;
- Proyectos;
- Personal;
- Asignaciones;
- Horas;
- Remuneraciones;
- Novedades remuneración;
- Facturas;
- CxC;
- Egresos;
- CxP;
- Cuentas / Movimientos;
- Obligaciones;
- Flujo de caja;
- Escenarios;
- Dashboard;
- Presupuesto;
- Rentabilidad;
- Usuarios admin;
- seguridad usuario normal / 403 Administración;
- catálogos/mantenedores smoke.

No repetir UAT completa para preparar producción.

## 5. Data QA a preservar mientras se prepare producción

QA-A:
`QA-A-CLIENTE`, `QA-A-PROYECTO`, `QA-A-PERSONA`, `QA-A-CUENTA`, `REM-000013`.

QA-B:
`QA-B-CLIENTE`, `QA-B-PROYECTO`, `QA-B-PERSONA`, `QA-B-AJUSTE`, `REM-000014`.

Otros:
`QA-USER`, `QA-CENTRO-COSTO`.

Presupuesto QA único:
- proyecto `QA-A-PROYECTO`;
- escenario Base;
- período `01/09/2026`;
- ingreso `$1.000.000`;
- personal `$400.000`;
- otros directos `$100.000`.

No limpiar ni duplicar datos QA sin una razón explícita.

## 6. Seguridad BD / deploy

Migraciones relevantes existentes:
- `database/migrations/2026_08_28_000100_create_payroll_record_time_entries_table.php`;
- `database/migrations/2026_08_31_000100_make_period_batch_id_not_nullable_on_time_entries.php`.

Reglas:
- producción existente: backup + migración incremental revisada;
- nunca bootstrap sobre BD existente;
- nunca cambiar `APP_ENV` para saltar salvaguardas;
- nunca desactivar FKs a ciegas;
- nunca inventar backfills;
- preservar `.env` y `storage/`.

## 7. Próximo paso exacto — preparar producción, NO desplegar todavía

Hacer una sola pasada de production readiness, sin repetir QA funcional:
1. verificar APP_ROOT/document root REAL de producción; el valor tentativo `/home/tdatcons/apps/flujo-caja-production` NO debe asumirse sin evidencia;
2. revisar plantilla/configuración production existente y diferencias necesarias respecto de staging;
3. determinar si producción ya tiene BD y qué migraciones incrementales requeriría;
4. definir backup previo;
5. comparar release funcional `ec1d511...` con lo que deba incluir producción;
6. decidir si hace falta algún cambio mínimo específico de producción;
7. solo después generar UN build production final;
8. validar manifest, checksums, ZIPs, secret scan, APP_ROOT y migraciones;
9. crear tag production únicamente después de build PASS;
10. DETENERSE antes del deploy para revisión/confirmación manual.

Después del futuro deploy production, smoke mínimo solamente:
`/up`, `/login`, login, 2FA, dashboard.

## 8. Política de ahorro de créditos para Codex

Desde 2026-09-03, priorizar costo:
- modelo por defecto para tareas acotadas: `GPT-5.6 Luna`;
- usar `GPT-5.6 Terra` solo cuando Luna no sea suficiente para razonamiento/cambios cruzados;
- reservar modelos más caros para blockers complejos realmente justificados;
- esfuerzo Bajo por defecto; Medio solo cuando haga falta;
- no pedir lectura amplia del repo si basta con archivos concretos;
- no ejecutar suite completa si existe test dirigido;
- no repetir builds ni UAT ya PASS;
- agrupar inspección + cambio + test + checkpoint en una sola tarea Codex cuando sea seguro;
- pedir salida corta y estructurada, sin explicaciones extensas;
- actualizar este HANDOFF con checkpoints compactos, no narrativas largas;
- antes de tareas largas, registrar checkpoint; después, registrar solo delta y resultado.

Nota de modelos: GPT-5.4 y GPT-5.4 mini fueron retirados de Codex para sesiones con cuenta ChatGPT el 31-08-2026. Sus reemplazos son GPT-5.6 Terra y GPT-5.6 Luna respectivamente.
