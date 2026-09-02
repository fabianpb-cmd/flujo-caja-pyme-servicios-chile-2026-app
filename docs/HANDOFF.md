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

Commit funcional:
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

No se ejecutó suite completa porque los tests dirigidos eran suficientes para las correcciones acotadas.

## 8. Release staging actual

HEAD del release funcional desplegado:
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

Push funcional confirmado:
- `origin/main` estuvo en `c2a3e97b6fdbc5d5a745e023ca040a5cf16222f6`
- tag remoto `cpanel-staging-20260901-210242` -> mismo SHA

Después se agregaron commits SOLO de documentación HANDOFF en `main`; eso no cambia el código funcional desplegado.

## 9. Deploy staging de c2a3e97

Deploy confirmado por comportamiento de UI.

Evidencia de que el código nuevo está activo:
- `Nuevo presupuesto` aparece en Gestión / Presupuesto
- Responsable `Jaime Soriano` ahora aparece correctamente en proyectos
- label Centros de costo corregido
- labels Horas corregidos

Para este update incremental NO era necesario volver a desplegar `public.zip`, porque entre `c24d3d3` y `c2a3e97` no cambió ningún archivo dentro de `public/`.

El cambio funcional estaba en `app-private.zip`.

Reglas mantenidas:
- NO importar `staging-bootstrap.sql`
- NO limpiar BD
- preservar `.env`
- preservar `storage/`

## 10. Primera re-prueba después de deploy

Codex reportó inicialmente:

Responsable proyecto: PASS
- `QA-A-PROYECTO` y `QA-B-PROYECTO` muestran `Jaime Soriano` en listado y detalle.

Reportó 500 en URLs `/create`, pero luego se comprobó que esas URLs eran incorrectas y no representaban fallas funcionales reales.

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
- los 500 de `/create` NO eran regresiones funcionales del release;
- la re-prueba usó rutas incorrectas;
- las pruebas reales se repitieron con `/crear` y navegación UI. Ver sección 17.

Robustez pendiente opcional:
sería razonable agregar una restricción numérica `whereNumber('record')` a las rutas show/edit/update/destroy para que una URL inválida como `/create` devuelva 404 y no 500. Esto es hardening, no el blocker actual.

## 12. Criterio de re-prueba después del diagnóstico de rutas

No repetir UAT completa.

Se definió reprobar únicamente:
- Remuneración A
- Remuneración B
- Rentabilidad posterior
- Presupuesto
- Centros de costo label
- Horas labels

Responsable Proyecto quedó PASS y no se debía repetir.

## 13. Criterio de cierre UAT

Para cerrar UAT deben pasar:
- Remuneración A
- Remuneración B
- Rentabilidad con costo laboral
- Presupuesto
- Centro de costo label
- Horas label/regresión
- Responsable Proyecto se mantiene PASS

Si todo lo anterior pasa:
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

## 16. Commits de documentación HANDOFF

Primer checkpoint HANDOFF remoto:
`1e2b740e4f7973f9d0aabc06b9693c34acfe1575`

Mensaje:
`docs: add project handoff checkpoint`

Ese commit agregó `docs/HANDOFF.md` y NO modifica código funcional del release staging.

## 17. Segunda re-prueba puntual con rutas correctas — ESTADO ACTUAL

Se repitieron las pruebas usando las rutas reales `/crear` y/o navegación UI.

### 17.1 Responsable Proyecto

Estado: PASS.

`QA-A-PROYECTO` y `QA-B-PROYECTO` muestran `Jaime Soriano` en listado y detalle.

No volver a repetir salvo dependencia nueva.

### 17.2 Remuneración A — BLOCKER REAL

Formulario:
PASS.

Ruta correcta:
`/operacion/payroll-records/crear`

Datos usados:
- Persona: `QA-A-PERSONA`
- Período: `2026-09-01`
- Proyecto esperado: `QA-A-PROYECTO`

Resultado:
- `QA-A-PROYECTO` aparece en el `<select>` de Proyecto;
- la opción sigue `hidden/disabled`;
- el selector Proyecto completo permanece deshabilitado;
- mensaje visible continúa en `Seleccione el período primero`;
- no hay error visible ni 500;
- NO se guardó un payroll inválido.

Horas:
no validables por bloqueo del selector.

Resultado cálculo:
no generado.

Trazabilidad:
no validable.

Estado: FAIL.

Conclusión importante:
el fix de parsing ISO introducido en `c2a3e97` NO fue suficiente para habilitar correctamente proyectos en browser staging. El blocker de Remuneraciones es real y debe diagnosticarse en la lógica JS/estado del formulario, no en rutas.

### 17.3 Remuneración B — BLOCKER REAL

Formulario:
PASS.

Mismo comportamiento del selector de Proyecto que en A.

Datos esperados:
- `QA-B-PERSONA`
- `QA-B-PROYECTO`
- 6 h aprobadas
- ajuste `QA-B-AJUSTE` $10.000

No fue posible validar horas, ajuste, cálculo ni trazabilidad porque Proyecto no se habilita.

Estado: FAIL.

### 17.4 Rentabilidad

Sigue pendiente por dependencia de Payroll.

Valores observados:
- QA-A costo laboral: `$0`
- QA-A margen: `$900.000`, `90 %`
- QA-B costo laboral: `$0`
- QA-B margen: `$300.000`, `60 %`

Estado: FAIL por dependencia de Remuneraciones.

No interpretar estos márgenes como resultado final mientras no existan payroll A/B.

### 17.5 Presupuesto

Estado: PASS.

Formulario: PASS.
Creación: PASS.
Persistencia: PASS.

Se creó UN único presupuesto QA:
- Proyecto: `QA-A-PROYECTO`
- Escenario: Base
- Período: `01/09/2026`
- Ingreso presupuesto: `$1.000.000`
- Personal presupuesto: `$400.000`
- Otros directos: `$100.000`

Aparece correctamente en Gestión -> Presupuesto.

NO crear presupuestos QA adicionales salvo necesidad real.

### 17.6 Centros de costo

Estado: PASS.

Label observado:
`Guardar`

La incidencia `Registrar horas` en mantenedor quedó resuelta.

NO repetir.

### 17.7 Horas — regresión UI

Estado: PASS.

Nueva carga:
`Registrar horas`

Editar carga:
`Guardar carga`

No se crearon horas adicionales solo para esta comprobación.

NO repetir.

### 17.8 UAT final actual

UAT FINAL: FAIL.

Apto para producción: NO.

Único blocker funcional pendiente de la UAT original/correcciones:
REMUNERACIONES — selector Proyecto permanece deshabilitado aunque Persona + Período correctos estén seleccionados y la opción de proyecto exista en el DOM.

Rentabilidad queda pendiente exclusivamente como consecuencia de ese blocker.

## 18. Próximo paso EXACTO

NO repetir:
- Responsable Proyecto
- Presupuesto
- Centros de costo
- Horas labels
- módulos completos ya PASS

Siguiente trabajo:
1. diagnosticar de forma dirigida el selector Proyecto de Remuneraciones;
2. inspeccionar `resources/views/operational/form.blade.php` en la lógica `syncPayrollProjects`, `syncPayrollUi`, `projectAvailableForPayroll`, listeners de `period_date` y generación de `data-assignment-ranges`/datos del option;
3. confirmar en browser/HTML cuál es exactamente el `value` real de `period_date` y qué contiene `data-assignment-ranges` para `QA-A-PROYECTO` y `QA-B-PROYECTO`;
4. revisar si algún segundo bloque JS vuelve a deshabilitar Proyecto después del nuevo parser ISO;
5. corregir SOLO la causa comprobada;
6. agregar test dirigido que reproduzca el comportamiento real del formulario/JS en la medida posible y test backend de guardado/trazabilidad;
7. hacer commit local, build/release staging nuevo, deploy incremental y repetir SOLO Remuneración A, Remuneración B y Rentabilidad.

NO modificar Presupuesto/Responsable/Labels ya PASS.
NO limpiar BD.
NO importar bootstrap.
NO producción hasta que payroll + rentabilidad pasen.
