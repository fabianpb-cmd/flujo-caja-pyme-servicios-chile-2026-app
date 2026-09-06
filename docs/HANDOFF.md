# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-05.

ÚNICA fuente de continuidad. No repetir tareas cerradas salvo incidente, nueva release o evidencia nueva.

## Estado final

- Producción: `https://licitaciones.tdatconsulting.cl`
- APP_ROOT: `/home/tdatcons/apps/flujo-caja-staging`
- PUBLIC_ROOT: `/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`
- Código funcional desplegado: `f18c368065bf134412e12d3ae87d17bb058772a2`
- Marcador GitHub de producción: rama `production-20260905` apuntando a `f18c368065bf134412e12d3ae87d17bb058772a2`
- `.env` productivo preservado: `APP_ENV=production`, `APP_DEBUG=false`, APP_URL correcto, sesiones seguras, APP_KEY y credenciales BD sin cambios.
- Backups frescos de BD, APP_ROOT y PUBLIC_ROOT: COMPLETADOS antes del deploy del 2026-09-05.
- Estado global productivo: **PRODUCCIÓN ESTABLE Y LIMPIA**.

## BD / migraciones

- No hay BD productiva separada: se conserva la BD actual.
- Nunca importar bootstrap SQL sobre esta BD.
- Migración `2026_09_04_000100_add_unique_person_period_to_payroll_records`:
  - pre-check duplicados: 0 filas;
  - índice `payroll_records_company_person_period_unique`: presente, `Non_unique=0`, columnas `company_id, person_id, period_date`;
  - índice legado ausente;
  - migración registrada manualmente en `migrations` con `batch=3`.
- Estado BD para esta release: **ALINEADO / PASS**.

## Cambios funcionales cerrados

- Integridad transaccional financiera: documentos/movimientos contabilizados protegidos contra edición/eliminación/bypass; período cerrado y saldo se revalidan al contabilizar.
- Movimientos de caja:
  - selector dependiente para Factura/Gasto/Remuneración/Obligación;
  - `Otro` conserva referencia libre;
  - estados soportados: `Borrador` y `Contabilizado`; `Anulado` no se ofrece sin flujo formal de reversión.
- Payroll:
  - horas/tarifa horaria derivadas de fuentes válidas;
  - vigencia laboral validada;
  - unicidad empresa/persona/período en BD;
  - flujo `Borrador -> Confirmado -> Parcial/Pagado`;
  - `Borrador` no es pagable; `Confirmar` solo para cálculo `OK`.
- Seguridad/IDOR y campos controlados por servidor: QA dirigida PASS.

## Deploy producción 2026-09-05

Miguel autorizó explícitamente y realizó deploy incremental manual en cPanel de los archivos funcionales hasta `f18c368...`.

No se modificó `.env`, `storage/`, `vendor/` ni `public/`. No se ejecutó Artisan/SQL adicional durante el deploy; la migración ya estaba alineada/registrada previamente.

Smoke productivo posterior al deploy: **PASS**.
- Movimientos de caja: selector interno OK.
- Tipo `Otro`: referencia libre OK.
- Estado: solo `Borrador` y `Contabilizado`.
- Remuneraciones: listado/edición/detalle OK; `REM-000016` Borrador + cálculo OK mostró botón `Confirmar` sin ejecutarlo.
- No se crearon datos solo para probar.

## Política de pruebas

No repetir UAT, suite completa, QA de seguridad ni smoke de la release cerrada. Reabrir solo ante:
1. incidente real;
2. nueva release;
3. cambio de esquema/código;
4. evidencia nueva.

## Limpieza final de datos de prueba — COMPLETADA

Miguel confirmó que los datos operacionales existentes eran de prueba y autorizó su eliminación preservando parámetros/configuración base.

Preservado:
- `companies` y usuario(s) necesarios para acceso;
- `migrations`;
- `company_settings`;
- parámetros legales/económicos: `legal_parameters`, `uf_values`, `utm_values`, `exchange_rates`, `income_tax_brackets`, `afps`, `afp_rates`;
- catálogos y geografía;
- escenarios baseline (`scenarios`) y catálogos/configuración de empresa.

Verificación manual post-limpieza en phpMyAdmin: **PASS**. Tablas operacionales verificadas en `0` filas: `clients`, `projects`, `people`, `project_assignments`, `time_entries`, `payroll_records`, `sales_documents`, `expense_documents`, `legal_obligations`, `cash_movements`, `cash_accounts`, `budgets`, `monthly_closures`.

Estado: **PRODUCCIÓN LIMPIA / PARÁMETROS Y CONFIGURACIÓN BASE PRESERVADOS / PASS**.

## Ajuste UX posterior — formato de montos y porcentajes editables (2026-09-05)

Hallazgos visuales:
- campos monetarios editables mostraban valor crudo/local, por ejemplo `306688,00`;
- campos porcentuales editables mostraban fracción cruda, por ejemplo `1.000000` para una probabilidad del 100%;
- los inputs monetarios editables no mostraban explícitamente la unidad/símbolo monetario.

Corrección implementada en `main`, pendiente de deploy final de esta revisión:
- `resources/views/operational/partials/field-input.blade.php` muestra montos editables con formato `es-CL`.
- Los money editables muestran prefijo de moneda/unidad en un `input-group`: `$`, `US$`, `€`, `UF` o el código correspondiente.
- La moneda se resuelve desde la definición del campo (`currency`, `currency_relation`, `currency_field`) y, si no existe configuración específica, usa CLP.
- Los campos `presentation => percent` muestran porcentaje humano (`1` persistido -> `100 %`) y vuelven a fracción antes del submit.
- Dinero se normaliza a número crudo antes del submit; no cambia persistencia, reglas, servicios ni BD.
- En `sales-documents`, `net_amount` no tiene hoy una relación de moneda propia y el modelo/servicio guardan ese monto operativo en CLP, por lo que el prefijo correcto para ese campo actualmente es `$`.
- En campos configurados con moneda dinámica, como `projects.sale_net`, el prefijo toma la moneda configurada del proyecto.

Commits principales del ajuste:
- money inicial: `ab378a1326dc6f4b6cffb771b8068ef38ffb1d9a`
- normalización money: `dd8aee22550bd8d157be784a28056a7b233c06f8`
- money + percent: `186a21c3782bb1387b386b5897efaba5de1aca55`
- prefijo/unidad monetaria en money editable: `a085f2f1d187c2b15b79f6ce528cf045f0e8ef43`

Validación: revisión estática dirigida del componente común, `UiFormatter`, configuración de `projects` y `sales-documents`, y modelo/servicio de prefacturación. No suite completa para ahorrar créditos.

Próximo paso: `git pull origin main`, subir SOLO la versión actual de `resources/views/operational/partials/field-input.blade.php` a cPanel y hacer smoke visual. En la factura mostrada: `Neto` debe verse con prefijo `$` y miles formateados; `Probabilidad` debe verse `100 %`. No guardar datos solo para probar.

## Operación mínima

- Ante incidente: revisar primero `storage/logs`, luego comparar contra `production-20260905` / `f18c368...`.
- Antes de cualquier nueva release o DDL: backup fresco de BD y archivos afectados.
- Para continuidad en otra cuenta: `Lee docs/HANDOFF.md y continúa desde el estado actual. No repitas tareas ya completadas.`
