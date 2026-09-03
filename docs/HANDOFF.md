# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-03.

ÚNICA fuente de continuidad entre cuentas de ChatGPT/Codex. Leer este archivo al retomar el proyecto y continuar desde el estado actual. NO repetir tareas ya cerradas. No guardar secretos.

## 1. Repositorio y entorno

Repositorio:
`fabianpb-cmd/flujo-caja-pyme-servicios-chile-2026-app`

Aplicación: Laravel.

Dominio objetivo de PRODUCCIÓN confirmado por Miguel:
`https://licitaciones.tdatconsulting.cl`

PUBLIC_ROOT confirmado por cPanel:
`/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`

APP_ROOT actual:
`/home/tdatcons/apps/flujo-caja-staging`

Se hará promoción in-place. Evitar mover APP_ROOT si no aporta beneficio claro.

Hosting/deploy:
- cPanel;
- sin SSH/Composer/Artisan remoto como flujo normal;
- app privada fuera del document root público;
- preservar siempre `.env` y `storage/`;
- nunca importar bootstrap SQL sobre la BD actual.

## 2. Estado funcional — UAT FINAL PASS

Release funcional probado:
- commit: `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- tag: `cpanel-staging-20260903-124424`;
- build PASS;
- ZIPs, secret scan, SQL validation y checksums PASS.

Remuneración A/B, Rentabilidad, escenarios E2E y UAT FINAL: PASS.
Funcionalmente apto para promoción a producción: SI.
No repetir UAT.

## 3. Seguridad BD / deploy

BD actual se PROMUEVE y se conserva.
NO crear BD productiva separada.
NO importar `production-bootstrap.sql` ni `staging-bootstrap.sql`.
No ejecutar migraciones salvo cambio posterior que realmente las requiera.
Preservar APP_KEY y credenciales BD actuales.

Migraciones relevantes existentes:
- `database/migrations/2026_08_28_000100_create_payroll_record_time_entries_table.php`;
- `database/migrations/2026_08_31_000100_make_period_batch_id_not_nullable_on_time_entries.php`.

Backups previos al cutover — TODOS COMPLETADOS por Miguel el 2026-09-03:
- BD actual;
- APP_ROOT;
- PUBLIC_ROOT;
- `.env` actual.

## 4. Production readiness — hechos confirmados

cPanel:
- host: `licitaciones.tdatconsulting.cl`;
- PUBLIC_ROOT: `/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`;
- HTTPS redirect activado;
- APP_ROOT actual: `/home/tdatcons/apps/flujo-caja-staging`.

Decisiones:
- mismo dominio;
- misma BD;
- mismos datos;
- promoción in-place;
- no bootstrap SQL;
- no migración de datos a otra BD.

`bootstrap/cache` NO contiene `config.php`; la configuración no está cacheada con `config:cache`.

## 5. .env actual — YA PRODUCTIVO, no cambiar por ahora

Miguel verificó manualmente los valores no secretos del `.env` actual. Estado confirmado:
- `APP_ENV=production`;
- `APP_DEBUG=false`;
- `APP_URL=https://licitaciones.tdatconsulting.cl`;
- `LOG_LEVEL=warning`;
- `SESSION_DRIVER=database`;
- `SESSION_LIFETIME=30`;
- `SESSION_ABSOLUTE_LIFETIME=480`;
- `SESSION_EXPIRE_ON_CLOSE=true`;
- `SESSION_SECURE_COOKIE=true`;
- `SESSION_HTTP_ONLY=true`;
- `SESSION_SAME_SITE=lax`;
- `CACHE_STORE=database`;
- `QUEUE_CONNECTION=sync`;
- `FILESYSTEM_DISK=local`;
- `BROADCAST_CONNECTION=log`;
- `MAIL_MAILER=log`.

APP_KEY está presente; NO registrar su valor y NO cambiarla.
Credenciales BD se mantienen y NO se registran.

Conclusión: el `.env` actual ya cumple la configuración productiva relevante revisada. NO hace falta editarlo para la promoción.

## 6. Próximo paso EXACTO — decidir datos QA antes de declarar go-live

No hay cambio técnico de `.env` pendiente.
Antes de declarar formalmente el go-live, decidir explícitamente si los datos QA/UAT que hoy viven en la misma BD se mantienen en producción o se eliminan de forma controlada.

Datos QA conocidos a considerar incluyen `QA-A-*`, `QA-B-*`, `QA-USER`, `QA-CENTRO-COSTO`, `REM-000013` y `REM-000014`.

NO borrar nada sin decisión explícita.
NO build production por ahora.
NO generar/importar SQL.
NO mover APP_ROOT.

Después de esa decisión: smoke mínimo de producción (`/up`, `/login`, login, 2FA, dashboard) y checkpoint final.

## 7. Política de ahorro de créditos

- sin Codex para inspecciones manuales de cPanel;
- no repetir UAT/builds PASS;
- modelo económico + esfuerzo Bajo cuando Codex sea necesario;
- checkpoints compactos en este archivo.
