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

Mejora futura recomendada: reemplazar el campo libre `source_document_code` por selector/autocompletado dependiente de `source_document_type` para evitar errores humanos y 404; manejar documento inexistente con validación amigable en vez de `firstOrFail()`.

## 5. Política de ahorro

No repetir UAT/builds PASS. Sin Codex para tareas manuales de cPanel/phpMyAdmin. Checkpoints compactos en este archivo.
