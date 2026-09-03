# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-03.

ÚNICA fuente de continuidad entre cuentas de ChatGPT/Codex. Leer este archivo al retomar el proyecto y continuar desde el estado actual. NO repetir tareas ya cerradas. No guardar secretos.

## 1. Repositorio y entorno

Repositorio:
`fabianpb-cmd/flujo-caja-pyme-servicios-chile-2026-app`

Aplicación: Laravel.

Staging:
`https://licitaciones.tdatconsulting.cl`

APP_ROOT staging:
`/home/tdatcons/apps/flujo-caja-staging`

Hosting/deploy:
- cPanel;
- sin SSH/Composer/Artisan remoto como flujo normal;
- app privada fuera del document root público;
- preservar siempre `.env` y `storage/`;
- `staging-bootstrap.sql` es bootstrap completo, NO migración incremental. Nunca importarlo sobre staging/productivo existente con datos que se deban conservar.

Producción todavía NO desplegada.

## 2. Estado funcional actual — UAT FINAL PASS

Release funcional probado y desplegado en staging:
- commit: `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- tag: `cpanel-staging-20260903-124424`;
- build: PASS, exit code 0;
- `manifest.git_commit`: `ec1d51160b8899b7351950fc1202f157d72e42c4`;
- ZIPs, secret scan, SQL validation y checksums: PASS;
- APP_ROOT: `/home/tdatcons/apps/flujo-caja-staging`;
- deploy realizado solo con `app-private.zip`; sin `public.zip`, sin SQL, sin migración nueva.

Main puede estar por delante del tag SOLO por commits documentales. No reconstruir release por eso.

### Remuneración A — PASS

Payroll: `REM-000013`
Proyecto: `QA-A-PROYECTO / PRY-000009`
Persona: `QA-A-PERSONA UAT QA`
Período: septiembre 2026

Validado:
- 10 h aprobadas;
- valor HH `$40.000`;
- bruto `$400.000`;
- líquido `$339.000`;
- costo empresa `$400.000`;
- cálculo y trazabilidad PASS;
- sin 500.

NO repetir ni duplicar `REM-000013`.

### Remuneración B — PASS

Payroll: `REM-000014`
Proyecto: `QA-B-PROYECTO / PRY-000010`
Persona: `QA-B-PERSONA`
Período: septiembre 2026

Validado:
- 6 h aprobadas;
- valor HH `$50.000`;
- bruto `$300.000`;
- `QA-B-AJUSTE` automático una sola vez como bono imponible `$10.000`;
- líquido `$254.250`;
- costo empresa mostrado `$300.000`;
- cálculo y trazabilidad PASS;
- sin 500.

NO repetir ni duplicar `REM-000014`.

### Rentabilidad — PASS

QA-A:
- ingresos `$1.000.000`;
- costo laboral `$400.000`;
- otros directos `$100.000`;
- costo total `$500.000`;
- margen `$500.000` / `50 %`.

QA-B:
- ingresos `$500.000`;
- costo laboral `$300.000`;
- otros directos `$200.000`;
- costo total `$500.000`;
- margen `$0` / `0 %`.

Conclusión:
- Remuneración A PASS;
- Remuneración B PASS;
- Rentabilidad PASS;
- Escenario A E2E PASS;
- Escenario B E2E PASS;
- UAT FINAL PASS;
- funcionalmente apto para preparar producción: SI.

## 3. Blockers resueltos — no reabrir sin evidencia nueva

- Selector Proyecto en Remuneraciones: listener `input` agregado; staging PASS.
- 500 payroll horario/honorarios: normalización de `bonuses`, `non_taxable_allowances`, `advances`, `other_deductions`; test dirigido PASS 10/92; staging PASS.
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

## 6. Production readiness — 2026-09-03

Inspección local reportada por Codex:
- HEAD local: `2611d775125061aa5a58ba31feb4c9b5341c9e13`;
- `origin/main`: `f81c0017a2a0887e15518c2e96cdff0a3d3d8c95` al momento de la inspección;
- working tree limpio;
- diferencia local/remoto: commits documentales en `docs/HANDOFF.md`.

Plantilla productiva:
- `.env.production.template`: EXISTE localmente;
- untracked + ignored por `.gitignore`;
- el build production podría usarla hoy localmente;
- NO es reproducible desde clone remoto actual;
- no exponer su contenido ni secretos.

Datos de producción aún no confirmados:
- APP_ROOT candidato `/home/tdatcons/apps/flujo-caja-production`: TENTATIVO, inferido del default del script;
- PUBLIC_ROOT: NO DEFINIDO;
- dominio/APP_URL production: NO DEFINIDO;
- existencia/estado de BD production: NO CONFIRMADO;
- tipo de deploy: NO CONFIRMADO;
- uso de bootstrap vs migración incremental: NO DETERMINABLE hasta conocer BD.

Backups obligatorios antes de deploy:
- BD;
- APP_ROOT;
- PUBLIC_ROOT;
- `.env`.

### Inspección manual cPanel

Captura del Administrador de archivos confirma:
- home de la cuenta: `/home/tdatcons`;
- carpeta `apps/` existe;
- dentro de `apps/` se observa `flujo-caja-staging`;
- NO se observa todavía una carpeta `flujo-caja-production`;
- staging está en `/home/tdatcons/apps/flujo-caja-staging` y contiene `.env`;
- no se abrió ni expuso el contenido del `.env`.

Conclusión provisional:
- APP_ROOT productivo aún NO existe/NO está confirmado en el filesystem mostrado;
- sigue pendiente definir/crear el APP_ROOT productivo después de confirmar dominio y document root;
- esto sigue siendo preparación, no deploy.

## 7. Próximo paso EXACTO — confirmación manual en cPanel, SIN CODEX

Pendiente confirmar:
1. PUBLIC_ROOT/document root productivo real y dominio HTTPS exacto;
2. estado de la BD productiva: inexistente/vacía o existente con datos que preservar;
3. si existe algún `.env` productivo en otra ubicación (no abrirlo ni mostrar contenido).

NO build production todavía.
NO generar SQL todavía.
NO deploy production todavía.

## 8. Política de ahorro de créditos

- modelo económico + esfuerzo Bajo por defecto;
- subir esfuerzo/modelo solo ante blocker real;
- no recorridos amplios del repo si bastan archivos concretos;
- no repetir UAT ni builds PASS;
- respuestas Codex cortas y estructuradas;
- agrupar tareas cuando sea seguro;
- checkpoints compactos en este archivo.
