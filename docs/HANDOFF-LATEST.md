# HANDOFF LATEST — checkpoint Remuneraciones

Fecha: 2026-09-01 / 2026-09-02

Este archivo complementa temporalmente `docs/HANDOFF.md` con el estado más reciente. Debe leerse junto con `docs/HANDOFF.md` hasta que este delta sea consolidado allí.

## Estado funcional previo

PASS y NO repetir:
- Responsable Proyecto
- Presupuesto
- Centros de costo label
- Horas labels
- resto de módulos ya aprobados en UAT previa

Pendientes antes de este fix:
- Remuneración A
- Remuneración B
- Rentabilidad, dependiente de payroll

UAT FINAL seguía FAIL y NO apto para producción.

## Diagnóstico definitivo del selector Proyecto en Remuneraciones

Causa raíz:
`period_date` en Remuneraciones es `input type="text"`.

El JavaScript de Proyecto escuchaba solamente el evento `change` del campo período. Al ingresar/seleccionar la fecha, el valor real podía quedar actualizado por evento `input` antes de que ocurriera `change`; por lo tanto `syncPayrollProjects()` seguía evaluando `payrollPeriodInput.value` como vacío y mantenía Proyecto deshabilitado con placeholder `Seleccione el período primero`.

DOM/datos observados:
- `period_date type`: `text`
- valor inicial: vacío
- valores válidos reconocidos por `parsePayrollPeriodDate()`: `2026-09-01` y `01/09/2026`
- evento faltante: `input`
- ya existía listener `change`
- `assignment_ranges` de `QA-A-PROYECTO` contiene `person_id` correcto, `start_date: 2026-09-01`, `end_date: 2026-09-30`
- condición que fallaba: `!payrollPeriodInput?.value`

## Fix mínimo realizado localmente

Se agregó:

```js
payrollPeriodInput?.addEventListener('input', syncPayrollProjects);
```

Se conserva también el listener `change`.

Archivos modificados localmente:
- `resources/views/operational/form.blade.php`
- `tests/Feature/OperationalUiTest.php`

## Tests ejecutados

1. `php artisan test tests\Feature\PayrollTimeEntryTraceTest.php --filter=manual_hourly_payroll_with_iso_period_and_project_consumes_approved_time_entries`
   - PASS: 1 test, 10 assertions

2. `php artisan test tests\Feature\OperationalUiTest.php --filter=payroll_project_options_and_backend_require_assignment_for_period`
   - PASS: 1 test, 22 assertions

3. `php artisan test tests\Feature\PayrollTimeEntryTraceTest.php`
   - PASS: 10 tests, 88 assertions

## Commit funcional local

SHA reportado por Codex:
`832f8a369682806659a936d7779b73ee641bc7e0`

IMPORTANTE:
este SHA NO está confirmado en GitHub todavía. El conector GitHub devuelve `No commit found for SHA`, por lo que debe considerarse LOCAL / PENDIENTE DE PUSH.

NO asumir que `origin/main` contiene este fix hasta verificarlo después del push.

## Próximo paso exacto

1. Verificar estado local y que el commit `832f8a369682806659a936d7779b73ee641bc7e0` sea HEAD o esté en la rama que se va a publicar.
2. Push del commit funcional a `origin/main` sin perder los commits de documentación ya existentes en remoto. Como `main` remoto contiene commits de HANDOFF posteriores al release funcional anterior, resolver la integración mediante pull/rebase/merge seguro; NO forzar push ni sobrescribir historia.
3. Verificar que `origin/main` contenga tanto el fix funcional como la documentación HANDOFF.
4. Generar un nuevo release staging desde el HEAD integrado.
5. Validar manifest/checksums/ZIP/secrets.
6. Para este fix se espera cambio en Blade/JS del árbol privado, por lo que normalmente basta actualizar `app-private.zip`; verificar compare antes de decidir si `public.zip` cambió.
7. No importar bootstrap SQL.
8. No limpiar BD ni data QA.
9. Deploy staging incremental preservando `.env` y `storage/`.
10. Reprobar SOLO:
   - Remuneración A
   - Remuneración B
   - Rentabilidad

## Criterio de cierre

Si Remuneración A y B pasan, consumen horas aprobadas con trazabilidad correcta y Rentabilidad refleja costo laboral coherente:
- Remuneraciones PASS
- Rentabilidad PASS
- Escenario A end-to-end PASS
- Escenario B end-to-end PASS
- UAT FINAL PASS
- funcionalmente apto para producción: SI

No repetir Presupuesto, Responsable Proyecto, Centros de costo, Horas ni módulos ya PASS.

## Pendiente técnico post-UAT

Después de cerrar UAT, optimizar `scripts/build-cpanel-release.sh` con un modo rápido de staging incremental (por ejemplo `CPANEL_FAST_BUILD=true`) para evitar trabajo innecesario como regenerar bootstrap SQL y rehacer dependencias completas cuando no corresponde. Mantener build completo y todas las validaciones para release candidato final/producción.
