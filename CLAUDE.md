# Flujo de Caja Pyme

## Objetivo

Aplicación web financiera para una pequeña empresa chilena de servicios.

Stack:
- PHP
- Laravel
- MySQL
- Blade
- Bootstrap
- Chart.js

Desarrollo actual:
- macOS local
- MySQL local
- URL UAT: http://127.0.0.1:8000

El despliegue productivo en servidor/subdominio se realizará posteriormente.

## Prioridades

1. Exactitud financiera.
2. Integridad de datos.
3. Seguridad.
4. Trazabilidad.
5. UX.
6. Automatización.
7. Diseño visual.

No agregar features innecesarios durante UAT.

## Regla de eficiencia

Minimiza uso de tokens y contexto.

- Lee solamente archivos necesarios.
- No recorras todo el repositorio antes de cada tarea.
- No vuelvas a analizar el Excel completo salvo necesidad explícita.
- Usa primero la documentación existente.
- No expliques código ya existente.
- Modifica directamente.
- Ejecuta tests relevantes.
- Respuesta final breve.

## Documentación funcional existente

Antes de buscar nuevamente reglas, consultar según necesidad:

- docs/excel-baseline.md
- docs/qa-local.md
- docs/catalog-audit.md
- docs/administration-baseline.md si existe
- docs/uat.md si existe

El Excel V3 es referencia secundaria, no debe releerse completamente para cada tarea.

## Reglas críticas

### Documentos vs caja

Los documentos representan derechos u obligaciones.

Los movimientos de caja representan dinero real.

Flujo real debe provenir de cash_movements.

Nunca sumar documento + movimiento como dos movimientos de caja.

### Códigos

- id = PK técnica interna.
- code = identificador funcional.
- Generar code automáticamente cuando corresponda.
- code no debe ser editable después de creación.
- Preservar códigos históricos importados.
- No usar MAX(code)+1 sin protección de concurrencia.

### Catálogos

Usar FK y mantenedores existentes.

No volver a convertir en texto libre:
- cargos
- modalidades
- contratos
- responsables
- centros de costo
- bancos
- categorías
- subcategorías
- medios de pago
- tipos de documento
- etc.

### Estados financieros

Los estados derivados NO son mantenedores editables:

- sales_documents.status
- expense_documents.payment_status
- payroll_records.status
- legal_obligations.status

Se calculan/sincronizan por reglas del sistema.

### Formato Chile

CLP:
$ 1.390.112

Sin decimales visibles normalmente.

UF:
UF 40.844,79

Separador miles:
.

Separador decimal:
,

RUT:
12.345.678-5 en presentación.

### Dependencias

Validar frontend y backend:

Cliente -> Proyecto

Región -> Comuna

Categoría -> Subcategoría

Nunca permitir combinaciones inconsistentes.

### Remuneraciones

Usar parámetros legales por vigencia.

No hardcodear tasas en PayrollService.

Los cálculos históricos deben conservar las tasas correspondientes al período.

Los payroll confirmados deben preservar snapshot de parámetros/tasas utilizados.

### Código financiero

Dinero:
DECIMAL, nunca FLOAT.

Operaciones críticas:
DB::transaction().

No confiar solo en validación JavaScript.

## Base de datos

Base local MySQL existente.

NO ejecutar:

php artisan migrate:fresh

sobre la base UAT actual salvo instrucción explícita.

Preferir:

php artisan migrate

Antes de cambios estructurales:
revisar migrations y datos existentes.

## Tests

Antes de entregar cualquier cambio relevante:

php artisan optimize:clear
php artisan test

Todos los tests existentes deben continuar pasando.

## Git

Antes de modificaciones grandes:
revisar git status.

Después de modificar:
mostrar git diff --stat.

No hacer commit automáticamente salvo instrucción explícita.

## UI

Sidebar:
- Blade controla active.
- JavaScript NO controla active.
- JS solo scroll/collapse/offcanvas.
- solo un sidebar-link puede estar is-active.

Tablas anchas:
- Acciones al inicio.
- Acciones sticky cuando corresponda.
- tablas responsive.
- montos alineados a derecha.

No introducir React/Vue/SPAs.

Mantener Blade + Bootstrap.

## Alcance actual

Estamos en UAT local.

NO implementar todavía:
- producción
- DNS
- subdominio
- SSL
- Nginx
- Apache
- SSO
- integraciones bancarias
- integraciones SII/Previred

salvo solicitud explícita.

## Regla final

Antes de modificar una funcionalidad:
1. identifica causa raíz;
2. modifica lo mínimo necesario;
3. preserva comportamiento existente;
4. prueba;
5. informa resultado brevemente.