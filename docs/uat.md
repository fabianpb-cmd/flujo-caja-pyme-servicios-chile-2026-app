# UAT Local

Ambiente: local, demo funcional via `php artisan migrate:fresh --seed`.

Usuario UAT:

- Email: `admin@flujo.local`
- Contrasena: desde `UAT_ADMIN_PASSWORD` en `.env`
- Fallback local: `storage/app/private/uat_credentials.json`

## UAT-01 Cliente + Proyecto

| Campo | Valor |
| --- | --- |
| ID | UAT-01 |
| Objetivo | Validar alta de cliente y proyecto con relacion correcta y estado activo. |
| Pasos | 1. Ingresar con usuario UAT. 2. Crear cliente. 3. Crear proyecto ligado al cliente. 4. Revisar relacion y estado. |
| Resultado esperado | Cliente y proyecto quedan visibles, relacionados y activos. |
| Resultado obtenido | PENDIENTE |
| Estado | PENDIENTE |
| Observacion | Usar datos ficticios. |

## UAT-02 Persona + Asignacion

| Campo | Valor |
| --- | --- |
| ID | UAT-02 |
| Objetivo | Validar creacion de persona y asignacion a proyecto con modalidad y tarifa. |
| Pasos | 1. Crear persona. 2. Asignarla al proyecto. 3. Definir modalidad. 4. Definir tarifa. |
| Resultado esperado | Asignacion activa y visible en proyecto/persona. |
| Resultado obtenido | PENDIENTE |
| Estado | PENDIENTE |
| Observacion | Verificar que no cruce empresa. |

## UAT-03 Horas

| Campo | Valor |
| --- | --- |
| ID | UAT-03 |
| Objetivo | Validar registro y aprobacion de horas con monto calculado. |
| Pasos | 1. Registrar horas. 2. Aprobar horas. 3. Revisar monto calculado. |
| Resultado esperado | Horas aprobadas y monto consistente con tarifa y modalidad. |
| Resultado obtenido | PENDIENTE |
| Estado | PENDIENTE |
| Observacion | Revisar totales en ficha y flujo. |

## UAT-04 Remuneracion/Honorario

| Campo | Valor |
| --- | --- |
| ID | UAT-04 |
| Objetivo | Validar generacion de pago y componentes de costo. |
| Pasos | 1. Generar pago. 2. Revisar bruto. 3. Revisar retencion/descuentos. 4. Revisar liquido. 5. Revisar costo empresa si aplica. |
| Resultado esperado | Montos y costo empresa coherentes con modalidad. |
| Resultado obtenido | PENDIENTE |
| Estado | PENDIENTE |
| Observacion | En dependiente mensual debe incluir proporcion si corresponde. |

## UAT-05 Facturacion

| Campo | Valor |
| --- | --- |
| ID | UAT-05 |
| Objetivo | Validar factura con neto, IVA, total y saldo. |
| Pasos | 1. Crear factura. 2. Revisar neto. 3. Revisar IVA. 4. Revisar total. 5. Revisar saldo. |
| Resultado esperado | Total y saldo calculados correctamente. |
| Resultado obtenido | PENDIENTE |
| Estado | PENDIENTE |
| Observacion | No confundir documento con caja real. |

## UAT-06 Cobros parciales

| Campo | Valor |
| --- | --- |
| ID | UAT-06 |
| Objetivo | Validar saldo y estado ante cobros parciales. |
| Pasos | 1. Crear factura por $3.000.000. 2. Registrar cobro de $1.000.000. 3. Revisar saldo. 4. Registrar segundo cobro de $1.000.000. 5. Revisar saldo y estado. |
| Resultado esperado | Saldo $2.000.000, luego $1.000.000, estado Parcial. |
| Resultado obtenido | PENDIENTE |
| Estado | PENDIENTE |
| Observacion | El tercer cobro deja saldo en cero. |

## UAT-07 Gastos y pagos

| Campo | Valor |
| --- | --- |
| ID | UAT-07 |
| Objetivo | Validar gasto, pago y efecto en CxP/caja. |
| Pasos | 1. Crear gasto. 2. Registrar pago. 3. Revisar CxP. 4. Revisar caja real. |
| Resultado esperado | CxP disminuye y caja real usa movimiento, no documento. |
| Resultado obtenido | PENDIENTE |
| Estado | PENDIENTE |
| Observacion | Confirmar pago parcial si aplica. |

## UAT-08 Obligaciones y cierre

| Campo | Valor |
| --- | --- |
| ID | UAT-08 |
| Objetivo | Validar obligaciones, flujo, rentabilidad, dashboard y cierre. |
| Pasos | 1. Revisar IVA/retenciones/PPM/cotizaciones. 2. Revisar flujo mensual. 3. Revisar rentabilidad. 4. Revisar dashboard. 5. Cerrar periodo. 6. Intentar editar el periodo cerrado. |
| Resultado esperado | Totales coherentes y periodo cerrado bloquea edicion normal. |
| Resultado obtenido | PENDIENTE |
| Estado | PENDIENTE |
| Observacion | Verificar alertas y no doble contabilizacion. |

## Checklist final

- Dashboard cuadra con movimientos.
- CxC correcta.
- CxP correcta.
- Caja real usa movimientos, no documentos.
- No hay doble contabilizacion.
- Alertas coherentes.
- Flujo proyectado coherente.
- Rentabilidad coherente.
- Cierre bloquea edicion.

## UAT financiero end-to-end - Agosto 2026

Ejecucion reproducible local: `php scripts/run-financial-uat.php`

### Escenario

- Empresa: `MI Empresa` preservada desde base local/UAT.
- Limpieza previa: `php artisan uat:clear-data --force`.
- Cuenta de caja: `Banco UAT CLP` con saldo inicial `1.000.000`.
- Cliente: `Cliente UAT SpA`.
- Proyecto: `Proyecto UAT Agosto 2026`.
- Venta neta: `2.000.000`.
- IVA vigente resuelto por fecha `2026-08-10`: `19,00%`.
- Factura bruta: `2.380.000`.
- Personal: `Persona A UAT` (`20 h x 20.000`) y `Persona B UAT` (`10 h x 15.000`).
- Costo laboral derivado por producto: `550.000`.
- Gastos pagados: `300.000` y `200.000`.
- Obligacion manual UAT: `150.000`, primero pendiente y luego pagada.

### Reconciliacion

- Caja fase A esperada: `2.880.000`.
- Caja fase A obtenida: `2.880.000`.
- Caja fase B esperada: `2.730.000`.
- Caja fase B obtenida: `2.730.000`.
- Formula rentabilidad evidenciada: `venta neta facturada sin IVA - costo_personal - other_costs`.
- Resultado rentabilidad proyecto: `2.000.000 - 550.000 - 500.000 = 950.000` (`47,50%`).
- Presupuesto UAT: ingreso `2.400.000`, egresos `1.250.000`, real del periodo `650.000`.

### Casos

| Caso | Dato inicial | Accion | Resultado esperado | Resultado obtenido | Estado | Evidencia breve |
| --- | --- | --- | --- | --- | --- | --- |
| UAT-01 Cliente | Empresa UAT preservada | Crear Cliente UAT SpA | Cliente activo y ligado a empresa | Cliente UAT SpA / company_id 1 | PASS | `clients.code=CLI-UAT-202608` |
| UAT-02 Proyecto | Cliente UAT creado | Crear Proyecto UAT Agosto 2026 | Proyecto ligado al cliente con venta neta CLP 2.000.000 | Proyecto UAT Agosto 2026 / neto 2.000.000 | PASS | `projects.code=PRY-UAT-202608` |
| UAT-03 Personal | Sin personal operacional | Crear Persona A UAT y Persona B UAT | Dos personas activas con tarifa HH CLP | A=20.000, B=15.000 | PASS | `PER-UAT-A`, `PER-UAT-B` |
| UAT-04 Asignaciones | Proyecto y personal creados | Asignar ambas personas al proyecto | Asignaciones activas y trazables | ASI-UAT-A / ASI-UAT-B | PASS | `project_assignments x2` |
| UAT-05 Horas | Asignaciones activas | Registrar 20h para A y 10h para B | 30 horas aprobadas y montos consistentes | 30h / 550.000 | PASS | `HOR-UAT-A`, `HOR-UAT-B` |
| UAT-06 Costos HH | Horas y payroll por honorarios | Calcular costo laboral del proyecto | Costo directo total 550.000 | 550.000 | PASS | `HourlyCostService` |
| UAT-07 Remuneraciones | Personas por hora con parametros vigentes | Generar payroll agosto 2026 sin pago | Payroll calculado y sin impacto en caja real | employer_cost A=400.000, B=150.000, caja personal=0 | PASS | estado `Falta fecha/Falta fecha` |
| UAT-08 Factura/documento de ingreso | Proyecto comercial creado | Crear factura neta 2.000.000 con IVA vigente | Documento pendiente y caja sin cambio antes del cobro | bruto 2.380.000, caja previa 0 | PASS | `sales_documents.code=ING-UAT-202608` |
| UAT-09 Cobro efectivo | Factura pendiente | Registrar cobro total del documento | Caja aumenta exactamente una vez por monto bruto | 1 movimiento / ingreso real 2.380.000 | PASS | `MOV-UAT-COBRO` |
| UAT-10 Gastos | Sin egresos pagados | Crear dos gastos y pagarlos | Caja disminuye 500.000 exactamente una vez por cada documento | cuentas por pagar previas 500.000 / egreso real 500.000 | PASS | `EGR-UAT-A`, `EGR-UAT-B` |
| UAT-11 Obligacion pendiente | Obligacion manual creada | Dejar obligacion sin pago en fase A | Permanece pendiente y no afecta caja | pendiente 150.000 / legal_real fase A 0 | PASS | `OBL-UAT-MANUAL` |
| UAT-12 Pago obligacion | Obligacion pendiente 150.000 | Registrar pago real | Caja disminuye 150.000 una sola vez y queda pagada | legal_real fase B 150.000 / 1 movimiento | PASS | `MOV-UAT-OBL` |
| UAT-13 Presupuesto | Proyecto UAT agosto 2026 | Crear presupuesto del periodo | Presupuesto queda separado de caja real | total_budget 1.250.000 / total_real 650.000 | PASS | `budgets.id=3` |
| UAT-14 Rentabilidad | Ventas, horas y gastos creados | Calcular rentabilidad por proyecto | Venta neta sin IVA - costo laboral - gastos directos | 2.000.000 - 550.000 - 500.000 = 950.000 | PASS | `ProfitabilityService::byProject` |
| UAT-15 Dashboard | Escenario fase B completo | Leer KPIs del dashboard | Dashboard consistente con flujo, documentos y payroll | cash 2.730.000 / income 2.380.000 / expense 650.000 | PASS | `DashboardService::data` |
| UAT-16 Reconciliacion caja | Fase A y Fase B del escenario | Comparar caja esperada vs obtenida | Caja debe cuadrar exactamente en ambas fases | fase A 2.880.000 / fase B 2.730.000 | PASS | `CashFlowService::monthly` |
| UAT-17 No doble contabilizacion | Documentos, obligaciones y payroll creados | Verificar que solo cash_movements mueven caja | Sin duplicidad por documento + movimiento | income/other/personnel/legal previos = 0 | PASS | `cash movements only` |

### Resultado

- Casos ejecutados: `17`
- PASS: `17`
- FAIL: `0`
- Doble contabilizacion detectada: `NO`
- Dashboard reconciliado: `SI`
- Defectos: `P0=0`, `P1=0`, `P2=0`, `P3=0`
- Evidencia estructurada: `storage/app/private/financial_uat_aug2026.json`
