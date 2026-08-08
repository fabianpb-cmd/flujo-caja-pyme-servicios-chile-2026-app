# Auditoria de catalogos maestros

Fecha: 2026-08-07  
Alcance: migrations, Models, FormRequest, Seeders, Blade Forms, validaciones y lectura de valores distintos en base local.  
Restriccion: no se modifican migraciones, modelos, calculos ni logica financiera.

## Resumen ejecutivo

El MVP ya tiene integridad referencial en entidades principales como empresas, clientes, proyectos, personas, asignaciones, documentos, cuentas y movimientos. Sin embargo, varios campos operacionales siguen como texto libre o listas embebidas en `config/operational.php`, lo que puede generar inconsistencias por mayusculas, acentos, variantes historicas y cambios futuros.

Se proponen 41 catalogos maestros. La matriz audita 55 campos candidatos: Prioridad Alta: 32, Media: 13, Baja: 10. Los mayores riesgos estan en estados, tipos de documento, obligaciones, modalidades laborales, categorias de gasto, bancos/medios de pago y centros de costo.

## Lista de catalogos propuestos

| Dominio | Catalogo | Tabla sugerida | CRUD |
| --- | --- | --- | --- |
| Organizacion | Sucursales | `branches` | Usuario |
| Organizacion | Centros de costo | `cost_centers` | Usuario |
| Organizacion | Responsables | `project_managers` | Usuario |
| Organizacion | Areas | `areas` | Usuario |
| Clientes | Tipos de cliente | `client_types` | Usuario |
| Clientes | Estados cliente | `client_statuses` | Sistema |
| Clientes | Formas/plazos de pago | `payment_terms` | Usuario |
| Proyectos | Tipos de proyecto | `project_types` | Usuario |
| Proyectos | Estados proyecto | `project_statuses` | Sistema |
| Proyectos | Estados facturacion | `billing_statuses` | Sistema |
| Proyectos | Tipos contrato comercial | `commercial_contract_types` | Usuario |
| Personas | Cargos | `positions` | Usuario |
| Personas | Modalidades contratacion | `employment_modes` | Sistema |
| Personas | Tipos contrato laboral | `employment_contract_types` | Sistema |
| Personas | Profesiones | `professions` | Usuario |
| Personas | Especialidades | `specialties` | Usuario |
| Personas | Sistemas salud/Isapres | `health_systems` | Sistema |
| Personas | Bancos | `banks` | Sistema |
| Personas | Tipos cuenta bancaria | `bank_account_types` | Sistema |
| Personas | Estados trabajador | `worker_statuses` | Sistema |
| Operacion | Actividades | `activities` | Usuario |
| Operacion | Tipos de hora | `hour_types` | Usuario |
| Operacion | Estados aprobacion | `approval_statuses` | Sistema |
| Facturacion | Tipos documento venta | `sales_document_types` | Sistema |
| Facturacion | Estados cobro | `collection_statuses` | Sistema |
| Facturacion | Probabilidades cobro | `collection_probabilities` | Sistema |
| Gastos | Proveedores | `vendors` | Usuario |
| Gastos | Categorias gasto | `expense_categories` | Usuario |
| Gastos | Subcategorias gasto | `expense_subcategories` | Usuario |
| Gastos | Tipos gasto | `expense_types` | Usuario |
| Gastos | Tipos documento compra | `expense_document_types` | Sistema |
| Gastos | Estados pago | `payment_statuses` | Sistema |
| Tesoreria | Tipos movimiento caja | `cash_movement_types` | Sistema |
| Tesoreria | Tipos origen documento | `cash_source_document_types` | Sistema |
| Tesoreria | Medios de pago | `payment_methods` | Usuario |
| Obligaciones | Tipos obligacion | `obligation_types` | Sistema |
| Obligaciones | Organismos | `agencies` | Sistema |
| Seguridad | Roles | `roles` | Sistema |
| Seguridad | Permisos | `permissions` | Sistema |
| Auditoria | Acciones auditoria | `audit_actions` | Sistema |
| Cierres | Estados cierre | `closure_statuses` | Sistema |

## Matriz de auditoria

| Campo | Tabla | Tipo actual | Debe ser catalogo | Catalogo sugerido | Prioridad | Justificacion |
| --- | --- | --- | --- | --- | --- | --- |
| `status` | `companies` | string(20) | Si | `company_statuses` | Media | Estado transversal; hoy existe `active`. |
| `setting_key` | `company_settings` | string(100) | Si | `setting_definitions` | Media | Clave funcional repetible; debe controlar tipo y visibilidad. |
| `setting_type` | `company_settings` | string(20) | Si | `setting_types` | Baja | Lista tecnica estable: string, decimal, integer, boolean. |
| `parameter_code` | `legal_parameters` | string(80) | Si | `legal_parameter_types` | Alta | Parametros legales por vigencia requieren codigos consistentes. |
| `unit` | `legal_parameters` | string(20) | Si | `measurement_units` | Media | Evita variantes como `%`, percent, UF, CLP. |
| `source` | `legal_parameters` | string | No | - | Baja | Fuente textual/documental, puede variar libremente. |
| `source` | `uf_values` | string | No | - | Baja | Fuente historica libre. |
| `status` | `clients` | string(20) | Si | `client_statuses` | Alta | Ya se usa como select; debe ser FK. |
| `payment_term_days` | `clients` | smallint | Si | `payment_terms` | Media | Terminos comerciales repetitivos; mantener dias como atributo. |
| `manager` | `projects` | string | Si | `project_managers` | Media | Responsable repetitivo; puede apuntar a usuarios/personas. |
| `contract_type` | `projects` | string(40) | Si | `commercial_contract_types` | Alta | Impacta lectura comercial y presupuesto. |
| `payment_form` | `projects` | string(40) | Si | `payment_terms` | Alta | Forma de pago repetitiva; hoy ejemplo `Transferencia`. |
| `project_status` | `projects` | string(20) | Si | `project_statuses` | Alta | Estado operativo debe ser controlado. |
| `billing_status` | `projects` | string(20) | Si | `billing_statuses` | Alta | Estado de facturacion afecta UAT y reportes. |
| `role` | `people` | string | Si | `positions` | Alta | Cargo repetitivo; ejemplo contiene nombre de cargo variable. |
| `modality` | `people` | string(40) | Si | `employment_modes` | Alta | Campo con logica de remuneraciones; hoy select embebido. |
| `contract_type` | `people` | string(40) | Si | `employment_contract_types` | Alta | Debe evitar variantes legales/laborales. |
| `health_system` | `people` | string(40) | Si | `health_systems` | Alta | Isapre/Fonasa requiere lista controlada. |
| `payment_data` | `people` | text | Parcial | `banks`, `bank_account_types` | Alta | Hoy texto libre; deberia separarse en banco, tipo cuenta, numero. |
| `status` | `people` | string(20) | Si | `worker_statuses` | Alta | Estado trabajador repetitivo y operativo. |
| `cost_center` | `project_assignments` | string | Si | `cost_centers` | Alta | Necesario para control de gestion. |
| `status` | `project_assignments` | string(20) | Si | `assignment_statuses` | Media | Estado operativo repetitivo. |
| `activity` | `time_entries` | string | Si | `activities` | Media | Puede ser catalogo administrable; permite analitica de horas. |
| `approval_status` | `time_entries` | string(20) | Si | `approval_statuses` | Alta | Ya es select; debe ser controlado. |
| `payment_status` | `time_entries` | string(20) | Si | `payment_statuses` | Alta | Estado de pago transversal. |
| `cost_center` | `time_entries` | string | Si | `cost_centers` | Alta | Debe compartir catalogo con asignaciones. |
| `status` | `payroll_records` | string(20) | Si | `payroll_statuses` | Alta | Incluye `Falta fecha`; estado derivado debe ser consistente. |
| `document_type` | `sales_documents` | string(40) | Si | `sales_document_types` | Alta | Factura/boleta/nota deben ser codigos controlados. |
| `status` | `sales_documents` | string(20) | Si | `collection_statuses` | Alta | Pagado/Parcial/Vencido/Pendiente/Anulado. |
| `document_number` | `sales_documents` | string(60) | No | - | Baja | Identificador externo; debe seguir libre con validacion. |
| `vendor_name` | `expense_documents` | string | Si | `vendors` | Media | Proveedores se repetiran; hoy no hay FK. |
| `category` | `expense_documents` | string(80) | Si | `expense_categories` | Alta | Afecta dashboard de egresos y analitica. |
| `subcategory` | `expense_documents` | string(80) | Si | `expense_subcategories` | Alta | Debe depender de categoria. |
| `expense_type` | `expense_documents` | string(40) | Si | `expense_types` | Media | Clasificacion tributaria/operativa repetitiva. |
| `document_type` | `expense_documents` | string(40) | Si | `expense_document_types` | Alta | Factura compra/boleta/honorario/etc. |
| `payment_status` | `expense_documents` | string(20) | Si | `payment_statuses` | Alta | Pagado/Parcial/Vencido/Pendiente/Anulado. |
| `document_number` | `expense_documents` | string(60) | No | - | Baja | Numero externo variable. |
| `obligation_type` | `legal_obligations` | string(80) | Si | `obligation_types` | Alta | Base tiene variantes con acentos y separadores; riesgo alto. |
| `status` | `legal_obligations` | string(20) | Si | `obligation_statuses` | Alta | Estado financiero/recaudatorio derivado. |
| `source_calculation` | `legal_obligations` | string | No | - | Baja | Texto explicativo de calculo. |
| `name` | `scenarios` | string | Si | `scenarios` existente | Baja | Ya es entidad administrable. |
| `institution` | `cash_accounts` | string | Si | `banks` | Alta | Banco/institucion financiera debe normalizarse. |
| `account_type` | `cash_accounts` | string(40) | Si | `bank_account_types` | Media | Corriente/vista/ahorro. |
| `currency` | `cash_accounts` | string(10) | Si | `currencies` | Media | CLP/UF/USD si aplica. |
| `movement_type` | `cash_movements` | string(40) | Si | `cash_movement_types` | Alta | Regla caja real requiere ingreso/egreso consistente. |
| `source_document_type` | `cash_movements` | string(40) | Si | `cash_source_document_types` | Alta | Evita duplicacion y errores de origen. |
| `counterparty_name` | `cash_movements` | string | Parcial | `clients`, `vendors`, `people` | Media | Puede seguir snapshot textual, pero conviene FK opcional. |
| `payment_method` | `cash_movements` | string(40) | Si | `payment_methods` | Alta | Transferencia, efectivo, tarjeta, cheque. |
| `reference` | `cash_movements` | string | No | - | Baja | Numero/folio bancario libre. |
| `status` | `cash_movements` | string(20) | Si | `cash_movement_statuses` | Alta | posted/voided/conciliado si se agrega conciliacion. |
| `status` | `monthly_closures` | string(20) | Si | `closure_statuses` | Alta | open/closed/reopened afecta bloqueo. |
| `action` | `audit_logs` | string(100) | Si | `audit_actions` | Media | Mejora reportabilidad sin impedir trazabilidad textual. |
| `ip_address` | `audit_logs` | string(45) | No | - | Baja | Dato tecnico variable. |
| `user_agent` | `audit_logs` | text | No | - | Baja | Dato tecnico variable. |
| `role` | `users` | string(30) | Si | `roles` | Alta | Seguridad no debe depender de texto libre. |

## Modelo normalizado sugerido

Estructura estandar para catalogos simples:

| Columna | Tipo | Nota |
| --- | --- | --- |
| `id` | PK | Interno. |
| `company_id` | FK nullable | Null para catalogos sistema; valor para catalogos por empresa. |
| `code` | string unique por alcance | Codigo funcional estable. |
| `name` | string | Nombre visible. |
| `description` | text nullable | Ayuda administrativa. |
| `status` | string/FK | Activo/Inactivo. |
| `sort_order` | unsignedSmallInteger | Orden UI. |
| `created_at`, `updated_at` | timestamps | Auditoria basica. |

Catalogos jerarquicos:

| Tabla | Dependencia |
| --- | --- |
| `expense_subcategories` | `expense_category_id` |
| `specialties` | `profession_id` nullable |
| `project_managers` | `user_id` o `person_id` nullable |
| `bank_account_types` | independiente |
| `payment_terms` | atributos `days`, `method_code` opcional |

## Dependencias FK futuras

| Tabla actual | FK futura |
| --- | --- |
| `companies.status` | `company_status_id` |
| `clients.status` | `client_status_id` |
| `clients.payment_term_days` | `payment_term_id` conservando `payment_term_days` como denormalizado o derivado |
| `projects.manager` | `project_manager_id` |
| `projects.contract_type` | `commercial_contract_type_id` |
| `projects.payment_form` | `payment_term_id` o `payment_method_id` segun definicion final |
| `projects.project_status` | `project_status_id` |
| `projects.billing_status` | `billing_status_id` |
| `people.role` | `position_id` |
| `people.modality` | `employment_mode_id` |
| `people.contract_type` | `employment_contract_type_id` |
| `people.health_system` | `health_system_id` |
| `people.payment_data` | `bank_id`, `bank_account_type_id`, `bank_account_number` |
| `people.status` | `worker_status_id` |
| `project_assignments.cost_center` | `cost_center_id` |
| `time_entries.activity` | `activity_id` |
| `time_entries.approval_status` | `approval_status_id` |
| `time_entries.payment_status` | `payment_status_id` |
| `time_entries.cost_center` | `cost_center_id` |
| `payroll_records.status` | `payroll_status_id` |
| `sales_documents.document_type` | `sales_document_type_id` |
| `sales_documents.status` | `collection_status_id` |
| `expense_documents.vendor_name` | `vendor_id` |
| `expense_documents.category` | `expense_category_id` |
| `expense_documents.subcategory` | `expense_subcategory_id` |
| `expense_documents.expense_type` | `expense_type_id` |
| `expense_documents.document_type` | `expense_document_type_id` |
| `expense_documents.payment_status` | `payment_status_id` |
| `legal_obligations.obligation_type` | `obligation_type_id` |
| `legal_obligations.status` | `obligation_status_id` |
| `cash_accounts.institution` | `bank_id` |
| `cash_accounts.account_type` | `bank_account_type_id` |
| `cash_accounts.currency` | `currency_id` |
| `cash_movements.movement_type` | `cash_movement_type_id` |
| `cash_movements.source_document_type` | `cash_source_document_type_id` |
| `cash_movements.payment_method` | `payment_method_id` |
| `cash_movements.status` | `cash_movement_status_id` |
| `monthly_closures.status` | `closure_status_id` |
| `audit_logs.action` | `audit_action_id` opcional |
| `users.role` | `role_id` |

## Seeders recomendados

Precargar como sistema: AFP, Isapres/Fonasa, bancos base Chile, tipos de cuenta bancaria, monedas, tipos documento venta/compra, estados de cobro/pago/aprobacion, tipos movimiento caja, tipos origen documento, tipos obligacion, organismos tributarios/previsionales, roles, permisos, acciones auditoria y estados cierre.

Precargar como empresa demo: centros de costo iniciales, responsables demo, areas, categorias/subcategorias de gasto, actividades, tipos de proyecto, tipos contrato comercial y terminos de pago. Estos deben ser editables por el usuario.

## Administracion y menu sugerido

Administracion:

- Organizacion: Empresas, Sucursales, Centros de costo, Areas, Responsables.
- Personas: Cargos, Modalidades, Tipos contrato, Profesiones, Especialidades, Salud, Bancos, Tipos cuenta.
- Comercial: Tipos cliente, Estados cliente, Tipos proyecto, Estados proyecto, Contratos comerciales, Formas/plazos pago.
- Finanzas: Tipos documento, Categorias gasto, Subcategorias, Tipos gasto, Medios pago, Monedas, Tipos movimiento, Tipos obligacion, Organismos.
- Seguridad: Usuarios, Roles, Permisos.
- Auditoria: Acciones auditoria, bitacora.
- Parametros: Parametros legales, UF, definiciones de configuracion.

## Prioridades

Alta:

- Estados financieros/operativos: documentos, pagos, cobros, remuneraciones, obligaciones, caja y cierres.
- Campos con impacto en calculos o reportes: modalidad, tipos documento, obligaciones, categorias gasto, medios pago, origen caja.
- Campos con riesgo de duplicacion por texto: cargos, centros de costo, bancos, formas de pago.

Media:

- Campos administrativos que mejoran filtros y reporting: responsables, proveedores, actividades, tipos proyecto, account types, audit actions.

Baja:

- Fuentes, referencias externas, notas, user agent, numeros de documento y descripciones deben seguir como texto libre con validaciones.

## Plan tecnico de migracion futura

1. Crear catalogos base con estructura comun y seeders sistema/empresa.
2. Poblar catalogos desde valores distintos actuales, normalizando acentos, mayusculas y sinonimos.
3. Agregar columnas FK nullable en tablas afectadas, manteniendo columnas texto antiguas para compatibilidad.
4. Ejecutar scripts de conversion por tabla: resolver valor texto a catalogo, registrar excepciones y no adivinar casos ambiguos.
5. Actualizar FormRequest y `config/operational.php` para usar `relation` en lugar de `select/text` embebido.
6. Actualizar importador para resolver o crear catalogos administrables; para catalogos sistema, rechazar valores desconocidos con warning controlado.
7. Migrar reportes y filtros a FK, manteniendo columnas texto como snapshot durante una version.
8. Agregar constraints `NOT NULL` solo despues de validar conversion completa en QA.
9. Retirar columnas texto legacy en una migracion posterior, no en el mismo despliegue.
10. Crear pruebas de conversion, importacion, permisos de administracion y compatibilidad de UAT.

## Riesgos

| Riesgo | Impacto | Mitigacion |
| --- | --- | --- |
| Valores historicos con tildes/variantes (`RETENCIóN`, `IVA_/_F29`) | Alto | Tabla de equivalencias antes de convertir. |
| Estados derivados convertidos manualmente | Alto | Mantener derivacion en servicios; catalogo solo valida codigo. |
| Importaciones Excel con textos nuevos | Medio | Modo dry-run debe listar catalogos faltantes. |
| Catalogos por empresa vs sistema | Medio | Definir `company_id nullable` y alcance unico. |
| Borrar texto legacy muy temprano | Alto | Mantener compatibilidad por al menos una version UAT. |
| Proveedores/contrapartes incompletos | Medio | Crear vendor/client/person FK opcional y conservar snapshot textual. |

## Compatibilidad

La migracion debe ser incremental. En la primera etapa, las columnas texto actuales no se eliminan; se agregan FK y se sincronizan. Esto permite revertir vistas/formularios si UAT detecta un caso no contemplado y evita alterar calculos financieros existentes.

## Fase separada para estados derivados

Los siguientes estados no deben normalizarse en la misma etapa que los catalogos administrativos, porque hoy dependen de logica financiera y trazabilidad transaccional:

- `sales_documents.status`
- `expense_documents.payment_status`
- `payroll_records.status`
- `legal_obligations.status`

Su futura normalizacion debe evaluarse en una fase separada, preservando la derivacion automatica y evitando regresiones en flujo, CxC/CxP, remuneraciones y obligaciones.

## Inventario legacy sincronizado

| Tabla | Columna legacy | FK reemplazo | Lecturas actuales | Escrituras actuales | ¿Retirable? |
| --- | --- | --- | --- | --- | --- |
| `clients` | `status` | `client_status_id` | Compatibilidad/importacion | `CatalogService::syncClientFields()` | MANTENER HASTA POST-UAT |
| `clients` | `payment_term_days` | `payment_term_id` | Compatibilidad/reporting simple | `CatalogService::syncClientFields()` | MANTENER HASTA POST-UAT |
| `projects` | `manager` | `manager_id` | Compatibilidad/importacion | `CatalogService::syncProjectFields()` | MANTENER HASTA POST-UAT |
| `projects` | `contract_type` | `contract_type_id` | Compatibilidad/importacion | `CatalogService::syncProjectFields()` | MANTENER HASTA POST-UAT |
| `projects` | `payment_form` | `payment_term_id` | Compatibilidad/importacion | `CatalogService::syncProjectFields()` | MANTENER HASTA POST-UAT |
| `projects` | `project_status` | `project_status_id` | Compatibilidad/importacion | `CatalogService::syncProjectFields()` | MANTENER HASTA POST-UAT |
| `projects` | `billing_status` | `billing_status_id` | Compatibilidad/importacion | `CatalogService::syncProjectFields()` | MANTENER HASTA POST-UAT |
| `people` | `role` | `position_id` | Compatibilidad/importacion | `CatalogService::syncPeopleFields()` | MANTENER HASTA POST-UAT |
| `people` | `modality` | `employment_mode_id` | Fallback de remuneraciones + importacion | `CatalogService::syncPeopleFields()` | MANTENER HASTA POST-UAT |
| `people` | `contract_type` | `employment_contract_type_id` | Compatibilidad/importacion | `CatalogService::syncPeopleFields()` | MANTENER HASTA POST-UAT |
| `people` | `status` | `worker_status_id` | Compatibilidad/importacion | `CatalogService::syncPeopleFields()` | MANTENER HASTA POST-UAT |
| `project_assignments` | `cost_center` | `cost_center_id` | Compatibilidad/importacion | `CatalogService::syncAssignmentFields()` | MANTENER HASTA POST-UAT |
| `project_assignments` | `status` | `assignment_status_id` | Compatibilidad/importacion | `CatalogService::syncAssignmentFields()` | MANTENER HASTA POST-UAT |
| `time_entries` | `activity` | `activity_id` | Compatibilidad/importacion | `CatalogService::syncTimeEntryFields()` | MANTENER HASTA POST-UAT |
| `time_entries` | `approval_status` | `approval_status_id` | Compatibilidad/importacion | `CatalogService::syncTimeEntryFields()` | MANTENER HASTA POST-UAT |
| `time_entries` | `cost_center` | `cost_center_id` | Compatibilidad/importacion | `CatalogService::syncTimeEntryFields()` | MANTENER HASTA POST-UAT |
| `sales_documents` | `document_type` | `document_type_id` | Compatibilidad/importacion | `CatalogService::syncDocumentField()` | MANTENER HASTA POST-UAT |
| `expense_documents` | `category` | `expense_category_id` | Compatibilidad/importacion | `CatalogService::syncExpenseFields()` | MANTENER HASTA POST-UAT |
| `expense_documents` | `subcategory` | `expense_subcategory_id` | Compatibilidad/importacion | `CatalogService::syncExpenseFields()` | MANTENER HASTA POST-UAT |
| `expense_documents` | `expense_type` | `expense_type_id` | Compatibilidad/importacion | `CatalogService::syncExpenseFields()` | MANTENER HASTA POST-UAT |
| `expense_documents` | `document_type` | `document_type_id` | Compatibilidad/importacion | `CatalogService::syncExpenseFields()` | MANTENER HASTA POST-UAT |
| `legal_obligations` | `obligation_type` | `obligation_type_id` | Compatibilidad/importacion | `CatalogService::syncLegacyFields()` | MANTENER HASTA POST-UAT |
| `cash_accounts` | `institution` | `bank_id` | Compatibilidad/importacion | `CatalogService::syncCashAccountFields()` | MANTENER HASTA POST-UAT |
| `cash_accounts` | `account_type` | `bank_account_type_id` | Compatibilidad/importacion | `CatalogService::syncCashAccountFields()` | MANTENER HASTA POST-UAT |
| `cash_accounts` | `currency` | `currency_id` | Compatibilidad/importacion | `CatalogService::syncCashAccountFields()` | MANTENER HASTA POST-UAT |
| `cash_movements` | `payment_method` | `payment_method_id` | Compatibilidad/importacion | `CatalogService::syncCashMovementFields()` | MANTENER HASTA POST-UAT |
| `cash_movements` | `movement_type` | `movement_type_id` | Compatibilidad/importacion | `CatalogService::syncCashMovementFields()` | MANTENER HASTA POST-UAT |

Resumen: no hay columnas duplicadas clasificadas como RETIRABLE AHORA. La fuente primaria ya es la FK en formularios nuevos, pero las columnas legacy todavia sostienen importacion, compatibilidad UAT y fallback controlado.

## Fuente de verdad confirmada

- Altas y ediciones nuevas usan FK/selects activos en `config/operational.php`.
- La sincronizacion temporal hacia texto legacy ocurre en `CatalogService`.
- Los historicos con catalogos inactivos se preservan y siguen visibles en edicion.
- Ajuste aplicado: `PayrollService` ahora prioriza `employment_mode_id` como fuente primaria y deja `people.modality` solo como fallback legacy.
- No se detecto dependencia operativa P1 de texto legacy en clientes, proyectos, gastos, cuentas o movimientos fuera de importacion/compatibilidad.

## Decision sobre estados derivados

| Campo | Decision |
| --- | --- |
| `sales_documents.status` | B. Almacenado y sincronizado automaticamente por `ReceivablesService`; no editable como catalogo. |
| `expense_documents.payment_status` | B. Almacenado y sincronizado automaticamente por `PayablesService`; no editable como catalogo. |
| `payroll_records.status` | B. Almacenado y sincronizado automaticamente por `PayrollService`; no editable como catalogo. |
| `legal_obligations.status` | B. Almacenado y sincronizado automaticamente por `LegalObligationService`; no editable como catalogo. |

Conclusión: para UAT estos estados deben seguir siendo financieros derivados, no mantenedores editables.

## Mantenedores pendientes relevantes

| Clasificacion | Elementos |
| --- | --- |
| P1 UAT | Ninguno. |
| POST-UAT | `vendors`, `agencies`, `cash_source_document_types`, separacion estructurada de `people.payment_data`, `users.role` a FK, estados derivados en fase aparte si se requiere mayor gobernanza. |
| NO IMPLEMENTAR | Fuentes, referencias, user agent, IP, notas, numeros de documento y otros textos descriptivos libres. |

## Decision GO / NO-GO UAT

Normalización lista para UAT: SI

- P1 pendientes: ninguno.
- Deuda POST-UAT: retiro gradual de columnas legacy una vez cerrada la compatibilidad de importacion/UAT y normalizacion adicional no financiera.
- Columnas legacy a retirar después de UAT: todas las listadas en el inventario, salvo que alguna se mantenga como snapshot deliberado por reportabilidad historica.
- Validacion segura completada en SQLite/testing; la verificacion destructiva `migrate:fresh --seed` sobre MySQL local principal no debe ejecutarse sin una base QA separada.
