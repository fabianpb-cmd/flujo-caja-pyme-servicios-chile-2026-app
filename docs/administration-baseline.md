# Baseline Administración

Fecha base: 2026-08-07  
Fuentes: `01_Config`, `02_Parametros_Legales`, `03_Listas`, `16_Escenarios` de `Flujo_Caja_Pyme_Servicios_Chile_2026_V3.xlsx`

## Resumen

- Los estados financieros derivados siguen fuera de mantenedores editables: `sales_documents.status`, `expense_documents.payment_status`, `payroll_records.status`, `legal_obligations.status`.
- Se reutilizó `record_statuses` para estados operacionales de proyecto; no se creó una tabla duplicada.
- Se separó la carga en `SystemCatalogSeeder`, `OperationalCatalogSeeder` y `DemoDataSeeder`.
- `DemoDataSeeder` solo corre en `local/testing`.

## Inventario de mantenedores base

| Mantenedor | Valores iniciales | Origen | Tipo | Tabla | Estado |
| --- | --- | --- | --- | --- | --- |
| Modalidades | Dependiente mensual; Honorarios mensual; Pago por hora; Por proyecto | Excel | Administrable | `employment_modes` | Implementado |
| Tipos contrato laboral/comercial | Indefinido; Plazo fijo; Obra o faena; Prestación de servicios; y comerciales existentes | Excel + operativo | Administrable | `contract_types` | Implementado |
| Responsables | Jaime; Emilio | Excel | Demo | `project_managers` | Implementado |
| Cargos | 6 cargos IRIS/BI del Excel | Excel | Demo | `positions` | Implementado |
| AFP | Capital; Cuprum; Habitat; Modelo; PlanVital; Provida; Uno | Excel | Sistema | `afps` | Implementado |
| Comisiones AFP | Vigencia 2026-01-01 por AFP | Excel | Sistema | `afp_rates` | Implementado |
| Sistemas de salud | Fonasa; Isapre; Otro | Excel | Administrable | `health_systems` | Implementado |
| Tipos de cliente | Empresa privada; Sector público; Persona natural | Operativo | Administrable | `client_types` | Implementado |
| Tipos de proyecto | Servicio recurrente; Implementación; Soporte; Consultoría | Operativo | Administrable | `project_types` | Implementado |
| Estados de proyecto | Planificado; En ejecución; Suspendido; Cerrado; Cancelado | Excel | Administrable | `record_statuses` (`domain=project`) | Implementado |
| Condiciones de pago | Contado; Transferencia; 15/30/45/60 días | Excel + operativo | Administrable | `payment_terms` | Implementado |
| Medios de pago | Transferencia; Tarjeta; Efectivo; Cheque; PAC; Otro; Depósito | Excel + operativo | Administrable | `payment_methods` | Implementado |
| Monedas | CLP; UF; USD; EUR | Excel + operativo | Administrable | `currencies` | Implementado |
| Actividades | Implementación; Soporte; Análisis; Administración | Operativo | Administrable | `activities` | Implementado |
| Estados de aprobación | Pendiente; Aprobado; Rechazado | Excel | Administrable | `approval_statuses` | Implementado |
| Tipos de gasto | Fijo; Variable; Directo; Indirecto; Administrativo; Comercial; Financiero; Tributario; Operacional | Excel + compatibilidad | Administrable | `expense_types` | Implementado |
| Categorías de gasto | Remuneraciones; Honorarios; Cotizaciones; Impuestos; Arriendo; Software y licencias; Servicios básicos; Contabilidad; Marketing; Transporte; Equipamiento; Comisiones bancarias; Proyecto; Otros | Excel | Administrable | `expense_categories` | Implementado |
| Subcategorías de gasto | Según históricos y alta administrativa | Operativo | Administrable | `expense_subcategories` | Implementado |
| Tipos de documento | Venta: Factura; Boleta; Nota de crédito; Otro. Gasto: Factura; Boleta honorarios; Boleta; Comprobante; Otro | Excel | Administrable | `document_types` | Implementado |
| Tipos de obligación | IVA/F29; Retención honorarios/F29; PPM/F29; Cotizaciones previsionales; Impuesto 2a categoría/F29; Otros legales | Excel | Administrable | `obligation_types` | Implementado |
| Organismos | SII; Previred; TGR; AFC; AFP; Fonasa/Isapre; Mutualidad; Otro | Operativo | Administrable | `legal_organizations` | Implementado |
| Mutualidades | ACHS; Mutual de Seguridad; IST; ISL; Otro | Operativo | Administrable | `occupational_insurance_entities` | Implementado |
| Bancos | Banco Estado; Banco de Chile; Santander; BCI; Itaú; Banco local | Operativo | Administrable | `banks` | Implementado |
| Tipos de cuenta bancaria | Corriente; Vista; Ahorro | Operativo | Administrable | `bank_account_types` | Implementado |
| Tipos de movimiento | Ingreso; Egreso | Operativo | Administrable | `cash_movement_types` | Implementado |
| Regímenes tributarios | Pro Pyme General; Pro Pyme Transparente; Régimen General; Otro | Operativo | Administrable | `tax_regimes` | Implementado |
| Escenarios | Conservador; Base; Optimista | Excel | Administrable | `scenarios` | Implementado |

## Parámetros y configuración base

| Elemento | Baseline | Destino | Estado |
| --- | --- | --- | --- |
| Moneda | CLP | `company_settings.currency` | Implementado |
| Fecha inicio modelo | 2026-07-01 | `company_settings.model_start_date` | Implementado |
| Mes análisis | 2026-07-01 | `company_settings.analysis_month` | Implementado |
| Saldo inicial | 0 | `company_settings.opening_balance` | Implementado |
| Plazo estándar clientes | 30 días | `company_settings.standard_client_payment_days` | Implementado |
| Margen mínimo proyecto | 30% | `company_settings.margin_minimum` | Implementado |
| Umbral concentración cliente | 40% | `company_settings.client_concentration_threshold` | Implementado |
| Días alerta obligaciones | 10 | `company_settings.obligation_alert_days` | Implementado |
| Escenario activo | BASE | `company_settings.active_scenario` | Implementado |
| Régimen tributario | Pro Pyme General | `company_settings.tax_regime_code` | Implementado |
| PPM activo | Sí | `company_settings.ppm_active` | Implementado |
| Provisión vacaciones | 8,33% | `company_settings.vacation_provision_rate` + `legal_parameters` | Implementado |
| UF fallback | 41.000 al 2026-07-31 | `uf_values` | Implementado |

## Parámetros legales por vigencia cargados

- IVA
- Retención honorarios 2026 y 2027
- AFP trabajador
- Salud mínima
- AFC trabajador indefinido
- AFC empleador indefinido
- AFC empleador plazo fijo/obra
- Ley 16.744 básica
- Ley 16.744 adicional
- SANNA
- PPM
- IDPC Pro Pyme referencial
- Tope imponible UF
- Tope AFC UF
- Provisión vacaciones
- Cotización empleador / SIS por tramos 2026-2027

## No implementado por diseño

- Motivos de rechazo, anulación y ajuste de caja: no se agregaron porque hoy no existe proceso/pantalla que los use.
- Tipos de centro de costo: no aportan valor operativo inmediato sobre el modelo actual.
- Catálogos editables para estados financieros derivados: excluidos intencionalmente.
