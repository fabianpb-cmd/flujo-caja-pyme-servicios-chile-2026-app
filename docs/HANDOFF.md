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

Causa raíz:
JS de remuneraciones asumía período `dd/mm/yyyy`, pero el input `period_date` es `date` y entrega `yyyy-mm-dd`.

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

Commit:
`c2a3e97b6fdbc5d5a745e023ca040a5cf16222f6`

Mensaje:
`Fix staging UAT issues`

Correcciones:

### Remuneraciones
- parsing compatible ISO/CL del período
- habilitación correcta de Proyecto
- validación funcional de `project_id` cuando existen horas aprobadas por remunerar
- evitar 500 por ese caso

Test dirigido:
`PayrollTimeEntryTraceTest`
Resultado: PASS, 10 tests / 88 assertions.

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

No se ejecutó suite completa porque los tests dirigidos eran suficientes para las correcciones acotadas.

## 8. Release staging actual

HEAD del release funcional:
`c2a3e97b6fdbc5d5a745e023ca040a5cf16222f6`

Build staging:
PASS, code 0.

Directorio:
`dist/cpanel-staging/`

`manifest.git_commit`:
`c2a3e97b6fdbc5d5a745e023ca040a5cf16222f6`

`requires_db_migration`:
`true`

`app-private.zip`:
PASS, íntegro.

`public.zip`:
PASS, íntegro.

Checksums:
- app-private.zip OK
- public.zip OK
- staging-bootstrap.sql OK
- manifest.json OK

Secretos excluidos:
PASS.

APP_ROOT generado en `public/index.php`:
`/home/tdatcons/apps/flujo-caja-staging`

Tag staging:
`cpanel-staging-20260901-210242`

Tag apunta a:
`c2a3e97b6fdbc5d5a745e023ca040a5cf16222f6`

Push confirmado:
- `origin/main` -> `c2a3e97b6fdbc5d5a745e023ca040a5cf16222f6`
- tag remoto `cpanel-staging-20260901-210242` -> mismo SHA

## 9. Deploy staging de c2a3e97

Deploy quedó PARCIALMENTE CONFIRMADO por UI.

Evidencia de que el código nuevo está activo:
- `Nuevo presupuesto` aparece en Gestión / Presupuesto
- Responsable `Jaime Soriano` ahora aparece correctamente en proyectos

Para este update incremental NO era necesario volver a desplegar `public.zip`, porque entre `c24d3d3` y `c2a3e97` no cambió ningún archivo dentro de `public/`.

El cambio funcional está en `app-private.zip`.

Reglas mantenidas:
- NO importar `staging-bootstrap.sql`
- NO limpiar BD
- preservar `.env`
- preservar `storage/`

## 10. Primera re-prueba después de deploy

Codex reportó:

Responsable proyecto: PASS
- `QA-A-PROYECTO` y `QA-B-PROYECTO` muestran `Jaime Soriano` en listado y detalle.

Inicialmente reportó FAIL/500 en:
- `/operacion/budgets/create`
- `/operacion/payroll-records/create`
- `/operacion/cost-centers/create`
- `/operacion/time-entries/create`

También dejó Rentabilidad pendiente porque no pudo generar payroll.

IMPORTANTE: estos 500 NO representan todavía una regresión funcional confirmada. El log de Laravel permitió identificar la causa exacta.

## 11. Diagnóstico exacto del supuesto 500 común

Log staging, 2026-09-02 01:32:29:

`OperationalCrudController::show(): Argument #3 ($record) must be of type int, string given`

El dispatcher llamó:

`OperationalCrudController->show(Request, 'cost-centers', 'create')`

Esto demuestra que se navegó a una URL inválida en inglés:

`/operacion/cost-centers/create`

Pero la ruta real del proyecto es en español:

`Route::get('/crear', 'create')->name('create');`

El grupo operacional es:

`/operacion/{resource}`

Por lo tanto las URLs correctas son:

- `/operacion/payroll-records/crear`
- `/operacion/budgets/crear`
- `/operacion/cost-centers/crear`
- `/operacion/time-entries/crear`

La URL `/operacion/{resource}/create` NO existe.

Como existe una ruta posterior:

`Route::get('/{record}', 'show')->name('show');`

Laravel interpreta literalmente `create` como `{record}` y llama `show(..., 'create')`. Como `show()` exige `int $record`, PHP produce TypeError y 500.

Conclusión:
- el supuesto bloqueo común de formularios NO está demostrado como bug funcional de `c2a3e97`;
- la re-prueba de Codex usó rutas incorrectas (`/create` en vez de `/crear`);
- se deben repetir SOLO esas re-pruebas usando navegación real de UI o `route('operational.create', ...)` / URL `/crear`.

Robustez pendiente opcional:
sería razonable agregar una restricción numérica `whereNumber('record')` a las rutas show/edit/update/destroy para que una URL inválida como `/create` devuelva 404 y no 500. Esto es mejora de robustez, no necesariamente bloqueante para producción, porque la UI genera `/crear`.

## 12. Próximas re-pruebas EXACTAS

NO repetir UAT completa.

Reprobar únicamente con rutas correctas o clics reales de UI:

### A. Responsable Proyecto
Estado actual: PASS.
No repetir salvo dependencia inesperada.

### B. Remuneración A
Abrir desde UI Remuneraciones -> Nuevo o:
`/operacion/payroll-records/crear`

Usar:
- `QA-A-PERSONA`
- período de las horas existentes
- `QA-A-PROYECTO`

Validar:
- formulario abre sin 500
- Proyecto se habilita
- proyecto correcto visible
- guardar funciona
- 10 h aprobadas consumidas
- cálculo correcto
- trazabilidad `payroll_record_time_entries`
- sin 500

### C. Remuneración B
Abrir:
`/operacion/payroll-records/crear`

Usar:
- `QA-B-PERSONA`
- `QA-B-PROYECTO`
- período correspondiente

Validar:
- 6 h aprobadas
- `QA-B-AJUSTE` $10.000 incorporado
- guardar sin 500
- trazabilidad correcta

### D. Rentabilidad
Después de generar payroll A/B:
- `QA-A-PROYECTO`
- `QA-B-PROYECTO`

deben incorporar costo laboral y dejar de mostrar costo laboral $0 por ausencia de payroll.
Validar margen y porcentaje coherentes.

### E. Presupuesto
Desde `/gestion/presupuesto`, hacer clic en `Nuevo presupuesto`.
La ruta correcta debe ser:
`/operacion/budgets/crear`

Crear UN presupuesto QA, guardar, verificar persistencia y navegación coherente.

### F. Centros de costo
Ruta correcta:
`/operacion/cost-centers/crear`

Confirmar botón `Guardar`, no `Registrar horas`.
No crear otro registro si no es necesario.

### G. Horas — regresión UI mínima
Ruta correcta:
`/operacion/time-entries/crear`

Confirmar:
- nueva carga -> `Registrar horas`
- editar batch existente -> `Guardar carga`

No crear nuevas horas si no es necesario.

## 13. Criterio de cierre UAT

Si pasan:
- Remuneración A
- Remuneración B
- Rentabilidad con costo laboral
- Presupuesto
- Centro de costo label
- Horas label/regresión

y Responsable Proyecto se mantiene PASS,

entonces:
- Escenario A end-to-end = PASS
- Escenario B end-to-end = PASS
- UAT COMPLETA FINAL = PASS
- Apto para producción = SI

NO volver a probar módulos ya PASS:
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

## 14. Producción — todavía pendiente

No hacer producción hasta cerrar UAT.

Cuando UAT quede PASS:
- no repetir QA funcional completa
- preparar release production
- verificar plantilla/env production existente antes de asumir
- backup antes del deploy
- no importar bootstrap sobre una BD productiva existente con datos
- usar migración incremental revisada en BD existente
- validar APP_ROOT real production
- validar manifest/checksums/ZIP/secrets
- smoke post-deploy mínimo:
  - `/up`
  - `/login`
  - login
  - 2FA
  - dashboard

## 15. Reglas de continuidad y operación

- No repetir trabajo que ya pasó.
- No ejecutar auditorías generales si el problema está acotado.
- Diagnosticar con evidencia antes de modificar código.
- Mantener `docs/HANDOFF.md` actualizado cada vez que perder el estado obligaría a repetir trabajo.
- Registrar explícitamente CONFIRMADO / PARCIALMENTE CONFIRMADO / PENDIENTE.
- No guardar contraseñas, `.env`, APP_KEY, credenciales BD ni códigos 2FA.
- Para Codex, recomendar siempre modelo + esfuerzo y priorizar menor gasto de créditos sin comprometer la tarea.
- Para tareas acotadas, preferir GPT-5.4 Mini + Bajo/Medio; escalar solo ante investigación realmente compleja.

## 16. Estado actual resumido

CONFIRMADO:
- `c2a3e97` pusheado a `origin/main`
- tag remoto `cpanel-staging-20260901-210242` correcto
- release build PASS
- código nuevo visible parcialmente en staging
- Responsable Proyecto PASS
- `Nuevo presupuesto` visible
- los 500 reportados sobre `/create` se deben a rutas incorrectas usadas en la re-prueba

PENDIENTE:
- repetir SOLO los create forms con `/crear` o desde la UI
- Remuneración A
- Remuneración B
- Rentabilidad posterior
- Presupuesto CRUD real
- label Centros de costo
- label Horas
- decidir si se agrega `whereNumber('record')` como hardening para que rutas inválidas devuelvan 404

NO HACER AHORA:
- UAT completa otra vez
- limpiar BD
- importar bootstrap
- producción
- cambios de código funcional sin antes repetir las re-pruebas con rutas correctas
