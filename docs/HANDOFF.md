# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-03.

ÚNICA fuente de continuidad entre cuentas de ChatGPT/Codex. Leer este archivo al retomar el proyecto y continuar desde el estado actual. NO repetir tareas ya cerradas. No guardar secretos.

## 1. Repositorio y entorno

Repositorio: `fabianpb-cmd/flujo-caja-pyme-servicios-chile-2026-app`
Aplicación: Laravel.
Dominio PRODUCCIÓN: `https://licitaciones.tdatconsulting.cl`
PUBLIC_ROOT: `/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`
APP_ROOT actual: `/home/tdatcons/apps/flujo-caja-staging`

Promoción in-place. Evitar mover APP_ROOT si no aporta beneficio claro.
Hosting cPanel, sin SSH/Composer/Artisan remoto como flujo normal. Preservar `.env` y `storage/`.

## 2. Estado funcional — UAT FINAL PASS

Release funcional probado:
- commit `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- tag `cpanel-staging-20260903-124424`;
- build, ZIPs, secret scan, SQL validation y checksums: PASS;
- Remuneración A/B, Rentabilidad, escenarios E2E y UAT FINAL: PASS.

Funcionalmente apto para producción: SI. No repetir UAT.

## 3. Seguridad BD / deploy

BD actual se PROMUEVE y se conserva.
NO crear BD productiva separada.
NO importar bootstrap SQL sobre la BD actual.
No desactivar FKs a ciegas.
Preservar APP_KEY y credenciales BD actuales.

Backups previos al cutover — TODOS COMPLETADOS por Miguel el 2026-09-03:
- BD actual;
- APP_ROOT;
- PUBLIC_ROOT;
- `.env` actual.

## 4. Production readiness

cPanel confirmado:
- host `licitaciones.tdatconsulting.cl`;
- HTTPS redirect activo;
- `bootstrap/cache` no contiene `config.php`.

`.env` actual ya productivo:
- `APP_ENV=production`;
- `APP_DEBUG=false`;
- `APP_URL=https://licitaciones.tdatconsulting.cl`;
- `LOG_LEVEL=warning`;
- sesiones/cookies seguras;
- `QUEUE_CONNECTION=sync`;
- APP_KEY presente y se preserva.

No hace falta editar `.env` para la promoción.

## 5. Decisión de limpieza pre-go-live — 2026-09-03

Miguel decidió: ELIMINAR antes del go-live todos los datos QA/UAT y dejar solo el baseline paramétrico/productivo.

Baseline que se debe PRESERVAR:
- catálogos de sistema;
- parámetros legales/tributarios/previsionales;
- geografía Chile;
- tablas de impuesto a la renta;
- catálogos operacionales y configuraciones por empresa necesarios para que la aplicación funcione;
- empresa base productiva;
- al menos un usuario administrador real de producción.

Datos a ELIMINAR:
- `QA-A-*`, `QA-B-*`, `QA-USER`, `QA-CENTRO-COSTO`;
- `REM-000013`, `REM-000014`;
- cualquier otro dato UAT/QA;
- cualquier dato DEMO/local si existe;
- datos transaccionales/operacionales no paramétricos creados para pruebas.

Referencia repo:
- `DatabaseSeeder` separa catálogos/bootstrap de `DemoDataSeeder`;
- DemoDataSeeder solo corresponde a local/testing;
- `SystemCatalogSeeder`, `IncomeTaxBracketSeeder`, `ChileGeographySeeder` y `OperationalCatalogSeeder` representan baseline paramétrico.

## 6. Empresa/admin productivos confirmados en BD — 2026-09-03

Capturas phpMyAdmin confirman:

`companies`:
- existe UNA sola empresa;
- `id=1`;
- `code=STAGING`;
- `name=Empresa Staging`;
- `status=active`.

`users`:
- `id=1`, `company_id=1`, `name=Administrador inicial`, email corporativo de Tdat, verificado: CONSERVAR como administrador productivo;
- `id=5`, `company_id=1`, `name=QA-USER`, email QA, no verificado: ELIMINAR durante limpieza.

Decisión operativa:
- conservar `company_id=1` y `user_id=1`;
- eliminar `user_id=5` una vez revisadas sus dependencias;
- el código/nombre `STAGING` de la empresa se puede renombrar más adelante si se desea, pero NO es requisito técnico para el go-live y no debe mezclarse con la limpieza de datos.

## 7. Reglas de limpieza

- NO DELETE todavía;
- NO borrar por patrones a ciegas;
- NO desactivar foreign keys globalmente;
- SQL incremental revisado, child-to-parent;
- primero SELECT/COUNT de previsualización;
- validar exactamente qué quedará;
- backup de BD disponible para rollback.

## 8. Próximo paso EXACTO

Ejecutar en phpMyAdmin una consulta de SOLO LECTURA para inventariar todas las tablas y cantidad aproximada de filas. Con ese inventario se clasifican tablas en PARAMÉTRICAS / SISTEMA / TRANSACCIONALES y se prepara el SQL de limpieza.

No ejecutar DELETE todavía.
Después de limpieza validada: smoke mínimo `/up`, `/login`, login, 2FA, dashboard.

## 9. Política de ahorro de créditos

- sin Codex para inspecciones manuales cPanel/phpMyAdmin;
- no repetir UAT/builds PASS;
- modelo económico + esfuerzo Bajo cuando Codex sea necesario;
- checkpoints compactos en este archivo.
