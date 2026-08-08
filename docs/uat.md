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
