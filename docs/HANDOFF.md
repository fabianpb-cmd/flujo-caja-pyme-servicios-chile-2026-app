# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-01 / 2026-09-02 UTC según logs de staging.

Este archivo es la fuente de continuidad entre cuentas de ChatGPT/Codex. Debe actualizarse cada vez que cambie el estado real del proyecto, una decisión técnica, un despliegue, una prueba, un incidente, un commit, un tag, una migración, la base de datos o el próximo paso. No guardar secretos.

## 1. Repositorio y entornos

Repositorio GitHub:
`fabianpb-cmd/flujo-caja-pyme-servicios-chile-2026-app`

Aplicación: Laravel.

Staging:
`https://licitaciones.tdatconsulting.cl`

APP_ROOT staging usado por el release cPanel:
`/home/tdatcons/apps/flujo-caja-staging`

Modo de hosting/deploy conocido:
- cPanel
- sin SSH remoto como flujo operativo normal
- sin Composer remoto
- sin Artisan remoto
- app privada fuera de `public_html`
- document root público separado
- `.env` y `storage/` deben persistir entre despliegues

Producción todavía NO ha sido desplegada en esta conversación.

## 2. Release base anterior

Commit/release previo aprobado para staging antes de las últimas correcciones:
`c24d3d3f37688e34818182aa30b6f5b1bdfa6bb1`

Mensaje:
`fix: require git commit in cpanel release manifest`

Tag previo:
`cpanel-staging-20260831-194817`

Ese release corrigió el build para que `manifest.git_commit` no quedara null en Windows/PHP y fallara si no podía determinarse el SHA.

Artefactos estándar del build:
- `app-private.zip`
- `public.zip`
- `staging-bootstrap.sql`
- `manifest.json`
- `checksums.txt`
- `.env.staging.template`
- `php-runtime-check.php`

`public.zip` contiene normalmente:
- `.htaccess`
- `css/`
- `css/app-dashboard.css`
- `favicon.ico`
- `index.php`
- `robots.txt`

Los secretos se excluyen del release. Nunca incluir `.env` real ni `storage/app/private/BOOTSTRAP_ADMIN_PASSWORD.txt`.

## 3. Migración importante existente

Migración:
`database/migrations/2026_08_31_000100_make_period_batch_id_not_nullable_on_time_entries.php`

Objetivo:
`time_entries.period_batch_id` pasa a no nullable.

Regla crítica para BD:
- `staging-bootstrap.sql` es un dump completo reconstruido/seeded, NO una migración incremental.
- NO importar bootstrap sobre una BD staging/productiva existente con datos que se deban conservar.
- En updates sobre BD existente: backup + migración incremental revisada.
- Nunca adivinar un backfill para `period_batch_id` si existieran NULL; inspeccionar primero.

## 4. Limpieza de base de datos realizada

Se limpió la BD de staging antes de la UAT final.

El usuario confirmó que todos los datos de negocio/operacionales existentes eran de prueba y podían eliminarse, excepto parametrizaciones/configuración base.

Se preservaron:
- empresa bootstrap
- administrador inicial
- catálogos
- parámetros empresa
- parámetros legales
- UF
- UTM
- tipos de cambio
- AFP y tasas
- tabla IUSC
- geografía
- configuraciones seed
- escenarios estándar
- migrations

Escenarios estándar preservados porque son parametrización seed:
- `CONSERVADOR` — Conservador
- `BASE` — Base
- `OPTIMISTA` — Optimista

No cambiar `APP_ENV` para forzar `uat:clear-data`; staging usa configuración production y ese comando está correctamente deshabilitado en production.

La limpieza directa por BD eliminó datos operacionales y dejó parametrizaciones.

Después de la limpieza se creó nueva data QA durante UAT. Esa data QA DEBE CONSERVARSE hasta cerrar las re-pruebas.

## 5. UAT completa ejecutada después de la limpieza

Se ejecutó una UAT final completa con dos escenarios end-to-end:

- Escenario A: BASE
- Escenario B: CONSERVADOR

### Escenario A — BASE

PASS inicial en:
- Clientes
- Personal
- Asignaciones
- Horas
- Facturas
- CxC
- Cobro parcial
- Cobro final
- Egresos
- CxP
- Cuenta Tesorería
- Movimientos

Datos relevantes:
- Cliente: `QA-A-CLIENTE`
- Proyecto: `QA-A-PROYECTO`
- Persona: `QA-A-PERSONA`
- Cuenta: `QA-A-CUENTA`
- Horas: batch `HOR-000022` a `HOR-000025`
- 4 días
- 10 h trabajadas
- 10 h aprobadas
- Valor HH: CLP 40.000
- Factura: `ING-000003`
- Neto: $1.000.000
- IVA: $190.000
- Total: $1.190.000
- Cobro parcial: $500.000 (`MOV-000004`)
- Cobro final: $690.000 (`MOV-000005`)
- CxC final: $0, Pagado
- Egreso: `EGR-000002`
- Neto: $100.000
- IVA: $19.000
- Total: $119.000
- CxP final: $0, Pagado

### Escenario B — CONSERVADOR

PASS inicial en:
- Clientes
- Personal
- Asignaciones
- Horas
- Novedades remuneración
- Factura pendiente
- CxC pendiente
- Egreso
- CxP parcial
- Escenario Conservador

Datos relevantes:
- Cliente: `QA-B-CLIENTE`
- Proyecto: `QA-B-PROYECTO`
- Persona: `QA-B-PERSONA`
- Ajuste: `QA-B-AJUSTE`
- Persona pago por hora, tarifa CLP 50.000
- Horas: batch `HOR-000026` a `HOR-000028`
- 3 días
- 6 h trabajadas/aprobadas
- Novedad: bono imponible $10.000
- Factura: `ING-000004`
- Neto: $500.000
- IVA: $95.000
- Total: $595.000
- Factura NO cobrada
- CxC pendiente: $595.000
- Egreso: `EGR-000003`
- Total: $238.000
- Pago parcial: $100.000
- CxP restante: $138.000

Configuración Conservador validada en UI:
- factor ventas 0,9
- costos 1,1
- retraso cobro 15 días
- nuevas contrataciones $500.000
- variación tarifas -5%

### Gestión

PASS:
- Obligaciones (pantalla carga; sin obligaciones generadas)
- Flujo de caja
- Escenarios
- Dashboard

Datos validados en flujo Sep 2026:
- ingreso real: $1.190.000
- egreso real: $219.000
- flujo real: $971.000
- CxC: $595.000
- CxP: $138.000

Dashboard coherente con esos valores.

### Administración

PASS:
- Usuarios admin
- Seguridad usuario normal
- Todos los mantenedores/catálogos en smoke

Usuario QA creado:
`QA-USER`
role=user

Comportamiento esperado y PASS:
- usuario normal puede entrar a módulos operacionales autorizados
- Administración / Usuarios -> 403
- mantenedores administrativos -> 403

CRUD representativo de mantenedor:
`QA-CENTRO-COSTO` creado/editado y dejado inactivo.

## 6. Incidencias originales encontradas por la UAT

La UAT completa quedó originalmente FAIL por estas incidencias:

### 6.1 Remuneraciones — bloqueante

Síntoma:
- seleccionar Persona + Período
- Proyecto quedaba deshabilitado con “Seleccione el período primero”
- guardar sin proyecto podía terminar en `500 Server Error`

Causa raíz inicial identificada:
JS de remuneraciones asumía período `dd/mm/yyyy`, pero el input `period_date` es `date` y entrega `yyyy-mm-dd`.

IMPORTANTE: después del fix de parsing ISO se comprobó en staging que el problema NO quedó completamente resuelto. Ver sección 17.

### 6.2 Responsable de Proyecto

Síntoma:
`QA-A-PROYECTO` y `QA-B-PROYECTO` se creaban seleccionando Responsable `Jaime Soriano`, pero listado mostraba `—`.

Causa raíz:
`manager_id` SÍ persistía, pero había colisión entre atributo histórico `manager` y relación inferida `manager()` para `manager_id`.

### 6.3 Presupuesto

Síntoma:
`/gestion/presupuesto` cargaba, pero no existía acción visible para alta de presupuesto.

El CRUD `budgets` ya existía en operational CRUD; faltaba navegación.

### 6.4 Label mantenedores

En `Centros de costo`, el botón genérico podía aparecer como `Registrar horas`.

Causa:
el hook `data-time-entry-submit-label` estaba presente en formularios que no eran Horas.

## 7. Commit de correcciones UAT

Commit funcional inicial:
`c2a3e97b6fdbc5d5a745e023ca040a5cf16222f6`

Mensaje:
`Fix staging UAT issues`

Correcciones:

### Remuneraciones
- parsing compatible ISO/CL del período
- habilitación pretendida de Proyecto
- validación funcional de `project_id` cuando existen horas aprobadas por remunerar
- evitar 500 por ese caso

Test dirigido:
`PayrollTimeEntryTraceTest`
Resultado: PASS, 10 tests / 88 assertions.

Estado posterior en browser staging: el parsing por sí solo NO resolvió el bloqueo del selector. Ver sección 17.

### Responsable Proyecto
- `manager_id` confirmado persistente
- relación explícita `projectManager()`
- `relation_name => projectManager` en config

Test:
`project_manager_id_persists`
PASS.

### Presupuesto
- botón visible `Nuevo presupuesto`
- apunta al CRUD existente `route('operational.create', 'budgets')`

Tests:
- `management_pages_render_for_authenticated_user` PASS
- `budget_can_be_created_updated` PASS

### Label mantenedores
- hook especial limitado a `time-entries`
- mantenedores genéricos muestran `Guardar`

Test:
`generic_maintainer_form` PASS.

Archivos modificados por `c2a3e97`:
- `app/Http/Requests/CrudResourceRequest.php`
- `app/Models/Project.php`
- `config/operational.php`
- `resources/views/management/budgets.blade.php`
- `resources/views/operational/form.blade.php`
- `tests/Feature/ManagementPagesTest.php`
- `tests/Feature/OperationalUiTest.php`
- `tests/Feature/PayrollTimeEntryTraceTest.php`

## 8. Release staging desplegado antes del fix final de payroll

HEAD funcional desplegado:
`c2a3e97b6fdbc5d5a745e023ca040a5cf16222f6`

Build staging:
PASS, code 0.

Tag staging:
`cpanel-staging-20260901-210242`

Tag apunta a:
`c2a3e97b6fdbc5d5a745e023ca040a5cf16222f6`

Deploy confirmado por comportamiento UI:
- `Nuevo presupuesto` visible
- Responsable `Jaime Soriano` visible correctamente
- label Centros de costo corregido
- labels Horas corregidos

No hubo cambios `public/` entre el release anterior y este commit, por lo que para ese update incremental bastó `app-private.zip`.

## 9. Diagnóstico del 500 por URL incorrecta

Las URLs `/operacion/{resource}/create` eran incorrectas. La ruta real es `/operacion/{resource}/crear`.

Como existe `Route::get('/{record}', 'show')`, Laravel interpretaba `create` como `{record}` y llamaba `show(..., 'create')`, produciendo TypeError porque `show()` exige `int $record`.

Conclusión:
- esos 500 NO eran regresiones funcionales;
- las re-pruebas correctas se hicieron con `/crear` / navegación UI.

Hardening opcional pendiente:
agregar `whereNumber('record')` para que URLs inválidas devuelvan 404 en vez de 500. No es el blocker actual.

## 10. Segunda re-prueba puntual con rutas correctas

### Responsable Proyecto
PASS. No repetir.

### Remuneración A
FAIL real:
- formulario abre;
- `QA-A-PROYECTO` existe en el select;
- Proyecto permanece disabled;
- placeholder sigue `Seleccione el período primero`;
- no se guardó payroll inválido.

### Remuneración B
FAIL real por el mismo selector.

### Rentabilidad
Pendiente/fail por dependencia de payroll:
- QA-A costo laboral `$0`, margen `$900.000`, `90 %`
- QA-B costo laboral `$0`, margen `$300.000`, `60 %`

### Presupuesto
PASS.
Presupuesto QA único creado:
- `QA-A-PROYECTO`
- Base
- período `01/09/2026`
- ingreso `$1.000.000`
- personal `$400.000`
- otros directos `$100.000`

### Centros de costo
PASS. Label `Guardar`.

### Horas
PASS.
- nueva carga `Registrar horas`
- edición `Guardar carga`

UAT final en ese punto: FAIL.
Apto producción: NO.

## 11. Causa raíz definitiva del selector Proyecto en Remuneraciones

`period_date` es realmente un `input type="text"`.

El JS escuchaba únicamente `change` para ejecutar `syncPayrollProjects()`. Al ingresar/seleccionar la fecha, el valor podía quedar actualizado por `input` antes de que se produjera `change`, por lo que la sincronización seguía evaluando `payrollPeriodInput.value` como vacío.

Observado:
- `period_date type`: `text`
- valor inicial: `""`
- `2026-09-01` y `01/09/2026` son reconocidos por `parsePayrollPeriodDate()`
- faltaba listener `input`
- ya existía listener `change`
- `assignment_ranges` de `QA-A-PROYECTO`: `person_id` correcto, `start_date: 2026-09-01`, `end_date: 2026-09-30`
- condición que quedaba verdadera: `!payrollPeriodInput?.value`

Fix mínimo:
```js
payrollPeriodInput?.addEventListener('input', syncPayrollProjects);
```

Se mantiene también el listener `change`.

Archivos funcionales modificados por este fix:
- `resources/views/operational/form.blade.php`
- `tests/Feature/OperationalUiTest.php`

Tests antes de integración:
- payroll manual ISO + project: PASS 1 test / 10 assertions
- OperationalUiTest dirigido: PASS 1 test / 22 assertions
- PayrollTimeEntryTraceTest completo: PASS 10 tests / 88 assertions

Commit local original antes de rebase:
`832f8a369682806659a936d7779b73ee641bc7e0`

## 12. Integración y push del fix definitivo

Estado inicial local:
`832f8a369682806659a936d7779b73ee641bc7e0`

`origin/main` antes de integrar:
`c2e7fc1c30d971b84d5507fe9dc138cbb5e89d0e`

Estrategia:
`git rebase origin/main`

Conflictos:
ninguno.

HEAD final integrado:
`4622b71f5455b30a9cf75cc90c5eb8f338d1be9d`

Tests post-integración:
- `PayrollTimeEntryTraceTest`: PASS, 10 tests / 88 assertions
- `OperationalUiTest` dirigido: PASS, 1 test / 22 assertions

Push:
PASS.

`origin/main` final confirmado:
`4622b71f5455b30a9cf75cc90c5eb8f338d1be9d`

HEAD local y `origin/main` coincidían exactamente en ese SHA al terminar la integración.

## 13. Build staging del HEAD integrado — BLOQUEADO / NO CERTIFICABLE

Se intentó build staging desde:
`4622b71f5455b30a9cf75cc90c5eb8f338d1be9d`

Primer intento:
FAIL porque MySQL local no estaba activo.

Se levantó MySQL en Laragon y se repitió.

Segundo intento:
- generó `app-private.zip` íntegro según `unzip -t`;
- `app-private.zip` contiene el listener `input` del fix;
- generó `public.zip` íntegro según `unzip -t`;
- generó SQL;
- APP_ROOT de `public.zip` confirmado como `/home/tdatcons/apps/flujo-caja-staging`;
- el script quedó colgado durante validaciones posteriores, aparentemente alrededor de `unzip -Z1` / `grep`;
- se interrumpió manualmente para no dejar proceso vivo.

Resultado certificable:
- exit code final: FAIL / `1`
- `manifest.json`: NO generado
- `checksums.txt`: NO generado
- secret scan completo: NO confirmado
- tag: NO creado
- deploy: NO realizado

IMPORTANTE:
NO desplegar los artefactos de ese build interrumpido aunque los ZIP abran correctamente. Se exige build completo con exit code 0.

## 14. Compare runtime del fix final

Desde el release funcional desplegado `c2a3e97b6fdbc5d5a745e023ca040a5cf16222f6` hasta el HEAD integrado `4622b71f5455b30a9cf75cc90c5eb8f338d1be9d`:

Cambio runtime funcional:
- `resources/views/operational/form.blade.php`

Cambio de test:
- `tests/Feature/OperationalUiTest.php`

Cambios docs no cuentan como runtime.

Cambios en `public/`:
ninguno.

Por lo tanto, cuando el build quede PASS, el deploy esperado es:
- subir `app-private.zip`;
- NO re-subir `public.zip` salvo que un compare posterior contradiga lo anterior;
- NO ejecutar SQL/migración nueva por este fix;
- preservar `.env`;
- preservar `storage/`;
- preservar toda la data QA.

## 15. Próximo paso EXACTO — diagnosticar solo el build colgado

NO tocar de nuevo el fix de Remuneraciones mientras sus tests sigan PASS.
NO repetir UAT.
NO crear tag todavía.
NO deploy.

Siguiente trabajo:
1. reproducir el build con MySQL local ya levantado;
2. instrumentar o ejecutar por separado las validaciones posteriores a creación de ZIP para identificar exactamente cuál comando se queda colgado;
3. revisar en particular `assert_zip_contains`, `assert_zip_not_contains`, `scan_zip_for_forbidden_strings`, `validate_sql_dump`, `generate_manifest` y checksums;
4. medir cuál llamada a `unzip -Z1`, `unzip -p` o `grep` no termina en el entorno Windows/Git Bash;
5. no desactivar controles de seguridad para “hacerlo pasar”;
6. si el problema es solo una implementación ineficiente/no portable de una validación, corregir únicamente esa validación manteniendo el mismo control de seguridad;
7. ejecutar build completo hasta exit code 0;
8. verificar manifest.git_commit = HEAD integrado;
9. verificar checksums, secret scan, ZIPs y APP_ROOT;
10. crear tag staging únicamente después de build PASS;
11. luego desplegar `app-private.zip` y reprobar SOLO Remuneración A, Remuneración B y Rentabilidad.

## 16. Criterio de cierre UAT

Si después del deploy del fix final:
- Remuneración A PASS y consume 10 h aprobadas con trazabilidad;
- Remuneración B PASS y consume 6 h aprobadas, incorpora `QA-B-AJUSTE` $10.000 y deja trazabilidad;
- Rentabilidad deja costo laboral `$0` y refleja costos/márgenes coherentes;

entonces:
- Remuneraciones PASS
- Rentabilidad PASS
- Escenario A end-to-end PASS
- Escenario B end-to-end PASS
- UAT FINAL PASS
- funcionalmente apto para producción: SI

No repetir:
- Responsable Proyecto
- Presupuesto
- Centros de costo
- Horas labels
- Clientes
- Personal
- Asignaciones
- Facturas
- CxC
- Egresos
- CxP
- Caja
- Dashboard
- Usuarios
- Seguridad
- Catálogos
- Escenarios
- Flujo

## 17. Producción — todavía pendiente

No hacer producción hasta cerrar UAT.

Cuando UAT quede PASS:
- no repetir QA funcional completa
- preparar release production
- verificar plantilla/env production existente antes de asumir
- backup antes del deploy
- no importar bootstrap sobre una BD productiva existente con datos
- usar migración incremental revisada si correspondiera
- validar APP_ROOT real production
- validar manifest/checksums/ZIP/secrets
- smoke post-deploy mínimo: `/up`, `/login`, login, 2FA, dashboard

## 18. Pendiente técnico post-UAT — optimizar empaquetado staging

El build actual tarda porque reconstruye release completo: copia árbol privado, limpia temporales, ejecuta `composer install --no-dev --optimize-autoloader`, comprime `vendor/`, genera `public.zip`, regenera `staging-bootstrap.sql`, valida contenido, escanea ZIPs y calcula checksums.

Decisión:
NO optimizarlo durante el cierre del blocker actual, salvo que sea imprescindible corregir una validación que se queda colgada.

Después de cerrar UAT:
- diseñar modo rápido staging incremental, por ejemplo `CPANEL_FAST_BUILD=true`;
- evitar regenerar bootstrap SQL cuando no hubo cambios de BD y el update no lo necesita;
- evaluar reutilizar dependencias/artefactos seguros;
- mantener build COMPLETO y todas las validaciones para release candidato final y producción;
- medir tiempo antes/después.

## 19. Reglas de continuidad y operación

- `docs/HANDOFF.md` es la ÚNICA fuente de verdad entre cuentas.
- Actualizarlo cada vez que perder el estado obligaría a repetir trabajo.
- Registrar CONFIRMADO / PARCIALMENTE CONFIRMADO / PENDIENTE.
- No guardar contraseñas, `.env`, APP_KEY, credenciales BD ni códigos 2FA.
- No repetir trabajo que ya pasó.
- Diagnosticar con evidencia antes de modificar código.
- Para Codex, recomendar siempre modelo + esfuerzo y priorizar menor gasto de créditos sin comprometer la tarea.
- Para tareas acotadas, preferir GPT-5.4 Mini + Bajo/Medio; escalar solo ante investigación realmente compleja.

## 20. Checkpoint ajuste build staging Windows

Estado confirmado:
- fix payroll integrado y pusheado en `origin/main`;
- HEAD integrado antes del ajuste de build: `4622b71f5455b30a9cf75cc90c5eb8f338d1be9d`;
- contiene documentación HANDOFF remota y el listener `payrollPeriodInput?.addEventListener('input', syncPayrollProjects);`;
- tests dirigidos post-integración PASS:
  - `PayrollTimeEntryTraceTest`: 10 tests / 88 assertions;
  - `OperationalUiTest --filter=payroll_project_options_and_backend_require_assignment_for_period`: 1 test / 22 assertions.

Diagnóstico del build staging en Windows/Git Bash:
- `unzip -Z1 dist/cpanel-staging/app-private.zip`: PASS, ~1.6 s;
- `unzip -Z1 dist/cpanel-staging/public.zip`: PASS, ~0.5 s;
- `assert_zip_contains`: PASS, ~0.5 s por assert observado;
- `assert_zip_not_contains`: PASS, ~0.7 s observado para `app-private.zip`;
- causa del hang: `scan_zip_for_forbidden_strings()` abría `app-private.zip` una vez por cada archivo textual y hacía `unzip -p ... | grep ...`; con miles de archivos de `vendor/`, el scan completo no terminó dentro de 90 s (`timeout`, code 124);
- medición de muestra previa: 200 archivos textuales tardaron ~13.2 s;
- no se confirmó problema en `assert_zip_not_contains`.

Ajuste de build:
- archivo: `scripts/build-cpanel-release.sh`;
- se mantiene el mismo objetivo de seguridad y los mismos patrones prohibidos:
  - `/Users/`;
  - `/Applications/`;
  - `127.0.0.1:8000`;
  - `/private/var/folders/`;
  - `/var/folders/`;
- se mantienen las extensiones textuales escaneadas:
  - `.php`, `.blade.php`, `.json`, `.xml`, `.txt`, `.md`, `.css`, `.js`, `.html`, `.htaccess`, `.env`, `.sql`;
- implementación nueva: PHP `ZipArchive` abre cada ZIP una sola vez, itera entradas textuales y busca los mismos strings prohibidos sin extraer al workspace permanente;
- se conserva fallback `php -d extension=zip` cuando `ZipArchive` no está cargado por defecto en Windows/Git Bash.

Medición después del ajuste:
- `app-private.zip`: 7.519 entradas textuales escaneadas, PASS, ~1 s;
- `public.zip`: 4 entradas textuales escaneadas, PASS, <1 s.

Build staging completo con el ajuste:
- resultado: PASS, exit code 0;
- release generado observado: `cpanel-staging-20260901-222546`;
- manifest observado antes de commitear el ajuste de build:
  - `manifest.git_commit`: `4622b71f5455b30a9cf75cc90c5eb8f338d1be9d`;
  - `requires_db_migration`: `true`;
  - `bootstrap_sql`: `staging-bootstrap.sql`;
- `app-private.zip`: PASS `unzip -t`;
- `public.zip`: PASS `unzip -t`;
- `checksums.txt`: PASS con `shasum -a 256 -c`;
- secret scan: PASS dentro del build y verificado con la medición de la nueva implementación;
- APP_ROOT en `public/index.php`: `/home/tdatcons/apps/flujo-caja-staging`.

Importante:
- después de commitear este ajuste de build y este HANDOFF, se debe ejecutar nuevamente el build completo para que `manifest.git_commit` apunte al SHA final del commit de build;
- solo después de ese build final PASS y push confirmado corresponde crear tag `cpanel-staging-YYYYMMDD-HHMMSS`;
- no desplegar automáticamente;
- deploy posterior esperado para este fix funcional: subir `app-private.zip`, no subir `public.zip` salvo cambio inesperado en `public/`, no importar SQL/bootstrap, no limpiar BD, preservar `.env`, `storage/` y data QA.
