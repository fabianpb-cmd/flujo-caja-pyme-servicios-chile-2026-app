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

## Ajuste UX money/percent — desplegado y validado 2026-09-06

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

Validación productiva:
- deploy manual del partial actualizado: PASS;
- smoke visual: PASS;
- prueba mínima de persistencia/submit sin modificar valores: PASS;
- al reabrir, Neto/moneda, Probabilidad, IVA y Total conservaron valores/formato esperados.

Estado del ajuste: **CERRADO / PASS**.

## Hallazgo crítico pendiente — base de prefacturación en proyecto cerrado (2026-09-06)

Caso QA observado:
- proyecto `Alerta Matrículas`, tipo de contrato `Proyecto cerrado`;
- venta neta contractual: `UF 180` (aprox. CLP 7,36 MM según UF de referencia mostrada por la aplicación);
- persona/asignación con tarifa de costeo cercana a `UF 0,77 / HH`;
- 9,75 h aprobadas del período;
- factura generada `ING-000006` con neto aproximado `CLP 306.688`.

Causa raíz confirmada por revisión de código:
- `SalesPrefacturationService::calculate()` construye el neto sumando líneas de horas aprobadas (`subtotal_clp`) y no usa `projects.sale_net` como base contractual.
- `SalesPrefacturationService::lineForEntry()` obtiene la tarifa mediante `HourlyRateService::resolveForEntry()`.
- `HourlyRateService::resolveForTimeEntry()` prioriza `project_assignments.hourly_value` cuando es > 0; solo si no existe usa `projects.contracted_hourly_rate`.
- Ese `project_assignments.hourly_value` es presentado en UI como tarifa/valor HH de costeo, por lo que hoy puede terminar reutilizado como tarifa de facturación.
- Para UF, cada línea de hora se convierte usando la UF de la fecha de la hora, lo que explica que el neto resultante sea del orden de `9,75 h x 0,77 UF/h x UF histórica ≈ CLP 306 mil`.

Conclusión funcional: para un contrato `Proyecto cerrado`, la facturación no debería derivarse del costo HH de la persona/asignación. Debe usar la venta contractual del proyecto (`sale_net`) y/o un esquema explícito de hitos/porcentajes/saldo por facturar. En contratos por hora, la tarifa de venta debe estar separada de la tarifa de costeo.

Estado: **BUG DE LÓGICA DE NEGOCIO CONFIRMADO / NO CERRAR PROYECTO TODAVÍA**.

Próximo bloque recomendado, sin suite completa:
1. separar base de facturación por tipo de contrato;
2. `Proyecto cerrado`: facturar contra `sale_net`, controlando saldo ya facturado e hitos/parcialidades;
3. contratos por hora: usar tarifa comercial del proyecto/asignación, nunca tarifa de costeo;
4. prueba dirigida con el caso `UF 180` y una prueba de facturación por HH;
5. desplegar solo después de PASS dirigido.

No limpiar todavía los datos QA de este caso: son útiles para reproducir y validar el fix.

## Política de pruebas / continuidad

No repetir UAT, suite completa, QA de seguridad ni smoke ya cerrados. Reabrir solo ante incidente real, nueva release, cambio de esquema/código o evidencia nueva.

Ante incidente: revisar primero `storage/logs`; comparar código contra la release productiva correspondiente.
Antes de cualquier nueva release o DDL: backup fresco de BD y archivos afectados.
Para continuidad en otra cuenta: `Lee docs/HANDOFF.md y continúa desde el estado actual. No repitas tareas ya completadas.`
