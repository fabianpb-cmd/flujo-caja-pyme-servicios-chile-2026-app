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

Ajuste del build cPanel Windows + release funcional desplegado anterior:
`3efcf37f69b2dbebbbacdbba14d7220c943376f4`
Mensaje: `Fix cpanel release secret scan on Windows`
Tag staging anterior:
`cpanel-staging-20260903-091246`

Release funcional actual desplegado desde 2026-09-03:
`ec1d51160b8899b7351950fc1202f157d72e42c4`
Tag staging actual desplegado:
`cpanel-staging-20260903-124424`

Build del release actual:
- PASS, exit code 0;
- `manifest.git_commit = ec1d51160b8899b7351950fc1202f157d72e42c4`;
- `app-private.zip`: PASS `unzip -t`;
- `public.zip`: PASS `unzip -t`;
- secret scan: PASS;
- SQL validation: PASS;
- checksums: PASS;
- APP_ROOT: `/home/tdatcons/apps/flujo-caja-staging`.

Main remoto contiene además commits SOLO documentales posteriores al release funcional desplegado. No confundir `origin/main` con el SHA funcional actualmente instalado en staging.

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

Para el blocker actual NO se confirmó necesidad de cambio de BD. El error comprobado era de código/normalización de datos antes del INSERT.

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

Las rutas reales usan `/crear`. `/create` caía en `/{record}` y producía TypeError. No confundir ese incidente antiguo con el 500 del POST válido ya diagnosticado.

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

En staging, luego del deploy anterior, el usuario confirmó:
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

## 10. UAT post-deploy anterior — selector PASS, guardado FAIL

Remuneración A usada:
- URL formulario: `https://licitaciones.tdatconsulting.cl/operacion/payroll-records/crear`;
- Persona: `QA-A-PERSONA UAT QA`;
- período: `2026-09-01`;
- proyecto: `QA-A-PROYECTO`;
- base: `Bruto`;
- fecha pago: `2026-09-30`.

Resultado histórico:
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
- el `0 h` visible antes del POST era problema de preview/UI y NO significa que el backend de guardado ignore las horas;
- la falla sucedía antes de escribir la trazabilidad pivote;
- no había evidencia que obligara a modificar esquema/BD para este error.

## 12. Causa raíz funcional del 500 — CONFIRMADA

El formulario manual envía campos monetarios vacíos. Laravel los normaliza a `NULL`.

Para modalidad honorarios/pago por hora, `PayrollService::calculate()` no devolvía algunos campos NOT NULL — específicamente `bonuses`, con el mismo riesgo para `non_taxable_allowances`, `advances` y `other_deductions`.

Esos `NULL` quedaban explícitos en los datos usados por `MassAssignment::create()`, por lo que MySQL rechazaba el INSERT por `payroll_records.bonuses NOT NULL`.

Causa raíz comprobada:
normalización incompleta de campos monetarios nullable/vacíos en el cálculo manual de payroll horario/honorarios antes del INSERT.

Corrección requerida y aplicada en el release actual:
- Código: SI.
- BD: NO.

## 13. Fix integrado del 500

Commit funcional original local:
`2b11a09000c54441d087403b4182a805967792df`

Después de `git rebase origin/main`, HEAD funcional final:
`ec1d51160b8899b7351950fc1202f157d72e42c4`

Cambio funcional:
- `app/Services/PayrollService.php`: normaliza `bonuses`, `non_taxable_allowances`, `advances` y `other_deductions` a valores numéricos en `calculate()` y los devuelve también para modalidad honorarios/pago por hora.

Test de regresión:
- `tests/Feature/PayrollTimeEntryTraceTest.php` envía esos campos vacíos, verifica persistencia en `0.0`, 10 h aprobadas y trazabilidad.

Test dirigido:
`php artisan test tests\Feature\PayrollTimeEntryTraceTest.php`

Resultado:
- PASS;
- 10 tests;
- 92 assertions.

## 14. Integración, build y tag del fix `bonuses = NULL`

Integración:
- `origin/main` inicial: `5f25be96fb0b15f32308f36f4bd2dc77f88ceeaf`;
- estrategia: `git rebase origin/main`;
- conflicto: solo `docs/HANDOFF.md`;
- `docs/HANDOFF-LATEST.md`: eliminado y no debe reaparecer;
- HEAD funcional final: `ec1d51160b8899b7351950fc1202f157d72e42c4`.

Compare contra `3efcf37...`:
- runtime: `app/Services/PayrollService.php`;
- tests: `tests/Feature/PayrollTimeEntryTraceTest.php`;
- docs: `docs/HANDOFF.md`, eliminación de `docs/HANDOFF-LATEST.md`;
- tooling: ninguno;
- `public/`: sin cambios.

Build staging completo:
- PASS, exit code 0;
- `manifest.git_commit`: `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- `app-private.zip`: PASS `unzip -t`;
- `public.zip`: PASS `unzip -t`;
- secret scan: PASS;
- SQL validation: PASS;
- checksums: PASS;
- APP_ROOT: `/home/tdatcons/apps/flujo-caja-staging`.

Tag:
`cpanel-staging-20260903-124424`

Tag SHA:
`ec1d51160b8899b7351950fc1202f157d72e42c4`

Main avanzó después solo por checkpoint documental; eso NO invalida el release ni requiere rebuild.

## 15. Deploy staging del fix `bonuses = NULL` — COMPLETADO 2026-09-03

El usuario confirmó manualmente `deploy listo` después de subir/extractar el `app-private.zip` del release:
- tag `cpanel-staging-20260903-124424`;
- commit funcional `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- APP_ROOT `/home/tdatcons/apps/flujo-caja-staging`.

Reglas del deploy confirmado:
- solo `app-private.zip`;
- `public.zip` NO requerido;
- SQL NO requerido;
- migración nueva NO requerida;
- preservar `.env`;
- preservar `storage/`;
- preservar data QA.

No repetir build ni deploy antes de la UAT puntual salvo evidencia de artefacto incorrecto.

## 16. Próximo paso EXACTO — SOLO Remuneración A

Ejecutar únicamente esta re-prueba en staging:
- Persona: `QA-A-PERSONA UAT QA`;
- período: `2026-09-01`;
- proyecto: `QA-A-PROYECTO`;
- base: `Bruto`;
- fecha pago: `2026-09-30`.

Validar:
1. Proyecto habilitado;
2. 10 h reconocidas por backend/calculo;
3. guardado sin 500;
4. ID/código generado;
5. cálculo generado;
6. trazabilidad hacia las horas aprobadas.

Antes de crear, comprobar que no exista ya un payroll QA-A para septiembre generado por un intento previo exitoso; no duplicar registros.

Si A FAIL:
- detenerse inmediatamente;
- no ejecutar B;
- no ejecutar Rentabilidad;
- obtener error exacto/evidencia y diagnosticar solo ese punto.

Si A PASS:
- checkpoint inmediato en `docs/HANDOFF.md`;
- luego recién ejecutar Remuneración B;
- solo si B PASS, ejecutar Rentabilidad.

NO repetir Presupuesto, Responsable Proyecto, Centros de costo, Horas labels ni otros módulos ya PASS.

UAT FINAL sigue PENDIENTE hasta cerrar A, B y Rentabilidad.
Producción sigue NO autorizada hasta UAT FINAL PASS.

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
UAT FINAL = FAIL/PENDIENTE.
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

## 19. UAT Remuneración A post-fix — PASS 2026-09-03

Release probado en staging:
- tag `cpanel-staging-20260903-124424`;
- commit funcional `ec1d51160b8899b7351950fc1202f157d72e42c4`.

Precheck:
- payroll QA-A previo para septiembre: NO.

Resultado Remuneración A:
- Proyecto habilitado: PASS;
- Proyecto seleccionado: `QA-A-PROYECTO`;
- horas reconocidas: 10 h;
- guardado: PASS, sin 500;
- URL final: `https://licitaciones.tdatconsulting.cl/operacion/payroll-records/13`;
- ID/código: `REM-000013`;
- cálculo: PASS;
- bruto: `$ 400.000`;
- valor HH: `$ 40.000`;
- bonos: `$ 0`;
- no imponibles: `$ 0`;
- anticipos: `$ 0`;
- otros descuentos: `$ 0`;
- retención honorarios: `$ 61.000`;
- líquido/neto: `$ 339.000`;
- costo empresa: `$ 400.000`;
- estado cálculo: `OK`.

Trazabilidad: PASS.
Modal `Ver cálculo` confirma:
- `Horas aprobadas del período 10 h`;
- `Horas aprobadas efectivas 10 h`;
- `Horas proyecto 10 h`;
- fuente `Módulo Horas`;
- proyecto `PRY-000009`;
- cliente `QA-A-CLIENTE`;
- asignación `ASI-000022 · QA-A-PROYECTO`;
- vigencia `01/09/2026 al 30/09/2026`.

Errores: ninguno.

Conclusión:
- Remuneración A = PASS;
- el 500 `bonuses = NULL` queda funcionalmente resuelto en staging para el escenario QA-A;
- NO repetir Remuneración A;
- preservar `REM-000013` y no crear duplicado.

## 20. Próximo paso EXACTO — SOLO Remuneración B

Ejecutar únicamente Remuneración B en staging.

Datos esperados:
- Persona: `QA-B-PERSONA`;
- período: septiembre 2026 / `2026-09-01`;
- Proyecto: `QA-B-PROYECTO`;
- horas aprobadas esperadas: 6 h;
- valor HH esperado: CLP 50.000;
- ajuste esperado: `QA-B-AJUSTE`;
- tipo: bono imponible;
- monto: `$10.000`.

Antes de crear, comprobar que no exista payroll QA-B previo para septiembre. No duplicar.

Validar:
1. Proyecto habilitado y seleccionable;
2. 6 h reconocidas;
3. `QA-B-AJUSTE` aplicado por `$10.000`;
4. guardado sin 500;
5. ID/código generado;
6. cálculo coherente;
7. trazabilidad de horas y novedad.

Si B FAIL:
- detenerse;
- NO ejecutar Rentabilidad;
- diagnosticar solo el fallo nuevo.

Si B PASS:
- checkpoint inmediato en `docs/HANDOFF.md`;
- luego ejecutar únicamente Rentabilidad para QA-A y QA-B.

UAT FINAL sigue PENDIENTE hasta Remuneración B PASS + Rentabilidad PASS.
Producción sigue NO autorizada.

## 21. UAT Remuneración B post-fix — PASS 2026-09-03

Precheck:
- payroll QA-B previo para septiembre: NO.

Resultado Remuneración B:
- Proyecto habilitado: PASS;
- Proyecto seleccionado: `QA-B-PROYECTO`;
- horas reconocidas: 6 h;
- guardado: PASS, sin 500;
- URL final: `https://licitaciones.tdatconsulting.cl/operacion/payroll-records/14`;
- ID/código: `REM-000014`;
- valor HH: `$ 50.000`;
- bruto: `$ 300.000`;
- bono imponible: `$ 10.000`;
- ajuste: `QA-B-AJUSTE`, detectado automáticamente desde Novedades remuneración y aplicado una sola vez;
- no imponibles: `$ 0`;
- retención honorarios: `$ 45.750` (15,25 %);
- anticipos: `$ 0`;
- otros descuentos: `$ 0`;
- líquido/neto: `$ 254.250`;
- costo empresa mostrado: `$ 300.000`;
- estado cálculo: `OK`.

Trazabilidad: PASS.
Modal `Ver cálculo` confirma:
- `Horas aprobadas del período 6 h`;
- `Horas aprobadas efectivas 6 h`;
- `Horas proyecto 6 h`;
- fuente `Módulo Horas`;
- proyecto `QA-B-PROYECTO / PRY-000010`;
- cliente `QA-B-CLIENTE`;
- asignación `ASI-000023 · QA-B-PROYECTO`;
- vigencia `01/09/2026 al 30/09/2026`;
- tarifa efectiva `$ 50.000 / HH`;
- bonos automáticos `$ 10.000 · Novedades remuneración`;
- bonos aplicados `$ 10.000`;
- horas internas `0 h`;
- costo no asignado `$ 0`.

Errores: ninguno.

Conclusión:
- Remuneración B = PASS;
- Remuneraciones A y B = PASS;
- preservar `REM-000013` y `REM-000014`; no crear duplicados.

## 22. Próximo paso EXACTO — SOLO Rentabilidad

Ejecutar únicamente Rentabilidad en staging para:
- `QA-A-PROYECTO`;
- `QA-B-PROYECTO`.

No modificar payrolls A/B. No repetir Remuneraciones ni otros módulos.

Objetivo:
- confirmar que el costo laboral ya no sea `$0` en ambos proyectos;
- capturar valores exactos mostrados por la aplicación para costo laboral, margen y margen %;
- validar coherencia con `REM-000013` y `REM-000014` sin imponer cifras calculadas manualmente si la aplicación incorpora otros componentes.

Referencia histórica antes de payroll:
- QA-A: costo laboral `$0`, margen `$900.000`, `90 %`;
- QA-B: costo laboral `$0`, margen `$300.000`, `60 %`.

Si ambos proyectos muestran costo laboral distinto de `$0` y márgenes coherentes con los payrolls reales, Rentabilidad = PASS y corresponde cerrar UAT FINAL como PASS.

Si alguno falla o sigue con costo laboral `$0`, detenerse y diagnosticar solo Rentabilidad.

Producción permanece NO autorizada hasta confirmar Rentabilidad PASS y registrar cierre UAT FINAL.
