# HANDOFF — Flujo Caja PyME Servicios Chile 2026

Última actualización: 2026-09-06.

ÚNICA fuente de continuidad. No repetir tareas cerradas salvo incidente, nueva release o evidencia nueva.

## Estado productivo

- Producción: `https://licitaciones.tdatconsulting.cl`
- APP_ROOT: `/home/tdatcons/apps/flujo-caja-staging`
- PUBLIC_ROOT: `/home/tdatcons/public_html/licitaciones.tdatconsulting.cl`
- Release funcional base desplegada: `f18c368065bf134412e12d3ae87d17bb058772a2`
- Marcador histórico: rama `production-20260905` -> `f18c368065bf134412e12d3ae87d17bb058772a2`
- `.env`, APP_KEY, credenciales, `storage/`, `vendor/` y `public/` preservados.
- Backups frescos previos al deploy principal: COMPLETADOS.
- BD productiva: ALINEADA/PASS; migración Payroll unique registrada con `batch=3` y unique index presente.

## Cambios funcionales cerrados

- Integridad transaccional financiera y períodos cerrados: PASS.
- Movimientos de caja: selector dependiente; `Otro` con referencia libre; estados solo `Borrador` y `Contabilizado`.
- Payroll: horas/tarifa derivadas, vigencia laboral, unicidad empresa/persona/período y flujo `Borrador -> Confirmado -> Parcial/Pagado`.
- Seguridad/IDOR/campos controlados por servidor: QA dirigida PASS.
- Smoke de la release principal: PASS.

## Limpieza de datos QA

Se eliminaron los datos operacionales de prueba preservando empresa, usuario(s), migraciones, parámetros legales/económicos, catálogos, geografía, escenarios y configuración base. Verificación manual previa: tablas operacionales principales en `0` filas.

## Ajuste UX money/percent — desplegado 2026-09-06

Hallazgo: inputs editables mostraban números crudos (`306688,00`, `1.000000`) y los montos editables no mostraban unidad monetaria.

Corrección en `resources/views/operational/partials/field-input.blade.php`:
- `money` editable usa formato `es-CL` y separador de miles;
- muestra prefijo/unidad: `$`, `US$`, `€`, `UF` o código correspondiente;
- resuelve moneda desde `currency`, `currency_relation` o `currency_field`, con CLP por defecto;
- `presentation => percent` muestra porcentaje humano (`1` -> `100 %`);
- antes del submit normaliza dinero a número crudo y porcentaje a fracción para mantener backend/BD sin cambios.

Commits funcionales del ajuste:
- `186a21c3782bb1387b386b5897efaba5de1aca55` — money + percent
- `a085f2f1d187c2b15b79f6ce528cf045f0e8ef43` — prefijo/unidad monetaria

Miguel confirmó deploy manual del partial actualizado y smoke visual **PASS**: el formato quedó corregido en producción.

Pendiente único antes del cierre definitivo de este ajuste: una prueba mínima de persistencia/submit con un registro QA existente, seguida de limpieza de los datos QA creados para esta revisión. No repetir UAT ni suite completa.

## Política de pruebas / continuidad

No repetir UAT, suite completa, QA de seguridad ni smoke ya cerrados. Reabrir solo ante incidente real, nueva release, cambio de esquema/código o evidencia nueva.

Ante incidente: revisar primero `storage/logs`; comparar código contra la release productiva correspondiente.
Antes de cualquier nueva release o DDL: backup fresco de BD y archivos afectados.
Para continuidad en otra cuenta: `Lee docs/HANDOFF.md y continúa desde el estado actual. No repitas tareas ya completadas.`
