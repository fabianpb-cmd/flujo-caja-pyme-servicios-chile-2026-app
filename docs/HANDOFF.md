# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-03.

ÚNICA fuente de continuidad entre cuentas de ChatGPT/Codex. Leer este archivo al retomar y NO repetir tareas cerradas. No guardar secretos.

## 1. Entorno / producción

Repo: `fabianpb-cmd/flujo-caja-pyme-servicios-chile-2026-app` — Laravel.
Producción: `https://licitaciones.tdatconsulting.cl`
PUBLIC_ROOT: `/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`
APP_ROOT actual: `/home/tdatcons/apps/flujo-caja-staging`

Promoción in-place: mismo dominio, misma BD, mismos archivos; no mover APP_ROOT salvo necesidad real. cPanel sin SSH/Composer/Artisan remoto como flujo normal.

`.env` ya productivo: `APP_ENV=production`, `APP_DEBUG=false`, APP_URL correcto, logging warning, sesiones/cookies seguras, queue sync. APP_KEY y credenciales BD se preservan. `bootstrap/cache` no tiene `config.php`.

Backups pre-cutover COMPLETADOS: BD, APP_ROOT, PUBLIC_ROOT y `.env`.

## 2. Release funcional validado

UAT FINAL PASS sobre commit `ec1d51160b8899b7351950fc1202f157d72e42c4`, tag `cpanel-staging-20260903-124424`.
Build/ZIP/secret scan/SQL/checksums PASS. Remuneración A/B, Rentabilidad y escenarios E2E PASS. No repetir UAT.

## 3. Política BD

Se conserva la BD actual; no crear BD productiva separada. Nunca importar bootstrap sobre esta BD. No desactivar FKs a ciegas. Backup BD disponible para rollback.

## 4. Baseline que debe quedar

Preservar:
- `companies.id=1` (actualmente código `STAGING`, nombre `Empresa Staging`);
- `users.id=1` Administrador inicial real;
- catálogos/sistema/parámetros: `activities`, `afp_rates`, `afps`, `approval_statuses`, `bank_account_types`, `banks`, `cash_movement_types`, `client_types`, `communes`, `company_settings`, `contract_types`, `currencies`, `document_types`, `employment_modes`, `exchange_rates`, `expense_categories`, `expense_subcategories`, `expense_types`, `health_systems`, `income_tax_brackets`, `legal_organizations`, `legal_parameters`, `obligation_types`, `occupational_insurance_entities`, `payment_methods`, `payment_terms`, `project_types`, `record_statuses`, `regions`, `scenarios`, `tax_regimes`, `uf_values`, `utm_values`, `migrations`.

Repo confirma que responsables/cargos demo no son baseline y que `DemoDataSeeder` solo corresponde a local/testing.

## 5. Limpieza pre-go-live — EJECUTADA Y VALIDADA PASS 2026-09-03

Miguel ejecutó en phpMyAdmin el SQL final aprobado, dentro de transacción, child-to-parent, sin desactivar FKs.

Se limpiaron datos QA/UAT/demo y runtime de prueba. Validación post-delete con `COUNT(*)` dio PASS:
- `companies` = 1;
- `users` = 1;
- `clients`, `projects`, `people`, `project_assignments`, `time_entries`, `payroll_records`, `payroll_record_time_entries`, `sales_documents`, `expense_documents`, `cash_accounts`, `cash_movements`, `cost_centers`, `positions`, `project_managers`, `audit_logs`, `sessions` = 0;
- baseline principal preservado: `afps` = 7, `regions` = 16, `communes` = 346, `legal_parameters` = 31, `company_settings` = 20, `scenarios` = 3.

Conclusión: limpieza BD pre-go-live PASS. Quedó empresa/admin real + baseline paramétrico, sin datos QA/UAT visibles en las tablas verificadas.

## 6. Próximo paso EXACTO — smoke final de producción

Hacer SOLO smoke mínimo, sin repetir UAT:
1. `https://licitaciones.tdatconsulting.cl/up` debe responder OK/200;
2. `/login` debe cargar normal;
3. iniciar sesión con el administrador real `users.id=1`;
4. completar 2FA si corresponde;
5. Dashboard debe cargar sin 500 y reflejar baseline vacío operacional.

Si los cinco puntos PASS: registrar checkpoint final y declarar go-live productivo.

## 7. Política de ahorro

Sin Codex para cPanel/phpMyAdmin. No repetir UAT/builds PASS. Modelo económico + esfuerzo Bajo solo cuando Codex sea necesario. Checkpoints compactos en este archivo.
