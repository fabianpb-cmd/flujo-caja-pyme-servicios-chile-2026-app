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

Hallazgos visuales en producción:
- campos monetarios editables del CRUD genérico se mostraban sin separador de miles, por ejemplo `306688,00` en `Neto`, mientras los calculados mostraban `$ 58.271` / `$ 364.959`;
- campos porcentuales editables se mostraban como fracción cruda, por ejemplo `1.000000` en `Probabilidad`, pese a que funcionalmente `1` equivale a `100 %`.

Corrección implementada en `main`, aún pendiente de deploy:
- `resources/views/operational/partials/field-input.blade.php` renderiza `type => money` editables como texto localizado `es-CL`, con separador de miles visible (ej. `306.688`).
- Los campos editables con `presentation => percent` muestran el porcentaje humano (ej. valor persistido `1` se muestra `100 %`).
- Al enviar el formulario, dinero se normaliza a número crudo y porcentaje vuelve a fracción (`100` visible -> `1` enviado), preservando reglas actuales, servicios, persistencia y BD.
- No se modificaron cálculos financieros, servicios ni esquema BD.

Commits principales del ajuste:
- money inicial: `ab378a1326dc6f4b6cffb771b8068ef38ffb1d9a`
- normalización money: `dd8aee22550bd8d157be784a28056a7b233c06f8`
- money + percent definitivo: `186a21c3782bb1387b386b5897efaba5de1aca55`

Validación: revisión estática dirigida del componente común y de la definición de `sales-documents` (`payment_probability` rango 0..1 y `net_amount` money). No suite completa para ahorrar créditos.

Próximo paso: desplegar SOLO la versión actual de `resources/views/operational/partials/field-input.blade.php` y hacer smoke visual en Factura/Ingreso: `Neto` debe verse `306.688`; `Probabilidad` debe verse `100 %`. No guardar datos solo para probar.

## Operación mínima

- Ante incidente: revisar primero `storage/logs`, luego comparar contra `production-20260905` / `f18c368...`.
- Antes de cualquier nueva release o DDL: backup fresco de BD y archivos afectados.
- Para continuidad en otra cuenta: `Lee docs/HANDOFF.md y continúa desde el estado actual. No repitas tareas ya completadas.`
