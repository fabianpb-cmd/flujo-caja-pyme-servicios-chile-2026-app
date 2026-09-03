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

APP_ROOT actual de staging:
`/home/tdatcons/apps/flujo-caja-staging`

APP_ROOT productivo todavía no existe/no está confirmado. Candidato:
`/home/tdatcons/apps/flujo-caja-production`

Hosting/deploy:
- cPanel;
- sin SSH/Composer/Artisan remoto como flujo normal;
- app privada fuera del document root público;
- preservar siempre `.env` y `storage/`;
- `staging-bootstrap.sql` es bootstrap completo, NO migración incremental. Nunca importarlo sobre una BD existente con datos que se deban conservar.

Producción todavía NO desplegada.

## 2. Estado funcional actual — UAT FINAL PASS

Release funcional probado y desplegado en staging:
- commit: `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- tag: `cpanel-staging-20260903-124424`;
- build: PASS, exit code 0;
- `manifest.git_commit`: `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- ZIPs, secret scan, SQL validation y checksums: PASS;
- deploy realizado solo con `app-private.zip`; sin `public.zip`, sin SQL, sin migración nueva.

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
- funcionalmente apto para preparar producción: SI.

NO repetir ni duplicar `REM-000013` ni `REM-000014`.

## 3. Blockers resueltos — no reabrir sin evidencia nueva

- Selector Proyecto en Remuneraciones: listener `input` agregado; staging PASS.
- 500 payroll horario/honorarios: normalización de campos monetarios; test dirigido PASS 10/92; staging PASS.
- Build cPanel Windows: secret scan con `ZipArchive`; build final PASS.
- Responsable Proyecto, Presupuesto, labels y demás incidencias UAT: PASS/cerradas.

## 4. Módulos ya PASS — NO repetir salvo smoke mínimo de producción

Clientes, Proyectos, Personal, Asignaciones, Horas, Remuneraciones, Novedades remuneración, Facturas, CxC, Egresos, CxP, Cuentas/Movimientos, Obligaciones, Flujo, Escenarios, Dashboard, Presupuesto, Rentabilidad, Usuarios admin, seguridad 403 y mantenedores smoke.

## 5. Seguridad BD / deploy

Migraciones relevantes existentes:
- `database/migrations/2026_08_28_000100_create_payroll_record_time_entries_table.php`;
- `database/migrations/2026_08_31_000100_make_period_batch_id_not_nullable_on_time_entries.php`.

Reglas:
- producción existente: backup + migración incremental revisada;
- nunca bootstrap sobre BD existente;
- nunca cambiar `APP_ENV` para saltar salvaguardas;
- nunca desactivar FKs a ciegas;
- preservar `.env` y `storage/`.

Backups obligatorios antes de deploy:
- BD;
- APP_ROOT;
- PUBLIC_ROOT;
- `.env`.

## 6. Production readiness — 2026-09-03

Plantilla productiva local:
- `.env.production.template`: EXISTE localmente;
- untracked + ignored por `.gitignore`;
- build production podría usarla localmente;
- no es reproducible desde clone remoto actual;
- no exponer su contenido ni secretos.

Inspección cPanel filesystem:
- home: `/home/tdatcons`;
- existe `apps/flujo-caja-staging`;
- no se observa `apps/flujo-caja-production`;
- staging contiene `.env`; no se abrió ni expuso.

Inspección cPanel dominios:
- `licitaciones.tdatconsulting.cl` -> document root `/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`; HTTPS redirect activado;
- `tdatconsulting.cl` -> document root `/home/tdatcons/public_html`; NO será usado para esta aplicación.

Decisión confirmada:
- dominio/APP_URL objetivo de producción: `https://licitaciones.tdatconsulting.cl`;
- PUBLIC_ROOT production: `/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`;
- el mismo host actualmente usado para staging será promovido a producción;
- por tanto hay que tratar el cambio como una promoción/cutover del entorno actual, no como creación de un dominio nuevo.

Estado de BD production: AÚN NO CONFIRMADO.
Tipo de transición de BD: AÚN NO DETERMINABLE.

## 7. Próximo paso EXACTO — manual, SIN CODEX

Entrar a cPanel > `Manage My Databases` y confirmar únicamente:
1. nombres de las bases existentes;
2. si alguna corresponde al staging actual de `licitaciones.tdatconsulting.cl`;
3. si existe una BD separada que se quiera usar como producción o si se pretende promover la BD actual;
4. no mostrar passwords, usuarios con secretos ni contenido de `.env`.

NO build production todavía.
NO generar SQL todavía.
NO cambiar APP_ENV todavía.
NO mover Document Root todavía.
NO deploy production todavía.

## 8. Política de ahorro de créditos

- sin Codex para inspecciones manuales de cPanel;
- modelo económico + esfuerzo Bajo por defecto cuando Codex sea necesario;
- no repetir UAT ni builds PASS;
- agrupar tareas cuando sea seguro;
- checkpoints compactos en este archivo.
