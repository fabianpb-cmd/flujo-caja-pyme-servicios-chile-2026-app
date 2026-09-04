# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-03.

ÚNICA fuente de continuidad entre cuentas de ChatGPT/Codex. Leer este archivo al retomar y NO repetir tareas cerradas. No guardar secretos.

## 1. Entorno / producción

Repo: `fabianpb-cmd/flujo-caja-pyme-servicios-chile-2026-app` — Laravel.
Producción: `https://licitaciones.tdatconsulting.cl`
PUBLIC_ROOT: `/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`
APP_ROOT actual: `/home/tdatcons/apps/flujo-caja-staging`

Promoción in-place: mismo dominio, misma BD y mismos archivos validados. cPanel sin SSH/Composer/Artisan remoto como flujo normal.

`.env` productivo: `APP_ENV=production`, `APP_DEBUG=false`, APP_URL correcto, sesiones/cookies seguras, queue sync. APP_KEY y credenciales BD se preservan. `bootstrap/cache` no tiene `config.php`.

Backups pre-cutover COMPLETADOS: BD, APP_ROOT, PUBLIC_ROOT y `.env`.

## 2. Release / go-live

Release funcional validado: commit `ec1d51160b8899b7351950fc1202f157d72e42c4`, tag `cpanel-staging-20260903-124424`.
Build/ZIP/secret scan/SQL/checksums PASS. UAT FINAL PASS.

Limpieza pre-go-live PASS: quedó `companies=1`, `users=1`, transaccionales QA en 0 y baseline paramétrico preservado.
Smoke productivo manual reportado por Miguel: `/up`, `/login`, login, 2FA y dashboard PASS.
GO-LIVE PRODUCTIVO: PASS.

## 3. Política BD

Se conserva la BD actual; no crear BD productiva separada. Nunca importar bootstrap sobre esta BD. No desactivar FKs a ciegas. Backup BD disponible para rollback.

Preservar `companies.id=1`, `users.id=1` y catálogos/parámetros (`activities`, AFP, bancos, monedas, geografía, legal_parameters, UF/UTM, exchange_rates, income_tax_brackets, scenarios, company_settings, migrations, etc.).

## 4. Incidente Movimientos de caja — 2026-09-03

Al intentar registrar un cobro para la factura interna `ING-000005`, la pantalla POST `/operacion/cash-movements` devolvió `404 Not Found`.

Causa raíz identificada en captura + código:
- el formulario tenía `Tipo documento origen = Factura/Ingreso`;
- en `Código documento` se ingresó `1`, que corresponde al número visible de factura, NO al código funcional interno;
- `CashMovementService::validateAgainstDocument()` busca `sales_documents.code = source_document_code` y usa `firstOrFail()`;
- como no existe `sales_documents.code='1'`, Laravel responde 404.

Corrección manual inmediata:
- volver al formulario de nuevo movimiento;
- usar `Código documento = ING-000005`;
- ingreso = total cobrado (`388844` si pago total);
- estado `posted` / Contabilizado;
- guardar.

Resultado esperado: `ReceivablesService` recalcula el documento; si cobros contabilizados igualan el total, estado pasa a `Pagado`; si son menores, `Parcial`.

Mejora implementada localmente:
- `source_document_code` dejó de ser texto libre en `Movimientos de caja` y ahora se renderiza como selector dependiente de `source_document_type`.
- Las opciones se construyen por empresa y solo incluyen documentos vigentes con saldo pendiente: facturas/ingresos, gastos/egresos, remuneraciones y obligaciones.
- El `value` enviado sigue siendo el código funcional persistido (`ING-*`, `EGR-*`, `REM-*`, `OBL-*`).
- La etiqueta visible incluye código, contraparte/persona/tipo, proyecto o período/vencimiento, saldo y estado.
- Al seleccionar documento se sugieren contraparte, proyecto e ingreso/egreso por el saldo pendiente; el monto queda editable para pagos parciales.
- Al cambiar `Tipo documento origen` se limpia el documento y campos derivados para no mezclar tipos.
- `CashMovementService::validateAgainstDocument()` ya no usa `firstOrFail()` como UX final: documento inexistente, de otra empresa, anulado o sin saldo genera validación amigable.

Archivos modificados:
- `config/operational.php`
- `app/Http/Controllers/OperationalCrudController.php`
- `app/Services/CashMovementService.php`
- `resources/views/operational/form.blade.php`
- `resources/views/operational/partials/field-input.blade.php`
- `tests/Feature/CashMovementSourceDocumentSelectorTest.php`
- `docs/HANDOFF.md`

Tests dirigidos PASS:
- `php artisan test tests\Feature\CashMovementSourceDocumentSelectorTest.php` — 2 tests / 40 assertions PASS.
- `php artisan test tests\Feature\FinancialCoreTest.php --filter="partial_payments_update_invoice_balance_and_status|overpayment_is_rejected_inside_cash_transaction|expense_payments_update_balance_and_reject_overpayment"` — 3 tests / 12 assertions PASS.

BD/migraciones: NO requiere migración, SQL ni cambios de estructura en `cash_movements`.

Próximo paso: deploy incremental pendiente de aprobación; no ejecutar build/deploy sin autorización.

## 5. Política de ahorro

No repetir UAT/builds PASS. Sin Codex para tareas manuales de cPanel/phpMyAdmin. Checkpoints compactos en este archivo.

## 6. Fix #1 — integridad transaccional financiera (2026-09-04)

Se aplicó una política conservadora para cerrar los bypass transversales confirmados en las QA profundas:
- `CashMovement` contabilizado es inmutable: no admite edición, retorno a borrador ni eliminación física. Una reversión contable queda como funcionalidad futura.
- Los borradores se editan/eliminan mediante `CashMovementService`; la transición a contabilizado ejecuta transacción, locks, período abierto, empresa, documento vigente, saldo/sobrepago, refresco del documento y auditoría.
- `SalesDocument`, `ExpenseDocument`, `PayrollRecord` y `LegalObligation` con movimientos contabilizados son inmutables desde el CRUD genérico.
- Cualquier movimiento, incluso borrador, bloquea la eliminación física del documento fuente con dependencia scopeada por empresa, tipo y código.
- La creación, modificación y eliminación de documentos fuente se bloquea en períodos cerrados. Un update valida tanto el período original como el destino.
- El selector de estado de caja incorpora explícitamente `draft` / Borrador.

QA resueltas por este bloque: `QA-VENTAS-01`, `QA-VENTAS-02`, `QA-VENTAS-03`, `QA-VENTAS-07`, `QA-VENTAS-08`, `QA-GASTOS-08`, `QA-GASTOS-09`, `QA-GASTOS-10`, `QA-PERSONAL-04` y los patrones documentados de mutación de Payroll posterior al pago/cierre.

Tests dirigidos PASS:
- `FinancialTransactionIntegrityTest`: 10 tests / 132 assertions.
- `CashMovementSourceDocumentSelectorTest`: 2 / 40.
- `FinancialCoreTest`: 29 / 103.
- `CashFlowServiceTest`: 7 / 22.
- `OperationalDependencyIntegrityTest`: 4 / 27.
- `LocalQaWorkflowTest`: 3 / 39.
- `ProfitabilityServiceTest`: 8 / 32.
- filtros pertinentes de `OperationalUiTest`: 4 / 50.
- filtros pertinentes de `SecurityGateTest`: 3 / 6.

`PayrollTimeEntryTraceTest` mantiene 9 tests PASS y el fallo preexistente del flujo `Payroll Batch` que genera estado `Borrador`; corresponde a `QA-PERSONAL-05`, fuera de este fix. La suite completa también conserva fallos preexistentes no relacionados de payload legado de Horas, entorno sin `ZipArchive` y fixtures UF/proyección; no se corrigieron artificialmente.

Commit funcional: este mismo commit (`fix: enforce financial transaction immutability`; SHA en `git log`).

BD/migraciones: no requiere migración, SQL ni cambio de esquema. Producción no fue tocada.

Próximo paso: deploy incremental manual pendiente de aprobación y smoke dirigido de inmutabilidad; no generar release ni desplegar sin autorización.
