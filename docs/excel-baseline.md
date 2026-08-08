# Excel baseline funcional

Fuente unica revisada una sola vez: `Flujo_Caja_Pyme_Servicios_Chile_2026_V3.xlsx`.

## Alcance

El workbook define una pyme de servicios con:

- configuracion general;
- parametros legales historicos;
- UF historica;
- clientes, proyectos y asignaciones;
- horas, remuneraciones, ingresos y egresos;
- obligaciones legales;
- presupuesto, flujo mensual y semanal;
- rentabilidad por proyecto y dashboard;
- movimientos reales de caja;
- control de cambios QA V3.

## Entidades principales

- `companies`
- `company_settings`
- `legal_parameters`
- `uf_values`
- `afps`
- `afp_rates`
- `clients`
- `projects`
- `people`
- `project_assignments`
- `time_entries`
- `payroll_records`
- `sales_documents`
- `expense_documents`
- `legal_obligations`
- `budgets`
- `scenarios`
- `cash_accounts`
- `cash_movements`
- `monthly_closures`
- `audit_logs`

## Relaciones funcionales

- una empresa agrupa parametros, clientes, proyectos, personas y caja;
- un cliente tiene muchos proyectos;
- un proyecto puede tener muchas facturas, egresos, horas, asignaciones y movimientos de caja;
- una persona puede tener asignaciones, horas y remuneraciones;
- una remuneracion puede derivarse de sueldo mensual, hora o proyecto;
- una factura/documento es distinto de un movimiento de caja;
- un documento puede tener muchos movimientos parciales;
- las obligaciones se calculan desde ventas, egresos y remuneraciones;
- los cierres mensuales leen saldos, CxC, CxP y movimientos reales.

## Reglas financieras clave

- usar siempre `ID Proyecto` como llave logica;
- no usar descripciones concatenadas como llave de relacion;
- filas vacias de proyectos/personas no deben convertirse en `0`;
- probabilidad vacia con saldo pendiente equivale a `100%` para forecast y genera alerta;
- fecha real de cobro sin monto o monto sin fecha generan alerta;
- una remuneracion con fecha prevista vacia no debe quedar como vencida; estado: `Falta fecha`;
- la UF y las tasas legales deben resolverse por fecha/vigencia, no por valor global unico;
- los calculos historicos no deben cambiar cuando cambian tasas futuras;
- si existen movimientos en `19_Movimientos_Caja`, el flujo real prioriza esa fuente;
- evitar doble contabilizacion entre documentos y caja real;
- CxC y CxP deben poder cerrarse a una fecha historica sin arrastre de pagos futuros;
- la provision de vacaciones impacta rentabilidad, no caja real hasta pago efectivo.

## Reglas de estado

### Ingresos

- `Pagado`: monto cobrado >= total.
- `Parcial`: 0 < monto cobrado < total.
- `Vencido`: monto cobrado = 0 y vencimiento < hoy.
- `Pendiente`: caso base.
- `Anulado`: mantener como estado excepcional.
- `REVISAR: fecha sin monto`: fecha real informada y monto vacio o cero.
- `REVISAR: monto sin fecha`: monto > 0 y fecha real vacia.

### Remuneraciones

- `Pagado`: monto pagado >= total.
- `Parcial`: monto pagado entre 0 y total.
- `Vencido`: solo cuando hay fecha prevista y esta vencida.
- `Falta fecha`: monto liquido > 0 y fecha prevista vacia.

### Proyectos / rentabilidad

- filas vacias no son proyectos;
- alertas de margen bajo deben contar solo proyectos validos;
- usar `Tarifa pactada` y `Tarifa promedio efectiva` por separado.

## Parametros legales y UF

- `02_Parametros_Legales` guarda vigencias historicas;
- retencion de honorarios debe distinguir 2026 y 2027;
- topes previsionales y AFC deben evaluarse por periodo;
- `02b_UF` contiene UF historica por fecha;
- si falta UF para una fecha, la app usa fallback y alerta en configuracion.

## CxC / CxP

- CxC as-of = facturas emitidas hasta la fecha menos cobros realizados hasta la fecha;
- CxP as-of = obligaciones/gastos generados hasta la fecha menos pagos realizados hasta la fecha;
- los pagos parciales se registran en `19_Movimientos_Caja`;
- un mismo documento puede tener varios movimientos;
- no se debe reescribir el saldo historico cuando se carga un pago futuro.

## Remuneraciones

- dependientes mensuales: sueldo proporcional por dias efectivos del periodo;
- dias efectivos = interseccion entre mes, fecha inicio y fecha termino del trabajador;
- pago por hora y por proyecto no usan la misma logica de prorrateo;
- honorarios historicos usan retencion por vigencia;
- provision de vacaciones puede ir al costo economico, no al cash flow.

## Obligaciones

- obligaciones estimadas salen de ventas, egresos y remuneraciones;
- estado normal: pendiente/parcial/pagado/vencido;
- IVA, retenciones y previsionales viven separados del flujo de caja real.

## Forecast y flujo de caja

- `13_Flujo_Mensual` y `14_Flujo_Semanal` leen proyeccion y caja real;
- si existe `19_Movimientos_Caja`, esa tabla manda para ingresos/egresos reales;
- el forecast no debe desaparecer por probabilidad vacia;
- el saldo historico de un mes cerrado no puede cambiar por pagos futuros.

## QA V3 incorporado

- `12_Presupuesto`: corregido filtro por proyecto usando ID logico.
- `15_Rentabilidad` y `17_Dashboard`: filas vacias no se convierten en proyecto `0`.
- `09_Ingresos`: probabilidad vacia no equivale a `0%`.
- `09_Ingresos`: controles de consistencia fecha/monto de cobro.
- `08_Remuneraciones`: fecha prevista vacia => `Falta fecha`.
- tasas legales historicas y UF historica quedaron con estructura de vigencia/fallback.
- `19_Movimientos_Caja` queda como capa de caja real y pagos parciales.

## Mapeo Excel -> app

- `00_Manual` -> `docs/excel-baseline.md` como referencia funcional persistente.
- `01_Config` -> configuracion global de empresa y escenario.
- `02_Parametros_Legales` -> `legal_parameters` + `afps`/`afp_rates`.
- `02b_UF` -> `uf_values`.
- `04_Clientes` -> `clients`.
- `05_Proyectos` -> `projects`.
- `06_Personal` -> `people`.
- `06b_Asignaciones` -> `project_assignments`.
- `07_Horas` -> `time_entries`.
- `08_Remuneraciones` -> `payroll_records`.
- `09_Ingresos` -> `sales_documents`.
- `10_Egresos` -> `expense_documents`.
- `11_Obligaciones` -> `legal_obligations`.
- `12_Presupuesto` -> `budgets`.
- `13_Flujo_Mensual` y `14_Flujo_Semanal` -> vistas/consultas de cash flow.
- `15_Rentabilidad` -> margen por proyecto/cliente.
- `16_Escenarios` -> `scenarios`.
- `17_Dashboard` -> resumen ejecutivo.
- `19_Movimientos_Caja` -> `cash_movements`.
- `20_QA_V3` -> control de cambios funcional y trazabilidad.

## Nota de implementacion

No se importaron datos productivos; esta fase deja la estructura local, el contrato funcional y el modelo relacional listos para CRUD y dashboard posteriores.
