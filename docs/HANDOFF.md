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

PUBLIC_ROOT confirmado por cPanel para ese host:
`/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`

APP_ROOT actual:
`/home/tdatcons/apps/flujo-caja-staging`

APP_ROOT productivo separado todavía no existe. Candidato si se decide separar código más adelante:
`/home/tdatcons/apps/flujo-caja-production`

Hosting/deploy:
- cPanel;
- sin SSH/Composer/Artisan remoto como flujo normal;
- app privada fuera del document root público;
- preservar siempre `.env` y `storage/`;
- `staging-bootstrap.sql` es bootstrap completo, NO migración incremental. Nunca importarlo sobre la BD actual.

Producción todavía NO formalizada; se hará promoción in-place del entorno validado.

## 2. Estado funcional actual — UAT FINAL PASS

Release funcional probado y desplegado:
- commit: `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- tag: `cpanel-staging-20260903-124424`;
- build: PASS, exit code 0;
- `manifest.git_commit`: `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- ZIPs, secret scan, SQL validation y checksums: PASS.

Main puede estar por delante del tag SOLO por commits documentales. No reconstruir release por eso.

Remuneración A: PASS, `REM-000013`, 10 h, costo empresa `$400.000`, cálculo/trazabilidad PASS.
Remuneración B: PASS, `REM-000014`, 6 h, `QA-B-AJUSTE` `$10.000`, costo empresa `$300.000`, cálculo/trazabilidad PASS.
Rentabilidad QA-A: ingresos `$1.000.000`, costo laboral `$400.000`, otros directos `$100.000`, margen `$500.000` / `50 %`.
Rentabilidad QA-B: ingresos `$500.000`, costo laboral `$300.000`, otros directos `$200.000`, margen `$0` / `0 %`.

Conclusión:
- Remuneración A PASS;
- Remuneración B PASS;
- Rentabilidad PASS;
- Escenario A E2E PASS;
- Escenario B E2E PASS;
- UAT FINAL PASS;
- funcionalmente apto para promoción a producción: SI.

NO repetir ni duplicar `REM-000013` ni `REM-000014`.

## 3. Blockers resueltos — no reabrir sin evidencia nueva

- Selector Proyecto en Remuneraciones: listener `input` agregado; PASS.
- 500 payroll horario/honorarios: normalización de campos monetarios; test dirigido PASS 10/92; PASS en servidor.
- Build cPanel Windows: secret scan con `ZipArchive`; build final PASS.
- Responsable Proyecto, Presupuesto, labels y demás incidencias UAT: PASS/cerradas.

## 4. Módulos ya PASS — NO repetir salvo smoke mínimo de producción

Clientes, Proyectos, Personal, Asignaciones, Horas, Remuneraciones, Novedades remuneración, Facturas, CxC, Egresos, CxP, Cuentas/Movimientos, Obligaciones, Flujo, Escenarios, Dashboard, Presupuesto, Rentabilidad, Usuarios admin, seguridad 403 y mantenedores smoke.

## 5. Seguridad BD / deploy

Migraciones relevantes existentes:
- `database/migrations/2026_08_28_000100_create_payroll_record_time_entries_table.php`;
- `database/migrations/2026_08_31_000100_make_period_batch_id_not_nullable_on_time_entries.php`.

Reglas:
- la BD actual se PROMUEVE y se conserva;
- NO crear una BD nueva para producción;
- NO importar `production-bootstrap.sql` ni `staging-bootstrap.sql` sobre la BD actual;
- no ejecutar migraciones salvo que un cambio posterior al release validado realmente las requiera;
- nunca cambiar `APP_ENV` para saltar salvaguardas;
- nunca desactivar FKs a ciegas;
- preservar `.env` y `storage/`.

Backups obligatorios antes del cutover:
- BD actual completa: COMPLETADO por Miguel el 2026-09-03;
- APP_ROOT actual: COMPLETADO por Miguel el 2026-09-03;
- PUBLIC_ROOT: COMPLETADO por Miguel el 2026-09-03;
- `.env` actual: PENDIENTE.

## 6. Production readiness — decisiones confirmadas 2026-09-03

Plantilla productiva local:
- `.env.production.template`: existe localmente, ignored;
- no exponer contenido ni secretos.

cPanel:
- home: `/home/tdatcons`;
- app actual: `/home/tdatcons/apps/flujo-caja-staging`;
- host: `licitaciones.tdatconsulting.cl`;
- PUBLIC_ROOT: `/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`;
- HTTPS redirect activado.

Decisiones de Miguel:
- dominio productivo será `https://licitaciones.tdatconsulting.cl`;
- se mantendrá la BASE DE DATOS ACTUAL;
- no se creará BD productiva separada;
- la transición es una PROMOCIÓN IN-PLACE del entorno ya validado, preservando datos actuales.

Consecuencias:
- no bootstrap SQL;
- no migración de datos a otra BD;
- mantener credenciales BD actuales en `.env`;
- realizar backups antes de cualquier cambio;
- evitar mover APP_ROOT si no aporta beneficio claro.

## 7. Próximo paso EXACTO — completar backups, todavía SIN DEPLOY

Estado actual:
1. backup completo BD actual: COMPLETADO;
2. backup de `/home/tdatcons/apps/flujo-caja-staging`: COMPLETADO;
3. backup de `/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`: COMPLETADO;
4. siguiente: backup privado del `.env` actual;
5. recién después revisar/cambiar configuración mínima productiva (`APP_ENV=production`, `APP_DEBUG=false`, APP_URL/seguridad), preservando APP_KEY y credenciales actuales.

NO build production todavía.
NO generar SQL.
NO importar bootstrap.
NO tocar BD.
NO cambiar `.env` hasta completar backups.

## 8. Política de ahorro de créditos

- sin Codex para inspecciones/manuales de cPanel;
- modelo económico + esfuerzo Bajo por defecto cuando sea necesario;
- no repetir UAT ni builds PASS;
- agrupar tareas cuando sea seguro;
- checkpoints compactos en este archivo.
