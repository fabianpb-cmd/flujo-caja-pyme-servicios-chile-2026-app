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

PRESERVAR:
- catálogos de sistema;
- parámetros legales/tributarios/previsionales;
- geografía Chile;
- tablas de impuesto a la renta;
- catálogos operacionales/configuraciones por empresa necesarios;
- empresa base productiva;
- administrador real de producción.

ELIMINAR:
- `QA-A-*`, `QA-B-*`, `QA-USER`, `QA-CENTRO-COSTO`;
- `REM-000013`, `REM-000014`;
- cualquier dato UAT/QA/demo;
- datos transaccionales/operacionales no paramétricos creados para pruebas.

## 6. Empresa/admin productivos confirmados

`companies`:
- una empresa real en BD;
- `id=1`, `code=STAGING`, `name=Empresa Staging`, activa: CONSERVAR.

`users`:
- `id=1`, `company_id=1`, Administrador inicial, email corporativo verificado: CONSERVAR;
- `id=5`, `company_id=1`, `QA-USER`: ELIMINAR.

No renombrar empresa durante la limpieza; puede hacerse después si se desea.

## 7. Inventario BD previo a limpieza

Miguel ejecutó inventario con `information_schema.TABLES`. `TABLE_ROWS` es aproximado para InnoDB; usarlo solo como orientación.

Clasificación basada en repo/seeders:

### Baseline paramétrico — PRESERVAR filas
`activities`, `afp_rates`, `afps`, `approval_statuses`, `bank_account_types`, `banks`, `cash_movement_types`, `client_types`, `communes`, `company_settings`, `contract_types`, `currencies`, `document_types`, `employment_modes`, `exchange_rates`, `expense_categories`, `expense_subcategories`, `expense_types`, `health_systems`, `income_tax_brackets`, `legal_organizations`, `legal_parameters`, `obligation_types`, `occupational_insurance_entities`, `payment_methods`, `payment_terms`, `project_types`, `record_statuses`, `regions`, `scenarios`, `tax_regimes`, `uf_values`, `utm_values`.

También preservar tabla/estado técnico de `migrations` y `companies`, y `users` solo para admin real `id=1`.

`CatalogService::seedDefaultsForCompany()` confirma que ProjectManager, Position y CostCenter NO se crean como defaults; se generan por backfill desde datos existentes. Por tanto las filas actuales de `project_managers`, `positions`, `cost_centers` no forman parte del baseline paramétrico automático y deben tratarse como datos derivados de QA salvo evidencia contraria.

### Datos QA/transaccionales — LIMPIAR
Filas actuales aproximadas reportadas:
- `cash_accounts` 1;
- `cash_movements` 4;
- `clients` 2;
- `cost_centers` 1 (`QA-CENTRO-COSTO` conocido);
- `expense_documents` 2;
- `payroll_record_time_entries` 6;
- `payroll_records` 2;
- `people` 2;
- `positions` 3;
- `project_assignments` 2;
- `project_managers` 1;
- `projects` 2;
- `sales_documents` 2;
- `time_entries` 7;
- `users`: eliminar `id=5` QA.

Tablas transaccionales actualmente reportadas en 0 pero que deben quedar vacías: `budgets`, `legal_obligations`, `monthly_closures`, `payroll_adjustments`, `sales_document_time_entries`.

### Runtime/histórico QA — LIMPIAR
- `audit_logs` ~37;
- `cache` ~66;
- `sessions` ~8;
- `cache_locks`, `failed_jobs`, `job_batches`, `jobs`, `password_reset_tokens`: actualmente 0; mantener vacías.

No tocar estructuras/tablas. Solo filas.

## 8. Reglas de limpieza

- NO DELETE todavía;
- NO borrar por patrones a ciegas;
- NO desactivar foreign keys globalmente;
- SQL incremental revisado, child-to-parent;
- primero SELECT/COUNT/previsualización;
- validar exactamente qué quedará;
- backup BD disponible para rollback.

FK relevante ya confirmada en repo: `payroll_record_time_entries.time_entry_id` usa `RESTRICT ON DELETE`, por lo que los links deben borrarse antes que `time_entries`; `payroll_record_id` tiene cascade.

## 9. Próximo paso EXACTO

Ejecutar en phpMyAdmin SOLO consultas de lectura para:
1. previsualizar identidad de todas las filas candidatas a eliminar;
2. listar el grafo de foreign keys entre tablas candidatas.

Con esos dos resultados preparar el único script DELETE final, ordenado por FKs y con validación post-limpieza.

Después de limpieza: smoke mínimo `/up`, `/login`, login, 2FA, dashboard.

## 10. Política de ahorro de créditos

- sin Codex para inspecciones manuales cPanel/phpMyAdmin;
- no repetir UAT/builds PASS;
- modelo económico + esfuerzo Bajo cuando Codex sea necesario;
- checkpoints compactos en este archivo.
