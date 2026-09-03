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

## 5. Decisión de limpieza pre-go-live

Miguel decidió: ELIMINAR antes del go-live todos los datos QA/UAT/demo y dejar solo baseline paramétrico/productivo.

PRESERVAR:
- catálogos de sistema;
- parámetros legales/tributarios/previsionales;
- geografía Chile;
- tablas de impuesto a la renta;
- catálogos operacionales/configuraciones por empresa necesarios;
- empresa `id=1`;
- administrador real `user_id=1`.

No renombrar empresa durante limpieza; puede hacerse después.

## 6. Inventario y candidatos confirmados

Baseline paramétrico a preservar:
`activities`, `afp_rates`, `afps`, `approval_statuses`, `bank_account_types`, `banks`, `cash_movement_types`, `client_types`, `communes`, `company_settings`, `contract_types`, `currencies`, `document_types`, `employment_modes`, `exchange_rates`, `expense_categories`, `expense_subcategories`, `expense_types`, `health_systems`, `income_tax_brackets`, `legal_organizations`, `legal_parameters`, `obligation_types`, `occupational_insurance_entities`, `payment_methods`, `payment_terms`, `project_types`, `record_statuses`, `regions`, `scenarios`, `tax_regimes`, `uf_values`, `utm_values`, `migrations`, `companies`, y `users.id=1`.

Previsualización SQL realizada por Miguel confirmó que TODAS las filas actuales de las siguientes tablas son QA/UAT/demo y pueden eliminarse:
- `cash_accounts`: `CTA-000005 | QA-A-CUENTA`;
- `cash_movements`: `MOV-000004` a `MOV-000007`;
- `clients`: `QA-A-CLIENTE`, `QA-B-CLIENTE`;
- `cost_centers`: `QA-CENTRO-COSTO`;
- `expense_documents`: `EGR-000002`, `EGR-000003`;
- `payroll_records`: `REM-000013`, `REM-000014`;
- `people`: `QA-A-PERSONA UAT QA`, `QA-B-PERSONA UAT QA`;
- `positions`: `BI Consultor Senior`, `BI Consultor`, `BI Consultor Junior` (datos demo, no defaults del CatalogService);
- `project_assignments`: `ASI-000022`, `ASI-000023`;
- `project_managers`: `Jaime Soriano` (derivado/no default);
- `projects`: `QA-A-PROYECTO`, `QA-B-PROYECTO`;
- `sales_documents`: `ING-000003`, `ING-000004`;
- `time_entries`: `HOR-000022` a `HOR-000028`;
- `users`: `id=5`, `QA-USER`.

Además limpiar runtime/histórico QA: `audit_logs`, `cache`, `cache_locks`, `sessions`, `password_reset_tokens`, `jobs`, `job_batches`, `failed_jobs`.
Tablas transaccionales actualmente vacías deben quedar vacías: `budgets`, `legal_obligations`, `monthly_closures`, `payroll_adjustments`, `sales_document_time_entries`.

## 7. FKs relevantes revisadas

- `payroll_record_time_entries.payroll_record_id` -> payroll_records: CASCADE;
- `payroll_record_time_entries.time_entry_id` -> time_entries: RESTRICT; borrar pivot antes de time_entries;
- `sales_document_time_entries` -> sales_documents/time_entries: CASCADE;
- `payroll_adjustments.person_id` -> people: CASCADE;
- project manager, position y cost center son FKs `NULL ON DELETE` desde projects/people/assignments/time_entries, pero se borrarán después de sus datos operacionales para mantener un orden claro;
- cash movements referencian cash account/project/user con `NULL ON DELETE`; igualmente borrar movimientos antes de cuentas.

No desactivar foreign keys.

## 8. Próximo paso EXACTO

Ejecutar UNA limpieza SQL final, child-to-parent, conservando `companies.id=1`, `users.id=1` y todos los catálogos/paramétricos anteriores.
Después validar conteos y login.

Aún NO se ha ejecutado ningún DELETE.
Backup BD disponible para rollback.

Después de limpieza: smoke mínimo `/up`, `/login`, login, 2FA, dashboard.

## 9. Política de ahorro de créditos

- sin Codex para inspecciones manuales cPanel/phpMyAdmin;
- no repetir UAT/builds PASS;
- modelo económico + esfuerzo Bajo cuando Codex sea necesario;
- checkpoints compactos en este archivo.
