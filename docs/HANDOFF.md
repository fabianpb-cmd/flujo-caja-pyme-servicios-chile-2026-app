# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-03.

Este archivo es la ÚNICA fuente de continuidad entre cuentas de ChatGPT/Codex para este proyecto. Debe actualizarse cada vez que cambie el estado real del proyecto, una decisión técnica, una prueba, un incidente, un commit, un push, un tag, un build, un deploy, la base de datos o el próximo paso. Si perder un dato obligaría a repetir trabajo o adivinar, debe quedar aquí. No guardar secretos.

Para continuar desde otra cuenta: leer este archivo completo y seguir desde el estado actual. NO repetir tareas ya completadas.

## 1. Repositorio y entornos

Repositorio GitHub:
`fabianpb-cmd/flujo-caja-pyme-servicios-chile-2026-app`

Aplicación: Laravel.

Staging:
`https://licitaciones.tdatconsulting.cl`

APP_ROOT staging:
`/home/tdatcons/apps/flujo-caja-staging`

Hosting/deploy conocido:
- cPanel;
- sin SSH remoto como flujo operativo normal;
- sin Composer remoto;
- sin Artisan remoto;
- aplicación privada fuera del document root público;
- `.env` y `storage/` deben persistir entre despliegues;
- para updates existentes NO importar `staging-bootstrap.sql` si se debe conservar data QA.

Producción todavía NO ha sido desplegada en esta conversación y permanece NO autorizada hasta UAT FINAL PASS.

## 2. Regla de continuidad

- `docs/HANDOFF.md` es la única fuente de verdad entre cuentas.
- No crear archivos HANDOFF paralelos.
- El antiguo `docs/HANDOFF-LATEST.md` quedó obsoleto y debe permanecer eliminado; su contenido relevante ya está consolidado aquí.
- Registrar estados como CONFIRMADO / PENDIENTE / NO VERIFICADO cuando corresponda.
- No guardar `.env`, APP_KEY, contraseñas, credenciales BD, códigos 2FA ni secretos.
- No repetir módulos UAT ya PASS.
- Para Codex, recomendar siempre modelo + esfuerzo con criterio de costo.
- Tareas acotadas: preferir GPT-5.4 Mini + Bajo/Medio; escalar solo si aparece investigación realmente compleja.

## 3. Releases y commits relevantes

Release funcional base anterior:
`c24d3d3f37688e34818182aa30b6f5b1bdfa6bb1`
Tag: `cpanel-staging-20260831-194817`

Correcciones UAT posteriores:
`c2a3e97b6fdbc5d5a745e023ca040a5cf16222f6`
Mensaje: `Fix staging UAT issues`

Fix definitivo del selector Proyecto, integrado y pusheado posteriormente:
`4622b71f5455b30a9cf75cc90c5eb8f338d1be9d`

Ajuste del build cPanel Windows + release funcional desplegado actual:
`3efcf37f69b2dbebbbacdbba14d7220c943376f4`
Mensaje: `Fix cpanel release secret scan on Windows`
Tag staging actual desplegado:
`cpanel-staging-20260903-091246`

Build del release `3efcf37...`:
- PASS, exit code 0;
- `manifest.git_commit = 3efcf37f69b2dbebbbacdbba14d7220c943376f4`;
- `app-private.zip`: PASS `unzip -t`;
- `public.zip`: PASS `unzip -t`;
- secret scan: PASS;
- SQL validation: PASS;
- checksums: PASS;
- APP_ROOT: `/home/tdatcons/apps/flujo-caja-staging`.

Deploy del release actual confirmado manualmente por el usuario el 2026-09-03:
- deploy completado;
- se esperaba/realizó update de `app-private.zip`;
- sin SQL nuevo;
- preservar `.env`, `storage/` y data QA.

Main remoto contiene además commits SOLO documentales posteriores al release funcional desplegado. No confundir `origin/main` con el SHA funcional actualmente instalado en staging.

Checkpoint documental remoto previo al diagnóstico actual:
`bf0230a481d9f0b8ee358ea54f59012b300bffeb`
Mensaje: `docs: checkpoint payroll save UAT failure`

## 4. Migraciones / seguridad de BD

Migración existente importante:
`database/migrations/2026_08_31_000100_make_period_batch_id_not_nullable_on_time_entries.php`

Migración de trazabilidad payroll-horas:
`database/migrations/2026_08_28_000100_create_payroll_record_time_entries_table.php`

Reglas críticas:
- `staging-bootstrap.sql` es dump completo reconstruido/seeded, NO migración incremental;
- no importar bootstrap sobre staging/productivo existente si se quiere conservar datos;
- updates de BD existente: backup + SQL/migración incremental revisada;
- nunca cambiar `APP_ENV` para saltar salvaguardas;
- nunca desactivar FKs a ciegas;
- no inventar backfills.

Para el blocker actual NO se ha confirmado necesidad de cambio de BD. El error comprobado es de código/normalización de datos antes del INSERT.

## 5. Limpieza y data QA que debe conservarse

La BD de staging se limpió antes de la UAT final. Se preservaron configuración y parametrizaciones base:
- empresa bootstrap;
- administrador inicial;
- catálogos;
- parámetros empresa y legales;
- UF, UTM, tipos de cambio;
- AFP y tasas;
- IUSC;
- geografía;
- configuraciones seed;
- migrations;
- escenarios estándar `CONSERVADOR`, `BASE`, `OPTIMISTA`.

Data QA actual que NO debe limpiarse ni duplicarse innecesariamente:
- `QA-A-CLIENTE`
- `QA-A-PROYECTO`
- `QA-A-PERSONA`
- `QA-A-CUENTA`
- `QA-B-CLIENTE`
- `QA-B-PROYECTO`
- `QA-B-PERSONA`
- `QA-B-AJUSTE`
- `QA-USER`
- `QA-CENTRO-COSTO`
- presupuesto QA único de `QA-A-PROYECTO`, escenario Base, período `01/09/2026`, ingreso `$1.000.000`, personal `$400.000`, otros directos `$100.000`.

Escenario A QA:
- 10 h trabajadas/aprobadas;
- valor HH CLP 40.000;
- factura `ING-000003`, neto 1.000.000, IVA 190.000, total 1.190.000;
- cobro parcial 500.000 (`MOV-000004`);
- cobro final 690.000 (`MOV-000005`);
- CxC final 0;
- egreso `EGR-000002`, total 119.000;
- CxP final 0.

Escenario B QA:
- 6 h trabajadas/aprobadas;
- persona por hora, tarifa CLP 50.000;
- ajuste `QA-B-AJUSTE`: bono imponible 10.000;
- factura `ING-000004`, total 595.000, pendiente;
- CxC 595.000;
- egreso `EGR-000003`, total 238.000;
- pago parcial 100.000;
- CxP restante 138.000.

Flujo real septiembre validado antes del blocker payroll:
- ingresos 1.190.000;
- egresos 219.000;
- flujo real 971.000;
- CxC 595.000;
- CxP 138.000.

## 6. Módulos ya PASS — NO repetir

PASS y cerrados salvo que una corrección futura los toque directamente:
- Clientes;
- Proyectos, incluido Responsable de Proyecto;
- Personal;
- Asignaciones;
- Horas y labels;
- Facturas;
- CxC;
- Egresos;
- CxP;
- Cuentas / Caja / Movimientos;
- Obligaciones;
- Flujo de caja;
- Escenarios;
- Dashboard;
- Usuarios admin;
- seguridad usuario normal / 403 Administración;
- catálogos/mantenedores smoke;
- Presupuesto;
- Centros de costo label.

Prefacturación había pasado UAT financiera previa y se evitó repetir para no generar duplicados.

## 7. Incidencias UAT históricas y correcciones

### 7.1 Responsable de Proyecto — RESUELTO

`manager_id` persistía pero existía colisión con relación inferida `manager()`. Se agregó relación explícita `projectManager()` y config correspondiente. Staging PASS. No repetir.

### 7.2 Presupuesto — RESUELTO

Faltaba navegación visible para alta. Se agregó `Nuevo presupuesto` hacia CRUD existente. Staging PASS. No repetir ni crear duplicados.

### 7.3 Labels — RESUELTO

Hook especial de horas aparecía en mantenedores genéricos. Se limitó a `time-entries`. Centros de costo muestra `Guardar`; Horas muestra `Registrar horas` / `Guardar carga`. PASS.

### 7.4 500 por ruta `/create` — NO era regresión funcional

Las rutas reales usan `/crear`. `/create` caía en `/{record}` y producía TypeError. No confundir ese incidente antiguo con el 500 actual del POST válido.

Hardening opcional futuro: `whereNumber('record')`; no es blocker actual.

## 8. Selector Proyecto de Remuneraciones — RESUELTO EN STAGING

Causa raíz definitiva del selector:
- `period_date` es `input type="text"`;
- el JS escuchaba `change` pero faltaba `input`;
- al escribir/seleccionar fecha, `syncPayrollProjects()` podía seguir viendo valor vacío.

Fix:
```js
payrollPeriodInput?.addEventListener('input', syncPayrollProjects);
```

Se mantiene listener `change`.

Tests post-integración del fix:
- `PayrollTimeEntryTraceTest`: PASS, 10 tests / 88 assertions;
- `OperationalUiTest --filter=payroll_project_options_and_backend_require_assignment_for_period`: PASS, 1 test / 22 assertions.

En staging, luego del deploy `3efcf37...`, el usuario confirmó:
- Proyecto habilitado: PASS;
- `QA-A-PROYECTO` seleccionable: PASS.

Por tanto, NO volver a tocar el selector salvo evidencia nueva.

## 9. Build cPanel Windows — incidente y resolución

El build se quedaba colgado porque `scan_zip_for_forbidden_strings()` abría `app-private.zip` una vez por cada archivo textual y hacía `unzip -p | grep` para miles de archivos de `vendor/`.

Ajuste en `scripts/build-cpanel-release.sh`:
- mismos patrones prohibidos (`/Users/`, `/Applications/`, `127.0.0.1:8000`, `/private/var/folders/`, `/var/folders/`);
- mismas extensiones textuales;
- implementación con PHP `ZipArchive` que abre cada ZIP una sola vez;
- fallback `php -d extension=zip` para Windows/Git Bash.

Medición posterior:
- `app-private.zip`: 7.519 entradas textuales, PASS, ~1 s;
- `public.zip`: 4 entradas, PASS, <1 s.

No deshacer esta optimización.

Pendiente post-UAT: diseñar modo rápido staging incremental (`CPANEL_FAST_BUILD=true` o equivalente) para evitar regenerar bootstrap/dependencias cuando no se necesitan. Mantener build completo y validaciones completas para candidato final/producción.

## 10. UAT post-deploy 2026-09-03 — selector PASS, guardado FAIL

Remuneración A usada:
- URL formulario: `https://licitaciones.tdatconsulting.cl/operacion/payroll-records/crear`;
- Persona: `QA-A-PERSONA UAT QA`;
- período: `2026-09-01`;
- proyecto: `QA-A-PROYECTO`;
- base: `Bruto`;
- fecha pago: `2026-09-30`.

Resultado:
- Proyecto habilitado: PASS;
- Proyecto seleccionado: PASS;
- panel previo mostraba `0 h`;
- al presionar Guardar, POST `/operacion/payroll-records` -> 500;
- payroll no visible/generado;
- cálculo y trazabilidad no validables.

Remuneración B NO ejecutada.
Rentabilidad NO ejecutada.
UAT FINAL: FAIL.
Apto producción: NO.

## 11. Diagnóstico exacto del 500 al guardar Remuneración A — CONFIRMADO

Evidencia del log de staging:

Exception:
`Illuminate\Database\QueryException`

Mensaje:
`SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'bonuses' cannot be null`

Archivo framework:
`/home/tdatcons/apps/flujo-caja-staging/vendor/laravel/framework/src/Illuminate/Database/Connection.php`

Línea:
`857`

SQL del log:
INSERT en `payroll_records` con `bonuses = NULL` y `hours_approved = 10`.

Operación exacta que falla:
`MassAssignment::create(PayrollRecord)` dentro de `OperationalCrudController::store()`, ANTES de `syncHourlyTimeEntryTrace()`.

Conclusiones comprobadas:
- el backend SÍ encontró las 10 h aprobadas de QA-A al preparar el payroll;
- `project_id = 9` correspondía al proyecto QA-A seleccionado en el POST/log;
- el `0 h` visible antes del POST es problema de preview/UI y NO significa que el backend de guardado ignore las horas;
- la falla sucede antes de escribir la trazabilidad pivote;
- no hay evidencia que obligue a modificar esquema/BD para este error.

Esquema staging no verificado directamente desde la sesión de diagnóstico:
- existencia de `payroll_record_time_entries`: NO VERIFICADA;
- row de migration: NO VERIFICADA;
- `SHOW CREATE TABLE`: NO DISPONIBLE.

Esto deja esas verificaciones como posibles controles posteriores, pero NO son la causa del 500 actual ya demostrado por el log.

## 12. Causa raíz funcional del 500 — CONFIRMADA

El formulario manual envía campos monetarios vacíos. Laravel los normaliza a `NULL`.

Para modalidad honorarios/pago por hora, `PayrollService::calculate()` no devolvía algunos campos NOT NULL — específicamente el caso observado `bonuses`, y también se identificó el mismo riesgo para `non_taxable_allowances`.

Esos `NULL` quedaban explícitos en los datos usados por `MassAssignment::create()`, por lo que MySQL no aplicaba defaults y rechazaba el INSERT por `payroll_records.bonuses NOT NULL`.

Causa raíz única comprobada:
normalización incompleta de campos monetarios nullable/vacíos en el cálculo manual de payroll horario/honorarios antes del INSERT.

Corrección requerida:
- Código: SI.
- BD: NO para este error.
- No proponer/importar SQL ni bootstrap por este blocker.

## 13. Fix local del 500 — HECHO, TESTEADO, AÚN NO REMOTO

Codex reportó cambio local en:
- `app/Services/PayrollService.php`;
- `tests/Feature/PayrollTimeEntryTraceTest.php`;
- `docs/HANDOFF.md`.

Test ejecutado:
`php artisan test tests\Feature\PayrollTimeEntryTraceTest.php`

Resultado:
- PASS;
- 10 tests;
- 92 assertions.

Commit LOCAL reportado:
`2b11a09000c54441d087403b4182a805967792df`

Verificación GitHub realizada desde ChatGPT después del reporte:
- `GitHub.fetch_commit(2b11a090...)` -> `No commit found for SHA` / 422.

Por tanto:
- el fix `2b11a090...` está LOCAL;
- NO está en `origin/main` todavía;
- NO está desplegado;
- NO decir que está pusheado hasta verificarlo después de la integración.

## 14. Estado esperado después del POST fallido

No se verificó directamente en BD staging si quedó un payroll QA-A, pero el INSERT falló en `MassAssignment::create()` dentro de una transacción antes de `syncHourlyTimeEntryTrace()`, por lo que no debería existir payroll persistido por ese intento. Aun así, antes de repetir UAT tras el próximo deploy conviene comprobar visualmente/listado o por consulta disponible que no exista duplicado QA-A para septiembre.

No se verificaron pivot rows porque el error ocurrió antes de la sincronización.

No borrar ni limpiar data a ciegas.

## 15. Próximo paso EXACTO — integrar el fix local, push, build staging UNA vez

El fix local `2b11a090...` debe integrarse sobre el `origin/main` actual, que contiene checkpoints documentales posteriores.

Secuencia segura:
1. en Codex/local: `git status`, `git fetch origin`, revisar `git rev-parse HEAD` y `git rev-parse origin/main`;
2. preservar el commit funcional local `2b11a090...`;
3. integrar `origin/main` sin force push, preferentemente rebase/cherry-pick seguro;
4. resolver cualquier conflicto de `docs/HANDOFF.md` conservando TODO el estado remoto actual y el detalle del fix local;
5. confirmar que `docs/HANDOFF-LATEST.md` NO existe después de integrar;
6. si el rebase solo toca docs y no hay conflicto de código, no repetir suites amplias; si hay conflicto en código, correr únicamente `PayrollTimeEntryTraceTest.php`;
7. push normal a `origin/main`, sin `--force`;
8. verificar en GitHub que el nuevo HEAD remoto contiene el cambio en `PayrollService.php`, test y este HANDOFF;
9. comparar contra `3efcf37...` para determinar archivos runtime modificados y confirmar si `public/` sigue sin cambios;
10. generar UN solo build staging desde el HEAD remoto integrado final;
11. validar exit code 0, manifest.git_commit = SHA final, ZIPs, secret scan, SQL validation, checksums y APP_ROOT;
12. crear nuevo tag `cpanel-staging-YYYYMMDD-HHMMSS` solo después del build PASS;
13. NO desplegar automáticamente desde Codex: dejar artefacto listo para despliegue manual del usuario;
14. para este fix se espera deploy de `app-private.zip`; no subir `public.zip` si compare confirma que `public/` no cambió;
15. NO importar SQL/bootstrap, NO limpiar BD, preservar `.env`, `storage/` y data QA.

Modelo recomendado para esta integración/build acotado:
GPT-5.4 Mini + Bajo. Subir a Medio solo si aparecen conflictos reales o una validación falla de forma no obvia.

## 16. UAT que debe repetirse después del próximo deploy

Primero SOLO Remuneración A:
- `QA-A-PERSONA UAT QA`;
- período `2026-09-01`;
- `QA-A-PROYECTO`;
- base `Bruto`;
- fecha pago `2026-09-30`.

Validar:
- Proyecto habilitado;
- 10 h backend/calculadas;
- guardado sin 500;
- ID/código generado;
- cálculo;
- trazabilidad de horas.

Si A FAIL: detenerse y diagnosticar; NO ejecutar B ni Rentabilidad.

Si A PASS:
- ejecutar Remuneración B con `QA-B-PERSONA`, septiembre 2026, `QA-B-PROYECTO`;
- confirmar 6 h;
- confirmar `QA-B-AJUSTE` bono imponible 10.000;
- confirmar cálculo y trazabilidad.

Solo si A y B PASS:
- ejecutar Rentabilidad para QA-A y QA-B;
- verificar costo laboral y márgenes coherentes.

NO repetir Presupuesto, Responsable Proyecto, Centros de costo, Horas labels ni otros módulos ya PASS.

## 17. Criterio de cierre UAT

UAT FINAL solo puede pasar si:
- Remuneración A PASS, consume 10 h aprobadas y deja trazabilidad;
- Remuneración B PASS, consume 6 h, incorpora `QA-B-AJUSTE` 10.000 y deja trazabilidad;
- Rentabilidad refleja costos laborales y márgenes coherentes para ambos proyectos.

Entonces:
- Remuneraciones PASS;
- Rentabilidad PASS;
- Escenario A end-to-end PASS;
- Escenario B end-to-end PASS;
- UAT FINAL PASS;
- funcionalmente apto para producción: SI.

Hasta entonces:
UAT FINAL = FAIL.
Producción = NO autorizada.

## 18. Producción — pendiente

Solo después de UAT FINAL PASS:
- no repetir QA completa;
- preparar release production;
- determinar APP_ROOT/document root real de producción;
- revisar plantilla/config production existente;
- backup antes de deploy;
- nunca importar bootstrap sobre BD productiva existente;
- usar migración incremental revisada si correspondiera;
- validar manifest/checksums/ZIP/secrets;
- smoke mínimo post-deploy: `/up`, `/login`, login, 2FA, dashboard.

## 19. Integración, build staging y tag del fix `bonuses = NULL`

Fecha: 2026-09-03.

Integración:
- `origin/main` inicial confirmado: `5f25be96fb0b15f32308f36f4bd2dc77f88ceeaf`;
- commit funcional local original: `2b11a09000c54441d087403b4182a805967792df`;
- estrategia usada: `git rebase origin/main`;
- conflicto: solo `docs/HANDOFF.md`;
- resolución del conflicto: se preservó el HANDOFF remoto como fuente principal, que ya contenía el diagnóstico del `NULL`, y se reaplicó solo el fix funcional;
- `docs/HANDOFF-LATEST.md`: no existe en HEAD integrado;
- HEAD funcional final integrado: `ec1d51160b8899b7351950fc1202f157d72e42c4`.

Fix funcional integrado:
- `app/Services/PayrollService.php`: normaliza `bonuses`, `non_taxable_allowances`, `advances` y `other_deductions` a valores numéricos en `calculate()` y los devuelve también para modalidad honorarios/pago por hora;
- `tests/Feature/PayrollTimeEntryTraceTest.php`: el test manual horario envía esos campos como strings vacíos, verifica persistencia en `0.0`, 10 h aprobadas y trazabilidad.

Test dirigido:
- comando: `php artisan test tests\Feature\PayrollTimeEntryTraceTest.php`;
- resultado: PASS;
- tests: 10;
- assertions: 92.

Push:
- `git push origin main`: PASS;
- `HEAD` y `origin/main` verificados en `ec1d51160b8899b7351950fc1202f157d72e42c4`.

Compare contra release desplegado actual `3efcf37f69b2dbebbbacdbba14d7220c943376f4`:
- runtime: `app/Services/PayrollService.php`;
- tests: `tests/Feature/PayrollTimeEntryTraceTest.php`;
- docs: `docs/HANDOFF.md` y eliminación de `docs/HANDOFF-LATEST.md`;
- tooling: ninguno;
- `public/`: sin cambios.

Build staging completo:
- comando: `scripts/build-cpanel-release.sh` con `CPANEL_RELEASE_MODE=staging` y MySQL Laragon activo;
- resultado: PASS, exit code 0;
- `manifest.git_commit`: `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- release id generado: `cpanel-staging-20260903-124325`;
- `app-private.zip`: PASS `unzip -t`;
- `public.zip`: PASS `unzip -t`;
- secret scan: PASS dentro del build;
- SQL validation: PASS dentro del build;
- checksums: PASS con `shasum -a 256 -c checksums.txt`;
- APP_ROOT: `/home/tdatcons/apps/flujo-caja-staging`.

Tag staging:
- nombre: `cpanel-staging-20260903-124424`;
- SHA: `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- push tag: PASS;
- verificación: `git rev-list -n 1 cpanel-staging-20260903-124424` devuelve `ec1d51160b8899b7351950fc1202f157d72e42c4`.

Deploy requerido, NO ejecutado:
- subir solo `app-private.zip`;
- NO subir `public.zip`;
- NO importar `staging-bootstrap.sql`;
- NO ejecutar SQL;
- NO ejecutar migración nueva;
- preservar `.env`;
- preservar `storage/`;
- preservar data QA staging.

Estado importante:
- TAG `cpanel-staging-20260903-124424` = release funcional desplegable;
- main HEAD posterior puede avanzar solo por este checkpoint documental;
- si main queda por delante del tag por documentación, NO reconstruir release por eso.

Próximo paso exacto:
1. deploy manual de `app-private.zip` del release `cpanel-staging-20260903-124424`;
2. después del deploy, repetir SOLO Remuneración A con `QA-A-PERSONA UAT QA`, período `2026-09-01`, `QA-A-PROYECTO`, base `Bruto`, fecha pago `2026-09-30`;
3. validar Proyecto habilitado, 10 h, guardado sin 500, ID/código, cálculo y trazabilidad;
4. si A falla, detenerse;
5. solo si A pasa, continuar B y Rentabilidad.
