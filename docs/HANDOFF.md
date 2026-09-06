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
- venta neta contractual: `UF 180`;
- persona/asignación con tarifa de costeo cercana a `UF 0,77 / HH`;
- 9,75 h aprobadas del período;
- factura generada `ING-000006` con neto aproximado `CLP 306.688`.

Causa raíz confirmada por revisión de código:
- `SalesPrefacturationService::calculate()` construye el neto sumando líneas de horas aprobadas (`subtotal_clp`) y no usa `projects.sale_net` como base contractual.
- `SalesPrefacturationService::lineForEntry()` obtiene la tarifa mediante `HourlyRateService::resolveForEntry()`.
- `HourlyRateService::resolveForTimeEntry()` prioriza `project_assignments.hourly_value` cuando es > 0; solo si no existe usa `projects.contracted_hourly_rate`.
- Ese `project_assignments.hourly_value` es tarifa de costeo y hoy puede terminar reutilizado como tarifa de facturación.

Estado: **BUG DE LÓGICA DE NEGOCIO CONFIRMADO / NO CERRAR PROYECTO TODAVÍA**.

## Diseño acordado — hitos de facturación para Proyecto cerrado

Objetivo: separar definitivamente ingreso contractual de costo de personal y soportar uno o más hitos de facturación con porcentaje del total del proyecto.

Reglas funcionales acordadas:
- `projects.sale_net` + moneda de venta son la fuente contractual para `Proyecto cerrado`.
- Cada proyecto cerrado puede tener 1..N hitos con: nombre/descripción, fecha prevista, porcentaje y orden.
- El monto del hito NO se ingresa manualmente: se calcula `sale_net * porcentaje / 100` en la moneda contractual.
- La suma de hitos puede quedar temporalmente bajo 100% y se mostrará `% pendiente por programar`; nunca puede superar 100%.
- Un hito facturado no se puede editar ni borrar.
- Si existe facturación activa de hitos, se bloquea el cambio retroactivo de `sale_net` y moneda del proyecto.
- La factura de un hito usa el monto contractual del hito y lo convierte a CLP a la fecha de emisión con los servicios de conversión/parámetros existentes. La factura conserva snapshot de porcentaje, monto contractual, moneda, tasa/UF y fecha de conversión.
- La facturación por hito NO consume ni vincula horas como base de ingreso; las horas permanecen como trazabilidad/costo.
- Si una factura de hito es anulada, debe poder reemitirse el hito; la aplicación debe impedir más de una factura activa no anulada por hito.

Warning de cobertura/caja:
- No bloquear la facturación si el hito no cubre los costos devengados.
- El warning debe comparar de forma **acumulada** la facturación contractual acumulada hasta el hito vs. el costo referencial acumulado de HH aprobadas del proyecto hasta la fecha del hito/emisión. Esto evita falsos warnings en hitos posteriores.
- Costo referencial de HH aprobadas: usar la ruta de costeo (asignación/persona), nunca la tarifa comercial del proyecto. Debe poder calcularse aun si Payroll aún no está confirmado/calculable.
- Mensaje esperado si cobertura negativa: indicar facturación acumulada, costo HH aprobado acumulado y brecha, aclarando que puede implicar financiar/adelantar pago al consultor hasta hitos futuros y que no bloquea el proceso.
- Este warning es de timing/cobertura, no altera el margen contractual final.

Separación con remuneraciones:
- Los hitos de facturación al cliente NO deben crear/modificar automáticamente el `Monto pactado remuneración por proyecto/hito` del consultor.
- Facturación del cliente y pago del consultor son conceptos distintos. El warning solo informa cobertura; no acopla ambos flujos.

Contratos por hora:
- Mantener flujo por HH separado.
- La tarifa comercial por HH debe usar `projects.contracted_hourly_rate` + moneda de venta; nunca `project_assignments.hourly_value`/tarifa de costeo.
- El modelo `Project` ya posee `contracted_hourly_rate`; falta exponer/usar correctamente este valor en el flujo comercial.

Diseño técnico mínimo recomendado:
- nueva tabla `project_billing_milestones` (`company_id`, `project_id`, `sequence`, `name`, `planned_invoice_date`, `percentage`, `notes`, timestamps);
- nuevo FK nullable `project_billing_milestone_id` en `sales_documents` para trazabilidad histórica y reemisión tras anulación;
- monto del hito derivado, no persistido, para evitar divergencia mientras no esté facturado;
- snapshot contractual en `sales_documents.billing_snapshot` al emitir;
- servicio dedicado pequeño para plan/hitos + rama `PROJECT_MILESTONE` en `SalesPrefacturationService`;
- UI aislada en detalle de Proyecto (`Plan de facturación`) para no complejizar el CRUD genérico.

## Implementación de hitos de facturación — 2026-09-06

Implementado localmente, pendiente de revisión y deploy (no se tocó producción):
- migración `2026_09_06_000500_create_project_billing_milestones` y FK opcional desde `sales_documents`;
- `ProjectBillingMilestoneService` concentra porcentajes, inmutabilidad, conversión/snapshot contractual, reemisión posterior a anulación y la alerta acumulada de cobertura;
- `Plan de facturación` aislado en el detalle del Proyecto: alta, edición, eliminación, porcentaje pendiente y emisión de borrador;
- la emisión por hito no crea enlaces a HH; el snapshot conserva monto/moneda contractual, porcentaje, tasa, fecha y cobertura;
- el cambio de venta neta o moneda queda bloqueado cuando hay hitos con factura activa;
- la prefacturación por HH ahora resuelve exclusivamente la tarifa comercial del proyecto (`contracted_hourly_rate` + moneda de venta), no la tarifa de costeo de asignación;
- se conserva la ruta de costeo asignación/persona solo para el warning, aun sin Payroll confirmado.

Validación dirigida ejecutada: sintaxis PHP, rutas de hitos, `SalesPrefacturationTest` (8 PASS) y pruebas HH de `FinancialCoreTest` (4 PASS). No se ejecutó la suite completa. La caché de resultados de PHPUnit no pudo persistirse por permisos del entorno, sin afectar los resultados.

Pruebas dirigidas suficientes, sin suite completa:
1. UF 180 con hitos 30/40/30 => UF 54/72/54 y 100% total;
2. impedir suma >100%;
3. factura de hito 30% usa UF 54 convertido a CLP a fecha de emisión, no `9,75h x 0,77UF`;
4. hito no consume horas;
5. warning acumulado aparece si ingreso acumulado < costo HH aprobado acumulado y NO bloquea;
6. no warning cuando cobertura acumulada >= costo;
7. hito facturado y venta/moneda contractual quedan protegidos;
8. flujo por HH usa `contracted_hourly_rate`, no tarifa de costeo.

Mantener los datos QA actuales para reproducir este caso hasta validar el fix. Implementar todo este bloque en una sola iteración antes de deploy.

## Política de pruebas / continuidad

No repetir UAT, suite completa, QA de seguridad ni smoke ya cerrados. Reabrir solo ante incidente real, nueva release, cambio de esquema/código o evidencia nueva.

Ante incidente: revisar primero `storage/logs`; comparar código contra la release productiva correspondiente.
Antes de cualquier nueva release o DDL: backup fresco de BD y archivos afectados.
Para continuidad en otra cuenta: `Lee docs/HANDOFF.md y continúa desde el estado actual. No repitas tareas ya completadas.`
