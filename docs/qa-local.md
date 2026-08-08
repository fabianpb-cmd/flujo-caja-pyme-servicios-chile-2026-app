# QA local MVP financiero

Fecha: 2026-08-07  
Base QA: `flujo_caja_pyme_qa`  
Fuente: `Flujo_Caja_Pyme_Servicios_Chile_2026_V3.xlsx`

## Verificacion tecnica

| Area | Resultado |
| --- | --- |
| `composer install` | OK |
| `php artisan migrate:status` | OK |
| `php artisan migrate:fresh --seed` en QA | OK |
| `php artisan finance:import-excel --dry-run` | OK: 254 leidos, 144 validos, 0 errores |
| Importacion real QA | OK: 144 importados, 75 omitidos, 4 warnings de parametros sin valor |
| Schema | OK: DECIMAL para dinero, fechas tipadas, 43 FKs, 60 uniques, 129 indices |
| Rutas | OK |
| `php artisan serve` | OK en puerto alternativo local usado por QA |
| Logs post-test | OK: sin errores nuevos despues de corrida final; log historico respaldado en `storage/logs/laravel.log.qa-before` |

## Conteos importados QA

| Entidad | Aplicacion | Resultado |
| --- | ---: | --- |
| Clientes | 2 | OK |
| Proyectos | 1 | OK |
| Personas | 1 | OK |
| Asignaciones | 1 | OK |
| Horas | 1 | OK |
| Remuneraciones | 1 | OK |
| Ingresos | 1 | OK |
| Egresos | 1 | OK |
| Obligaciones | 108 | OK |
| Presupuesto | 1 | OK |
| Escenarios | 3 | OK |
| Movimientos | 0 | OK: hoja V3 vacia |
| Parametros legales | 27 | OK |
| UF | 0 | OK: hoja V3 sin valores |

## Conciliacion Excel vs aplicacion

| Caso | Excel | Aplicacion | Diferencia | Resultado |
| --- | ---: | ---: | ---: | --- |
| Venta neta ING-001 | 1.168.161,00 | 1.168.161,00 | 0 | OK |
| IVA ING-001 | 221.950,59 | 221.950,59 | 0 | OK |
| Total ING-001 | 1.390.111,59 | 1.390.111,59 | 0 | OK |
| Saldo ING-001 sin movimientos | 1.390.111,59 | 1.390.111,59 | 0 | OK |
| Pago por hora bruto PAG-001 | 328.000,00 | 328.000,00 | 0 | OK |
| Retencion no dependiente PAG-001 | 50.020,00 | 50.020,00 | 0 | OK |
| Liquido PAG-001 | 277.980,00 | 277.980,00 | 0 | OK |
| Provision vacaciones PAG-001 | 0,00 | 0,00 | 0 | OK |
| Costo empresa PAG-001 | 328.000,00 | 328.000,00 | 0 | OK |
| IVA/F29 julio | 221.950,59 | 221.950,59 | 0 | OK |
| Retenciones julio | 50.020,00 | 50.020,00 | 0 | OK |
| PPM julio | 1.460,20 | 1.460,20 | 0 | OK |
| CxC 31-07-2026 | 1.390.111,59 | 1.390.111,59 | 0 | OK |
| CxP 31-07-2026 | 0,00 | 0,00 | 0 | OK |
| Flujo real julio | 0,00 | 0,00 | 0 | OK |
| Forecast ingreso agosto | 1.390.111,59 | 1.390.111,59 | 0 | OK |
| Rentabilidad venta PRY-001 | 1.168.161,00 | 1.168.161,00 | 0 | OK |
| Rentabilidad costo PRY-001 | 328.000,00 | 328.000,00 | 0 | OK |
| Rentabilidad margen PRY-001 | 840.161,00 | 840.161,00 | 0 | OK |
| Rentabilidad margen % PRY-001 | 71,92% | 71,92% | 0 pp | OK |
| Presupuesto julio sin input | 0,00 | 0,00 | 0 | OK |

## QA funcional

| Caso | Excel | Aplicacion | Diferencia | Resultado |
| --- | --- | --- | --- | --- |
| Documento no cuenta como caja real | Documento separado | Caja real solo por movimiento | 0 | OK |
| Cobro parcial 3.000.000 - 1.000.000 - 1.000.000 | Saldo 1.000.000 | Saldo 1.000.000 | 0 | OK |
| Sobrepago con saldo 1.000.000 | Rechazar | Rechazado en transaccion | 0 | OK |
| CxC historica | Pago futuro no cambia cierre | Pago futuro no cambia cierre | 0 | OK |
| Probabilidad NULL | 100% forecast | 100% forecast | 0 | OK |
| Pago sin fecha | Falta fecha | Falta fecha | 0 | OK |
| Tasa 2027 no altera 2026 | Vigencia historica | Vigencia historica | 0 | OK |
| Periodo cerrado | Bloquear movimiento | Bloqueado | 0 | OK |
| Login obligatorio | Requerido | Requerido | 0 | OK |
| Relacion multiempresa | No cruzar empresa | Validacion por empresa | 0 | OK |
| Auditoria creacion/edicion/cierre/reapertura | Trazable | Trazable | 0 | OK |

## Hallazgos corregidos

| Prioridad | Hallazgo | Correccion | Resultado |
| --- | --- | --- | --- |
| P0 | Retencion y provision en pagos no dependientes no conciliaban con V3 | Retencion a no dependientes; vacaciones solo dependientes | OK |
| P1 | Flujo real omitía egresos `Otro` con `source_document_type` NULL | `other_real` incluye NULL y excluye solo personal/legal | OK |
| P1 | Payroll CRUD podia quedar `Pagado` sin movimiento de caja | Estado derivado por servicio de payroll | OK |
| P1 | Relaciones CRUD podian apuntar a IDs de otra empresa | `exists` scoping por `company_id` | OK |
| P1 | Auditoria CRUD/cierre insuficiente | `AuditService` y `MonthlyClosureService` | OK |

## Pendientes

P0 abiertos: ninguno.  
P1 abiertos: ninguno.  
P2 abiertos: completar UF historica y parametros legales pendientes antes de usar datos reales dependientes de topes.

Tests: `php artisan test`  
Passed: 18  
Failed: 0  
Estado: APTO PARA UAT
