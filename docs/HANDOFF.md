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
- empresa base productiva necesaria para el tenant;
- al menos un usuario administrador REAL de producción para poder ingresar.

Datos a ELIMINAR:
- `QA-A-*`;
- `QA-B-*`;
- `QA-USER`;
- `QA-CENTRO-COSTO`;
- `REM-000013`;
- `REM-000014`;
- cualquier otro dato creado durante UAT/QA;
- cualquier dato DEMO/local si existe en la BD (`Empresa Demo`, `admin@flujo.local`, `RESP_JAIME`, `RESP_EMILIO`, posiciones IRIS/BI demo, `BANK-001`, etc.);
- datos transaccionales/operacionales no paramétricos creados para pruebas.

Referencia repo:
- `DatabaseSeeder` separa catálogos/bootstrap de `DemoDataSeeder`; DemoDataSeeder solo corresponde a local/testing;
- `BootstrapCompanySeeder` crea empresa + admin inicial si existen variables bootstrap;
- `SystemCatalogSeeder`, `IncomeTaxBracketSeeder`, `ChileGeographySeeder` y `OperationalCatalogSeeder` representan baseline paramétrico.

Reglas de limpieza:
- NO borrar por patrones a ciegas sin revisar dependencias;
- NO desactivar foreign keys globalmente;
- usar SQL incremental revisado, child-to-parent;
- primero ejecutar consultas SELECT/COUNT de previsualización;
- ejecutar DELETE solo después de validar exactamente qué quedará;
- backup de BD ya existe para rollback.

## 6. Próximo paso EXACTO — identificar empresa/admin a conservar y preparar SQL de limpieza

Antes de cualquier DELETE:
1. identificar `company` productiva real y usuario admin real a conservar;
2. inventariar datos no paramétricos actuales y dependencias;
3. preparar SQL de previsualización (SELECT/COUNT) y luego SQL de limpieza ordenado por FKs;
4. revisar que el resultado esperado sea catálogos + empresa + admin, sin QA/demo/transacciones;
5. solo entonces ejecutar en phpMyAdmin;
6. smoke mínimo: `/up`, `/login`, login, 2FA, dashboard.

NO ejecutar DELETE todavía.
NO importar bootstrap.
NO mover APP_ROOT.

## 7. Política de ahorro de créditos

- sin Codex para inspecciones manuales cPanel;
- no repetir UAT/builds PASS;
- modelo económico + esfuerzo Bajo cuando Codex sea necesario;
- checkpoints compactos en este archivo.
