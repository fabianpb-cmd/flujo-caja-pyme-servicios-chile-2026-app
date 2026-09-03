# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-03.

ÚNICA fuente de continuidad entre cuentas de ChatGPT/Codex. Leer este archivo al retomar el proyecto y continuar desde el estado actual. NO repetir tareas ya cerradas. No guardar secretos.

## 1. Repositorio y entorno

Repositorio:
`fabianpb-cmd/flujo-caja-pyme-servicios-chile-2026-app`

Aplicación: Laravel.

Dominio objetivo de PRODUCCIÓN confirmado por Miguel:
`https://licitaciones.tdatconsulting.cl`

Ese mismo host fue usado como staging durante la UAT final y será promovido a producción; no se usará `tdatconsulting.cl` para esta aplicación.

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

## 3. Blockers resueltos — no reabrir sin evidencia nueva

- selector Proyecto Remuneraciones: PASS;
- 500 payroll horario/honorarios: PASS, test dirigido 10/92;
- build cPanel Windows/secret scan: PASS;
- demás incidencias UAT: cerradas.

## 4. Seguridad BD / deploy

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

## 5. Production readiness — hechos confirmados

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

Plantilla local `.env.production.template`: existe localmente, ignored; no exponer contenido ni secretos.

## 6. Inspección bootstrap/cache — 2026-09-03

Captura cPanel de `/home/tdatcons/apps/flujo-caja-staging/bootstrap/cache` confirma solamente:
- `.gitignore`;
- `packages.php`;
- `services.php`.

NO existe `config.php`.
Conclusión: configuración Laravel NO está cacheada mediante `config:cache`; cambios del `.env` deberían aplicarse en la siguiente petición sin necesitar Artisan para limpiar config cache.

Revisión repo:
- `config/app.php` usa `APP_ENV`, `APP_DEBUG`, `APP_URL` y `APP_KEY` de `.env`;
- `config/session.php` hace cookie segura por defecto para `staging`/`production`, pero `SESSION_SECURE_COOKIE` puede sobrescribirla;
- `.env.staging.example` ya recomienda `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, `LOG_LEVEL=warning`, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`, `QUEUE_CONNECTION=sync`.

Importante: NO asumir que el `.env` actual necesita cambiar `APP_ENV`; primero verificar valores NO secretos existentes porque el template de staging ya usaba `APP_ENV=production`.

## 7. Próximo paso EXACTO — inspección manual de valores NO secretos del .env

Abrir `.env` en cPanel y revisar SOLO estas variables, sin compartir APP_KEY ni credenciales DB:
- `APP_ENV`
- `APP_DEBUG`
- `APP_URL`
- `LOG_LEVEL`
- `SESSION_SECURE_COOKIE`
- `SESSION_HTTP_ONLY`
- `SESSION_SAME_SITE`
- `QUEUE_CONNECTION`

Miguel debe reportar únicamente esos valores no secretos. No modificar todavía.

NO build production.
NO generar SQL.
NO importar bootstrap.
NO tocar BD.
NO mover APP_ROOT.

## 8. Política de ahorro de créditos

- sin Codex para inspecciones manuales de cPanel;
- no repetir UAT/builds PASS;
- modelo económico + esfuerzo Bajo cuando Codex sea necesario;
- checkpoints compactos en este archivo.
