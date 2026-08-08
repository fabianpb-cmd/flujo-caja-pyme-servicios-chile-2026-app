<?php

namespace App\Services\Import;

use App\Models\Afp;
use App\Models\Budget;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\LegalParameter;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\SalesDocument;
use App\Models\Scenario;
use App\Models\TimeEntry;
use App\Models\UfValue;
use App\Services\CashMovementService;
use App\Services\PayablesService;
use App\Services\PayrollService;
use App\Services\ReceivablesService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class FinanceExcelImporter
{
    private XlsxWorkbookReader $reader;
    private bool $dryRun = false;
    private ?Company $company = null;

    /** @var array<string, array<string, true>> */
    private array $known = [
        'clients' => [],
        'projects' => [],
        'people' => [],
        'assignments' => [],
        'scenarios' => [],
        'cash_accounts' => [],
        'sales_documents' => [],
        'expense_documents' => [],
        'payroll_records' => [],
        'legal_obligations' => [],
    ];

    private array $report = [];

    public function __construct(
        private readonly ReceivablesService $receivables,
        private readonly PayablesService $payables,
        private readonly PayrollService $payroll,
        private readonly CashMovementService $cashMovements,
    ) {
    }

    public function import(string $path, bool $dryRun = false): array
    {
        $this->reader = new XlsxWorkbookReader($path);
        $this->dryRun = $dryRun;
        $this->report = [
            'source' => $path,
            'dry_run' => $dryRun,
            'generated_at' => now()->toIso8601String(),
            'sheets' => [],
            'qa' => [],
        ];

        $this->seedReportBuckets([
            'configuracion',
            'parametros_legales',
            'uf',
            'clientes',
            'proyectos',
            'personal',
            'asignaciones',
            'horas',
            'remuneraciones',
            'ingresos',
            'egresos',
            'obligaciones',
            'presupuesto',
            'escenarios',
            'movimientos',
        ]);

        $runner = function (): void {
            $this->importConfig();
            $this->loadKnownFromDatabase();
            $this->importLegalParameters();
            $this->importUf();
            $this->importClients();
            $this->importProjects();
            $this->importPeople();
            $this->importAssignments();
            $this->importTimeEntries();
            $this->importPayroll();
            $this->importSalesDocuments();
            $this->importExpenseDocuments();
            $this->importLegalObligations();
            $this->importBudgets();
            $this->importScenarios();
            $this->importCashMovements();
            $this->runComparativeQa();
        };

        if ($dryRun) {
            $runner();
        } else {
            DB::transaction($runner);
        }

        $this->report['summary'] = $this->summary();
        file_put_contents(storage_path('app/import_report.json'), json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $this->report;
    }

    private function importConfig(): void
    {
        $sheet = 'configuracion';
        $rows = $this->rawRows('01_Config');
        $settings = [];
        $companyName = 'Empresa Demo';
        $companyTaxId = null;

        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber < 5) {
                continue;
            }

            $label = trim((string) ($row[1] ?? ''));
            $value = $row[2] ?? null;

            if ($label === '') {
                continue;
            }

            $this->read($sheet);

            match ($this->norm($label)) {
                'nombreempresa' => $companyName = (string) $value,
                'rutempresa' => $companyTaxId = $this->blank($value) ? null : (string) $value,
                'moneda' => $settings['currency'] = [(string) $value, 'string', true],
                'fechainiciodelmodelo' => $settings['model_start_date'] = [$this->date($value), 'date', false],
                'mesdeanalisis' => $settings['analysis_month'] = [$this->date($value), 'date', false],
                'plazodepagoestandarclientesdias' => $settings['default_client_payment_term_days'] = [(string) (int) $this->number($value), 'integer', false],
                'margenminimoporproyecto' => $settings['margin_minimum'] = [(string) $this->percent($value), 'decimal', false],
                'umbralconcentracionporcliente' => $settings['client_concentration_threshold'] = [(string) $this->percent($value), 'decimal', false],
                'diasdealertaobligaciones' => $settings['obligation_alert_days'] = [(string) (int) $this->number($value), 'integer', false],
                'escenarioactivo' => $settings['active_scenario'] = [strtoupper($this->code((string) $value) ?: 'BASE'), 'string', false],
                default => null,
            };
        }

        $this->valid($sheet);

        if (! $this->dryRun) {
            $this->company = Company::query()->updateOrCreate(
                ['code' => 'CMP-001'],
                ['name' => $companyName ?: 'Empresa Demo', 'tax_id' => $companyTaxId, 'status' => 'active']
            );

            foreach ($settings as $key => [$value, $type, $public]) {
                CompanySetting::query()->updateOrCreate(
                    ['company_id' => $this->company->id, 'setting_key' => $key],
                    ['setting_value' => $value, 'setting_type' => $type, 'is_public' => $public]
                );
            }

            $opening = $this->settingValueFromConfig('Saldo inicial caja y bancos');
            CashAccount::query()->updateOrCreate(
                ['code' => 'BANK-001'],
                [
                    'company_id' => $this->company->id,
                    'name' => 'Cuenta Banco Local',
                    'institution' => 'Banco local',
                    'account_type' => 'Corriente',
                    'currency' => $settings['currency'][0] ?? 'CLP',
                    'opening_balance' => $this->money($opening),
                    'is_active' => true,
                ]
            );
        } else {
            $this->company = new Company(['id' => 0, 'code' => 'CMP-001', 'name' => $companyName]);
        }

        $this->inserted($sheet);
        $this->known['cash_accounts']['BANK-001'] = true;
    }

    private function importLegalParameters(): void
    {
        $sheet = 'parametros_legales';
        $rows = $this->rawRows('02_Parametros_Legales');

        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber < 6) {
                continue;
            }

            if (! $this->blank($row[1] ?? null) && ! $this->blank($row[3] ?? null)) {
                $this->legalParameter($sheet, 'COTIZACION_EMPLEADOR', 'Cotizacion empleador', $row[1], $row[2] ?? null, $row[3], '%', $row[7] ?? null, $row[6] ?? null);
            }

            if (! $this->blank($row[1] ?? null) && ! $this->blank($row[4] ?? null)) {
                $this->legalParameter($sheet, 'SIS_RATE', 'SIS', $row[1], $row[2] ?? null, $row[4], '%', $row[7] ?? null, $row[6] ?? null);
            }

            if (! $this->blank($row[8] ?? null) && ! $this->blank($row[10] ?? null)) {
                $parameterCode = $this->parameterCode((string) ($row[10] ?? ''));
                $value = $row[11] ?? null;

                if ($parameterCode && ! $this->blank($value)) {
                    $this->legalParameter($sheet, $parameterCode, (string) $row[10], $row[8], $row[9] ?? null, $value, (string) ($row[12] ?? ''), $row[13] ?? null, $row[14] ?? null);
                } elseif ($parameterCode) {
                    $this->warning($sheet, "Fila {$rowNumber}: parametro {$row[10]} sin valor, omitido.");
                    $this->skipped($sheet);
                }
            }
        }
    }

    private function importUf(): void
    {
        foreach ($this->table('02b_UF') as $index => $row) {
            $this->read('uf');
            $date = $this->date($this->get($row, ['Fecha']));
            $value = $this->money($this->get($row, ['Valor UF']));

            if (! $date || $value <= 0) {
                $this->skipped('uf');
                continue;
            }

            $this->valid('uf');

            if (! $this->dryRun) {
                UfValue::query()->updateOrCreate(
                    ['company_id' => $this->companyId(), 'value_date' => $date],
                    ['value' => $value, 'source' => $this->str($this->get($row, ['Fuente'])), 'notes' => $this->str($this->get($row, ['Observación', 'Observacion']))]
                );
            }

            $this->inserted('uf');
        }
    }

    private function importClients(): void
    {
        foreach ($this->table('04_Clientes') as $row) {
            $this->read('clientes');
            $code = $this->code($this->get($row, ['ID Cliente']));

            if (! $code) {
                $this->skipped('clientes');
                continue;
            }

            $this->valid('clientes');

            if (! $this->dryRun) {
                Client::query()->updateOrCreate(
                    ['company_id' => $this->companyId(), 'code' => $code],
                    [
                        'legal_name' => $this->str($this->get($row, ['Razón social', 'Razon social'])) ?: $code,
                        'tax_id' => $this->str($this->get($row, ['RUT'])),
                        'contact_name' => $this->str($this->get($row, ['Contacto'])),
                        'contact_email' => $this->str($this->get($row, ['Correo'])),
                        'payment_term_days' => (int) ($this->number($this->get($row, ['Plazo pago (días)', 'Plazo pago (dias)'])) ?: 30),
                        'status' => $this->status($this->get($row, ['Estado'])),
                        'notes' => $this->str($this->get($row, ['Observaciones'])),
                    ]
                );
            }

            $this->known['clients'][$code] = true;
            $this->inserted('clientes');
        }
    }

    private function importProjects(): void
    {
        foreach ($this->table('05_Proyectos') as $row) {
            $this->read('proyectos');
            $code = $this->code($this->get($row, ['ID Proyecto']));
            $clientCode = $this->code($this->get($row, ['ID Cliente']));

            if (! $code) {
                $this->skipped('proyectos');
                continue;
            }

            if (! $this->known('clients', $clientCode)) {
                $this->error('proyectos', "Proyecto {$code}: cliente {$clientCode} no existe.");
                $this->skipped('proyectos');
                continue;
            }

            $this->valid('proyectos');

            if (! $this->dryRun) {
                $client = Client::query()->where('company_id', $this->companyId())->where('code', $clientCode)->firstOrFail();
                Project::query()->updateOrCreate(
                    ['company_id' => $this->companyId(), 'code' => $code],
                    [
                        'client_id' => $client->id,
                        'name' => $this->str($this->get($row, ['Proyecto/Servicio'])) ?: $code,
                        'manager' => $this->str($this->get($row, ['Responsable'])),
                        'start_date' => $this->date($this->get($row, ['Fecha inicio'])),
                        'end_date' => $this->date($this->get($row, ['Fecha término', 'Fecha termino'])),
                        'contract_type' => $this->str($this->get($row, ['Tipo contrato'])),
                        'sale_net' => $this->money($this->get($row, ['Venta neta ($)'])),
                        'vat_rate' => $this->yes($this->get($row, ['Afecto IVA'])) ? 0.19 : 0,
                        'sale_total' => $this->money($this->get($row, ['Venta total ($)'])),
                        'payment_form' => $this->str($this->get($row, ['Forma de pago'])),
                        'installments' => (int) ($this->number($this->get($row, ['Nº cuotas/hitos', 'No cuotas/hitos'])) ?: 1),
                        'invoice_date' => $this->date($this->get($row, ['Fecha facturación', 'Fecha facturacion'])),
                        'projected_collection_date' => $this->date($this->get($row, ['Fecha cobro prevista'])),
                        'project_status' => $this->status($this->get($row, ['Estado proyecto'])),
                        'billing_status' => $this->status($this->get($row, ['Estado facturación', 'Estado facturacion'])),
                        'contracted_hourly_rate' => $this->money($this->get($row, ['Tarifa pactada ($/h)'])) ?: null,
                        'notes' => $this->str($this->get($row, ['Observaciones'])),
                    ]
                );
            }

            $this->known['projects'][$code] = true;
            $this->inserted('proyectos');
        }
    }

    private function importPeople(): void
    {
        foreach ($this->table('06_Personal') as $row) {
            $this->read('personal');
            $code = $this->code($this->get($row, ['ID Persona']));

            if (! $code) {
                $this->skipped('personal');
                continue;
            }

            $afpId = null;
            $afpName = $this->str($this->get($row, ['AFP']));

            if ($afpName && ! $this->dryRun) {
                $afp = Afp::query()->firstOrCreate(['code' => strtoupper($this->code($afpName) ?: $afpName)], ['name' => $afpName, 'is_active' => true]);
                $afpId = $afp->id;
            }

            $this->valid('personal');

            if (! $this->dryRun) {
                Person::query()->updateOrCreate(
                    ['company_id' => $this->companyId(), 'code' => $code],
                    [
                        'name' => $this->str($this->get($row, ['Nombre'])) ?: $code,
                        'identifier' => $this->str($this->get($row, ['RUT/Identificador'])),
                        'role' => $this->str($this->get($row, ['Cargo/Función', 'Cargo/Funcion'])),
                        'modality' => $this->str($this->get($row, ['Modalidad'])) ?: 'Pago por hora',
                        'contract_type' => $this->str($this->get($row, ['Tipo contrato'])),
                        'afp_id' => $afpId,
                        'health_system' => $this->str($this->get($row, ['Sistema salud'])),
                        'additional_health_plan' => $this->money($this->get($row, ['Plan salud adicional ($)'])) ?: null,
                        'monthly_value' => $this->money($this->get($row, ['Valor mensual ($)'])) ?: null,
                        'hourly_value' => $this->money($this->get($row, ['Valor hora contratado ($)'])) ?: null,
                        'monthly_hours' => (int) $this->number($this->get($row, ['Horas mensuales contrato'])) ?: null,
                        'payment_data' => $this->str($this->get($row, ['Datos de pago'])),
                        'status' => $this->status($this->get($row, ['Estado'])),
                        'start_date' => $this->date($this->get($row, ['Fecha inicio'])),
                        'end_date' => $this->date($this->get($row, ['Fecha término', 'Fecha termino'])),
                        'notes' => $this->str($this->get($row, ['Observaciones'])),
                    ]
                );
            }

            $this->known['people'][$code] = true;
            $this->inserted('personal');
        }
    }

    private function importAssignments(): void
    {
        foreach ($this->table('06b_Asignaciones') as $row) {
            $this->read('asignaciones');
            $code = $this->code($this->get($row, ['ID Asignación', 'ID Asignacion']));
            $personCode = $this->code($this->get($row, ['Persona']));
            $clientCode = $this->code($this->get($row, ['Cliente']));
            $projectCode = $this->code($this->get($row, ['Proyecto']));

            if (! $code) {
                $this->skipped('asignaciones');
                continue;
            }

            if (! $this->known('people', $personCode) || ! $this->known('clients', $clientCode) || ! $this->known('projects', $projectCode)) {
                $this->error('asignaciones', "Asignacion {$code}: referencia persona/cliente/proyecto inexistente.");
                $this->skipped('asignaciones');
                continue;
            }

            $this->valid('asignaciones');

            if (! $this->dryRun) {
                ProjectAssignment::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'company_id' => $this->companyId(),
                        'person_id' => Person::query()->where('company_id', $this->companyId())->where('code', $personCode)->value('id'),
                        'client_id' => Client::query()->where('company_id', $this->companyId())->where('code', $clientCode)->value('id'),
                        'project_id' => Project::query()->where('company_id', $this->companyId())->where('code', $projectCode)->value('id'),
                        'hourly_value' => $this->money($this->get($row, ['Valor hora asignación ($)', 'Valor hora asignacion ($)'])) ?: null,
                        'project_value' => $this->money($this->get($row, ['Valor proyecto ($)'])) ?: null,
                        'monthly_hours' => (int) $this->number($this->get($row, ['Horas mensuales asignadas'])) ?: null,
                        'cost_center' => $this->str($this->get($row, ['Centro costo'])),
                        'start_date' => $this->date($this->get($row, ['Fecha inicio'])),
                        'end_date' => $this->date($this->get($row, ['Fecha término', 'Fecha termino'])),
                        'status' => $this->status($this->get($row, ['Estado'])),
                        'notes' => $this->str($this->get($row, ['Control asignación', 'Control asignacion'])),
                    ]
                );
            }

            $this->known['assignments'][$code] = true;
            $this->inserted('asignaciones');
        }
    }

    private function importTimeEntries(): void
    {
        foreach ($this->table('07_Horas') as $row) {
            $this->read('horas');
            $code = $this->code($this->get($row, ['ID Registro']));
            $personCode = $this->code($this->get($row, ['ID Persona']));
            $clientCode = $this->code($this->get($row, ['ID Cliente']));
            $projectCode = $this->code($this->get($row, ['ID Proyecto lógico', 'ID Proyecto logico', 'ID Proyecto']));

            if (! $code) {
                $this->skipped('horas');
                continue;
            }

            if (! $this->known('people', $personCode) || ! $this->known('clients', $clientCode) || ! $this->known('projects', $projectCode)) {
                $this->error('horas', "Hora {$code}: referencia persona/cliente/proyecto inexistente.");
                $this->skipped('horas');
                continue;
            }

            $this->valid('horas');

            if (! $this->dryRun) {
                TimeEntry::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'company_id' => $this->companyId(),
                        'person_id' => Person::query()->where('company_id', $this->companyId())->where('code', $personCode)->value('id'),
                        'client_id' => Client::query()->where('company_id', $this->companyId())->where('code', $clientCode)->value('id'),
                        'project_id' => Project::query()->where('company_id', $this->companyId())->where('code', $projectCode)->value('id'),
                        'assignment_id' => $this->assignmentIdFor($personCode, $projectCode),
                        'entry_date' => $this->date($this->get($row, ['Fecha'])) ?: now()->toDateString(),
                        'activity' => $this->str($this->get($row, ['Actividad'])) ?: 'Actividad importada',
                        'hours_worked' => $this->number($this->get($row, ['Horas trabajadas'])),
                        'hours_approved' => $this->number($this->get($row, ['Horas aprobadas'])),
                        'hourly_value' => $this->money($this->get($row, ['Valor hora ($)'])) ?: null,
                        'calculated_amount' => $this->money($this->get($row, ['Monto calculado ($)'])),
                        'approval_status' => $this->status($this->get($row, ['Estado aprobación', 'Estado aprobacion'])),
                        'payment_status' => $this->status($this->get($row, ['Estado pago'])),
                        'pay_period' => $this->date($this->get($row, ['Periodo pago'])),
                        'cost_center' => $this->str($this->get($row, ['Centro costo'])),
                        'notes' => $this->str($this->get($row, ['Observaciones'])),
                    ]
                );
            }

            $this->inserted('horas');
        }
    }

    private function importPayroll(): void
    {
        foreach ($this->table('08_Remuneraciones') as $row) {
            $this->read('remuneraciones');
            $code = $this->code($this->get($row, ['ID Pago']));
            $personCode = $this->code($this->get($row, ['ID Persona']));
            $projectCode = $this->code($this->get($row, ['ID Proyecto lógico', 'ID Proyecto logico', 'ID Proyecto']));
            $period = $this->date($this->get($row, ['Periodo']));

            if (! $code || ! $period) {
                $this->skipped('remuneraciones');
                continue;
            }

            if (! $this->known('people', $personCode)) {
                $this->error('remuneraciones', "Pago {$code}: persona {$personCode} no existe.");
                $this->skipped('remuneraciones');
                continue;
            }

            $this->valid('remuneraciones');

            if (! $this->dryRun) {
                $person = Person::query()->where('company_id', $this->companyId())->where('code', $personCode)->firstOrFail();
                $projectId = $projectCode ? Project::query()->where('company_id', $this->companyId())->where('code', $projectCode)->value('id') : null;
                $calculated = $this->payroll->calculate($person, $period, [
                    'hours_approved' => $this->number($this->get($row, ['Horas aprobadas'])),
                    'hourly_value' => $this->money($this->get($row, ['Valor hora ($)'])),
                    'monthly_value' => $this->money($this->get($row, ['Valor mensual ($)'])),
                    'project_value' => $this->money($this->get($row, ['Valor proyecto ($)'])),
                ]);

                $record = PayrollRecord::query()->updateOrCreate(
                    ['code' => $code],
                    array_merge($calculated, [
                        'company_id' => $this->companyId(),
                        'person_id' => $person->id,
                        'project_id' => $projectId,
                        'period_date' => $period,
                        'payment_date' => $this->date($this->get($row, ['Fecha prevista pago', 'Fecha real pago'])),
                        'monthly_value' => $this->money($this->get($row, ['Valor mensual ($)'])) ?: null,
                        'hourly_value' => $this->money($this->get($row, ['Valor hora ($)'])) ?: null,
                        'project_value' => $this->money($this->get($row, ['Valor proyecto ($)'])) ?: null,
                        'bonuses' => $this->money($this->get($row, ['Bonos imponibles ($)'])),
                        'non_taxable_allowances' => $this->money($this->get($row, ['Asignaciones no imponibles ($)'])),
                        'status' => $this->status($this->get($row, ['Estado pago'])) ?: 'Pendiente',
                        'notes' => $this->str($this->get($row, ['Observaciones'])),
                    ])
                );

                $this->payroll->refreshStatus($record);
            }

            $this->known['payroll_records'][$code] = true;
            $this->inserted('remuneraciones');
        }
    }

    private function importSalesDocuments(): void
    {
        foreach ($this->table('09_Ingresos') as $row) {
            $this->read('ingresos');
            $code = $this->code($this->get($row, ['ID Ingreso']));
            $clientCode = $this->code($this->get($row, ['ID Cliente']));
            $projectCode = $this->code($this->get($row, ['ID Proyecto lógico', 'ID Proyecto logico', 'ID Proyecto']));

            if (! $code) {
                $this->skipped('ingresos');
                continue;
            }

            if (! $this->known('clients', $clientCode)) {
                $this->error('ingresos', "Ingreso {$code}: cliente {$clientCode} no existe.");
                $this->skipped('ingresos');
                continue;
            }

            $this->valid('ingresos');

            if (! $this->dryRun) {
                $client = Client::query()->where('company_id', $this->companyId())->where('code', $clientCode)->firstOrFail();
                $projectId = $projectCode ? Project::query()->where('company_id', $this->companyId())->where('code', $projectCode)->value('id') : null;
                $net = $this->money($this->get($row, ['Valor neto ($)']));
                $amounts = $this->receivables->amountsWithVat($this->companyId(), $net, $this->date($this->get($row, ['Fecha emisión', 'Fecha emision', 'Fecha registro'])) ?: now());

                $document = SalesDocument::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'company_id' => $this->companyId(),
                        'client_id' => $client->id,
                        'project_id' => $projectId,
                        'document_type' => $this->str($this->get($row, ['Tipo documento'])) ?: 'Factura',
                        'document_number' => $this->str($this->get($row, ['Nº documento', 'No documento'])),
                        'issue_date' => $this->date($this->get($row, ['Fecha emisión', 'Fecha emision', 'Fecha registro'])),
                        'due_date' => $this->date($this->get($row, ['Fecha vencimiento'])),
                        'projected_collection_date' => $this->date($this->get($row, ['Fecha cobro proyectada'])),
                        'scenario_collection_date' => $this->date($this->get($row, ['Fecha cobro escenario'])),
                        'actual_collection_date' => $this->date($this->get($row, ['Fecha cobro real'])),
                        'payment_probability' => $this->nullablePercent($this->get($row, ['Probabilidad cobro'])),
                        'net_amount' => $amounts['net_amount'],
                        'vat_amount' => $amounts['vat_amount'],
                        'gross_amount' => $amounts['gross_amount'],
                        'collected_amount' => 0,
                        'status' => $this->status($this->get($row, ['Estado cobro'])) ?: 'Pendiente',
                        'is_voided' => $this->yes($this->get($row, ['Anulado'])),
                        'notes' => trim(($this->str($this->get($row, ['Descripción', 'Descripcion'])) ?: '').' '.($this->str($this->get($row, ['Observaciones'])) ?: '')) ?: null,
                    ]
                );

                $this->receivables->refreshDocumentState($document);
            }

            $this->known['sales_documents'][$code] = true;
            $this->inserted('ingresos');
        }
    }

    private function importExpenseDocuments(): void
    {
        foreach ($this->table('10_Egresos') as $row) {
            $this->read('egresos');
            $code = $this->code($this->get($row, ['ID Egreso']));

            if (! $code) {
                $this->skipped('egresos');
                continue;
            }

            $clientCode = $this->code($this->get($row, ['ID Cliente']));
            $projectCode = $this->code($this->get($row, ['ID Proyecto lógico', 'ID Proyecto logico', 'ID Proyecto']));

            $this->valid('egresos');

            if (! $this->dryRun) {
                $issueDate = $this->date($this->get($row, ['Fecha registro/documento'])) ?: now()->toDateString();
                $net = $this->money($this->get($row, ['Valor neto ($)']));
                $amounts = $this->payables->amountsWithVat($this->companyId(), $net, $issueDate, $this->yes($this->get($row, ['IVA recuperable'])));
                $document = ExpenseDocument::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'company_id' => $this->companyId(),
                        'vendor_name' => $this->str($this->get($row, ['Proveedor/Beneficiario'])),
                        'client_id' => $clientCode ? Client::query()->where('company_id', $this->companyId())->where('code', $clientCode)->value('id') : null,
                        'project_id' => $projectCode ? Project::query()->where('company_id', $this->companyId())->where('code', $projectCode)->value('id') : null,
                        'category' => $this->str($this->get($row, ['Categoría', 'Categoria'])),
                        'subcategory' => $this->str($this->get($row, ['Subcategoría', 'Subcategoria'])),
                        'expense_type' => $this->str($this->get($row, ['Tipo gasto'])),
                        'document_type' => $this->str($this->get($row, ['Tipo documento'])),
                        'document_number' => $this->str($this->get($row, ['Nº documento', 'No documento'])),
                        'issue_date' => $issueDate,
                        'due_date' => $this->date($this->get($row, ['Fecha vencimiento'])),
                        'projected_payment_date' => $this->date($this->get($row, ['Fecha pago escenario'])),
                        'actual_payment_date' => $this->date($this->get($row, ['Fecha real pago'])),
                        'net_amount' => $amounts['net_amount'],
                        'vat_amount' => $amounts['vat_amount'],
                        'recoverable_vat_amount' => $amounts['recoverable_vat_amount'],
                        'gross_amount' => $amounts['gross_amount'],
                        'paid_amount' => 0,
                        'payment_status' => $this->status($this->get($row, ['Estado'])) ?: 'Pendiente',
                        'tax_deductible' => true,
                        'deductible_vat' => $this->yes($this->get($row, ['IVA recuperable'])),
                        'notes' => trim(($this->str($this->get($row, ['Descripción', 'Descripcion'])) ?: '').' '.($this->str($this->get($row, ['Observaciones'])) ?: '')) ?: null,
                    ]
                );

                $this->payables->refreshDocumentState($document);
            }

            $this->known['expense_documents'][$code] = true;
            $this->inserted('egresos');
        }
    }

    private function importLegalObligations(): void
    {
        foreach ($this->table('11_Obligaciones') as $row) {
            $this->read('obligaciones');
            $code = $this->code($this->get($row, ['ID Obligación', 'ID Obligacion']));
            $period = $this->date($this->get($row, ['Periodo']));
            $type = $this->str($this->get($row, ['Tipo obligación', 'Tipo obligacion']));

            if (! $code || ! $period || ! $type) {
                $this->skipped('obligaciones');
                continue;
            }

            $this->valid('obligaciones');

            if (! $this->dryRun) {
                LegalObligation::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'company_id' => $this->companyId(),
                        'obligation_type' => strtoupper(str_replace(' ', '_', $type)),
                        'period_date' => $period,
                        'due_date' => $this->date($this->get($row, ['Fecha vencimiento'])),
                        'base_detail' => $this->str($this->get($row, ['Base / detalle'])),
                        'estimated_amount' => $this->money($this->get($row, ['Monto estimado ($)'])),
                        'payment_date' => $this->date($this->get($row, ['Fecha real pago'])),
                        'paid_amount' => $this->money($this->get($row, ['Monto pagado ($)'])),
                        'pending_amount' => $this->money($this->get($row, ['Saldo pendiente ($)'])),
                        'status' => $this->status($this->get($row, ['Estado'])) ?: 'Pendiente',
                        'source_calculation' => $this->str($this->get($row, ['Fuente de cálculo', 'Fuente de calculo'])),
                        'notes' => $this->str($this->get($row, ['Observaciones'])),
                        'vat_carryforward_amount' => $this->money($this->get($row, ['Remanente IVA arrastrado ($)'])),
                    ]
                );
            }

            $this->known['legal_obligations'][$code] = true;
            $this->inserted('obligaciones');
        }
    }

    private function importBudgets(): void
    {
        foreach ($this->table('12_Presupuesto') as $row) {
            $this->read('presupuesto');
            $period = $this->date($this->get($row, ['Periodo']));
            $projectCode = $this->code($this->get($row, ['ID Proyecto lógico', 'ID Proyecto logico', 'ID Proyecto']));

            if (! $period) {
                $this->skipped('presupuesto');
                continue;
            }

            $this->valid('presupuesto');

            if (! $this->dryRun) {
                $projectId = $projectCode ? Project::query()->where('company_id', $this->companyId())->where('code', $projectCode)->value('id') : null;
                Budget::query()->updateOrCreate(
                    ['company_id' => $this->companyId(), 'period_date' => $period, 'project_id' => $projectId],
                    [
                        'scenario_id' => null,
                        'revenue_budget' => $this->money($this->get($row, ['Ingreso presupuestado ($)'])),
                        'personnel_budget' => $this->money($this->get($row, ['Personal presupuestado ($)'])),
                        'other_direct_budget' => $this->money($this->get($row, ['Otros egresos presupuestados ($)'])),
                        'legal_budget' => $this->money($this->get($row, ['Legal presupuestado ($)'])),
                        'other_indirect_budget' => 0,
                        'notes' => $this->str($this->get($row, ['Comentario desviación', 'Comentario desviacion'])),
                    ]
                );
            }

            $this->inserted('presupuesto');
        }
    }

    private function importScenarios(): void
    {
        foreach ($this->table('16_Escenarios') as $row) {
            $this->read('escenarios');
            $code = strtoupper($this->code($this->get($row, ['Escenario'])));

            if (! $code) {
                $this->skipped('escenarios');
                continue;
            }

            if (! in_array($code, ['CONSERVADOR', 'BASE', 'OPTIMISTA'], true)) {
                $this->skipped('escenarios');
                continue;
            }

            $this->valid('escenarios');

            if (! $this->dryRun) {
                Scenario::query()->updateOrCreate(
                    ['company_id' => $this->companyId(), 'code' => $code],
                    [
                        'name' => ucfirst(strtolower($code)),
                        'sales_factor' => $this->number($this->get($row, ['Factor ventas'])) ?: 1,
                        'cost_factor' => $this->number($this->get($row, ['Factor costos'])) ?: 1,
                        'collection_delay_days' => (int) $this->number($this->get($row, ['Retraso cobros (días)', 'Retraso cobros (dias)'])),
                        'new_hires_monthly' => $this->money($this->get($row, ['Nuevas contrataciones ($/mes)'])),
                        'affected_client_id' => null,
                        'client_loss_flag' => $this->yes($this->get($row, ['Pérdida ventas cliente', 'Perdida ventas cliente'])),
                        'tariff_variation' => $this->percent($this->get($row, ['Variación tarifas', 'Variacion tarifas'])),
                        'description' => $this->str($this->get($row, ['Descripción', 'Descripcion'])),
                        'is_active' => $code === strtoupper((string) CompanySetting::query()->forCompany($this->companyId())->where('setting_key', 'active_scenario')->value('setting_value')),
                    ]
                );
            }

            $this->known['scenarios'][$code] = true;
            $this->inserted('escenarios');
        }
    }

    private function importCashMovements(): void
    {
        foreach ($this->table('19_Movimientos_Caja') as $row) {
            $this->read('movimientos');
            $code = $this->code($this->get($row, ['ID Movimiento']));

            if (! $code) {
                $this->skipped('movimientos');
                continue;
            }

            $this->valid('movimientos');

            if (! $this->dryRun) {
                if (CashMovement::query()->where('company_id', $this->companyId())->where('code', $code)->exists()) {
                    $this->warning('movimientos', "Movimiento {$code}: ya existia, omitido para no duplicar caja.");
                    $this->skipped('movimientos');
                    continue;
                }

                $accountCode = $this->code($this->get($row, ['Cuenta / Banco'])) ?: 'BANK-001';
                $account = CashAccount::query()->firstOrCreate(
                    ['code' => $accountCode],
                    ['company_id' => $this->companyId(), 'name' => $accountCode, 'currency' => 'CLP', 'opening_balance' => 0, 'is_active' => true]
                );

                $sourceType = $this->sourceType($this->get($row, ['Tipo Documento Origen']));

                try {
                    $this->cashMovements->create([
                        'company_id' => $this->companyId(),
                        'code' => $code,
                        'movement_type' => $this->str($this->get($row, ['Tipo Movimiento'])) ?: ($this->money($this->get($row, ['Ingreso'])) > 0 ? 'Ingreso' : 'Egreso'),
                        'source_document_type' => $sourceType,
                        'source_document_code' => $this->code($this->get($row, ['ID Documento Origen'])),
                        'counterparty_name' => $this->str($this->get($row, ['Cliente / Proveedor / Trabajador'])),
                        'project_id' => $this->projectId($this->code($this->get($row, ['ID Proyecto']))),
                        'movement_date' => $this->date($this->get($row, ['Fecha Movimiento'])) ?: now()->toDateString(),
                        'income' => $this->money($this->get($row, ['Ingreso'])),
                        'expense' => $this->money($this->get($row, ['Egreso'])),
                        'payment_method' => $this->str($this->get($row, ['Medio de Pago'])),
                        'cash_account_id' => $account->id,
                        'reference' => $this->str($this->get($row, ['Referencia'])),
                        'notes' => $this->str($this->get($row, ['Observación', 'Observacion'])),
                        'status' => $this->movementStatus($this->get($row, ['Estado'])),
                    ]);
                } catch (Throwable $exception) {
                    $this->error('movimientos', "Movimiento {$code}: ".$exception->getMessage());
                    $this->skipped('movimientos');
                    continue;
                }
            }

            $this->inserted('movimientos');
        }
    }

    private function runComparativeQa(): void
    {
        if ($this->dryRun) {
            $this->report['qa'] = [
                'modo' => 'dry-run',
                'comparacion' => 'Se valida lectura y relaciones internas sin consultar ni modificar la base de datos.',
                'warnings' => [
                    'No se importan flujo, rentabilidad ni dashboard porque son resultados derivados recalculados por servicios Laravel.',
                    'Si 19_Movimientos_Caja esta vacia, la caja real queda sin movimientos importados por diseno.',
                ],
            ];

            return;
        }

        $companyId = $this->companyId();
        $this->report['qa'] = [
            'ventas_importadas' => SalesDocument::query()->forCompany($companyId)->count(),
            'iva_documentos' => (float) SalesDocument::query()->forCompany($companyId)->sum('vat_amount'),
            'honorarios_retencion' => (float) PayrollRecord::query()->forCompany($companyId)->sum('employee_retention'),
            'remuneraciones' => (float) PayrollRecord::query()->forCompany($companyId)->sum('net_pay'),
            'obligaciones' => (float) LegalObligation::query()->forCompany($companyId)->sum('estimated_amount'),
            'movimientos' => [
                'count' => DB::table('cash_movements')->where('company_id', $companyId)->count(),
                'ingresos' => (float) DB::table('cash_movements')->where('company_id', $companyId)->sum('income'),
                'egresos' => (float) DB::table('cash_movements')->where('company_id', $companyId)->sum('expense'),
            ],
            'cxc' => app(\App\Services\ReceivablesService::class)->accountsReceivable($companyId, now()),
            'cxp' => app(\App\Services\PayablesService::class)->accountsPayable($companyId, now()),
            'presupuestos' => Budget::query()->forCompany($companyId)->count(),
            'rentabilidad_proyectos' => Project::query()->forCompany($companyId)->count(),
            'warnings' => [
                'No se importan flujo, rentabilidad ni dashboard porque son resultados derivados recalculados por servicios Laravel.',
                'Si 19_Movimientos_Caja esta vacia, la caja real queda sin movimientos importados por diseno.',
            ],
        ];
    }

    private function legalParameter(string $sheet, string $code, string $name, mixed $from, mixed $to, mixed $value, string $unit, mixed $source, mixed $notes): void
    {
        $this->read($sheet);
        $validFrom = $this->date($from);

        if (! $validFrom) {
            $this->skipped($sheet);
            return;
        }

        $this->valid($sheet);

        if (! $this->dryRun) {
            LegalParameter::query()->updateOrCreate(
                ['company_id' => $this->companyId(), 'parameter_code' => $code, 'valid_from' => $validFrom],
                [
                    'parameter_name' => $name,
                    'valid_to' => $this->date($to),
                    'value' => $this->percent($value),
                    'unit' => $unit ?: '%',
                    'source' => $this->str($source),
                    'notes' => $this->str($notes),
                ]
            );
        }

        $this->inserted($sheet);
    }

    private function rawRows(string $sheet): array
    {
        try {
            return $this->reader->rows($sheet);
        } catch (Throwable $exception) {
            $this->warning($this->reportKey($sheet), "Hoja {$sheet} no encontrada: ".$exception->getMessage());

            return [];
        }
    }

    private function table(string $sheet): array
    {
        try {
            return $this->reader->tableRows($sheet);
        } catch (Throwable $exception) {
            $this->warning($this->reportKey($sheet), "Hoja {$sheet} no encontrada: ".$exception->getMessage());

            return [];
        }
    }

    private function get(array $row, array $headers): mixed
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[$this->norm((string) $key)] = $value;
        }

        foreach ($headers as $header) {
            $key = $this->norm($header);
            if (array_key_exists($key, $normalized)) {
                return $normalized[$key];
            }
        }

        return null;
    }

    private function settingValueFromConfig(string $label): mixed
    {
        foreach ($this->rawRows('01_Config') as $rowNumber => $row) {
            if ($rowNumber >= 5 && $this->norm((string) ($row[1] ?? '')) === $this->norm($label)) {
                return $row[2] ?? null;
            }
        }

        return null;
    }

    private function loadKnownFromDatabase(): void
    {
        if ($this->dryRun) {
            return;
        }

        if (! $this->company || ! $this->company->exists) {
            return;
        }

        $companyId = $this->companyId();
        $this->known['clients'] += Client::query()->forCompany($companyId)->pluck('code')->mapWithKeys(fn ($code) => [$code => true])->all();
        $this->known['projects'] += Project::query()->forCompany($companyId)->pluck('code')->mapWithKeys(fn ($code) => [$code => true])->all();
        $this->known['people'] += Person::query()->forCompany($companyId)->pluck('code')->mapWithKeys(fn ($code) => [$code => true])->all();
        $this->known['sales_documents'] += SalesDocument::query()->forCompany($companyId)->pluck('code')->mapWithKeys(fn ($code) => [$code => true])->all();
        $this->known['expense_documents'] += ExpenseDocument::query()->forCompany($companyId)->pluck('code')->mapWithKeys(fn ($code) => [$code => true])->all();
        $this->known['payroll_records'] += PayrollRecord::query()->forCompany($companyId)->pluck('code')->mapWithKeys(fn ($code) => [$code => true])->all();
        $this->known['legal_obligations'] += LegalObligation::query()->forCompany($companyId)->pluck('code')->mapWithKeys(fn ($code) => [$code => true])->all();
        $this->known['cash_accounts'] += CashAccount::query()->forCompany($companyId)->pluck('code')->mapWithKeys(fn ($code) => [$code => true])->all();
    }

    private function known(string $bucket, ?string $code): bool
    {
        return $code !== null && $code !== '' && isset($this->known[$bucket][$code]);
    }

    private function companyId(): int
    {
        if ($this->dryRun) {
            return (int) ($this->company?->id ?: 0);
        }

        if ($this->company && $this->company->id) {
            return (int) $this->company->id;
        }

        $this->company = Company::query()->firstOrCreate(['code' => 'CMP-001'], ['name' => 'Empresa Demo', 'status' => 'active']);

        return (int) $this->company->id;
    }

    private function projectId(?string $code): ?int
    {
        if (! $code) {
            return null;
        }

        return Project::query()->where('company_id', $this->companyId())->where('code', $code)->value('id');
    }

    private function assignmentIdFor(string $personCode, string $projectCode): ?int
    {
        $personId = Person::query()->where('company_id', $this->companyId())->where('code', $personCode)->value('id');
        $projectId = Project::query()->where('company_id', $this->companyId())->where('code', $projectCode)->value('id');

        return ProjectAssignment::query()->where('company_id', $this->companyId())->where('person_id', $personId)->where('project_id', $projectId)->value('id');
    }

    private function sourceType(mixed $value): ?string
    {
        return match ($this->norm((string) $value)) {
            'ingreso' => 'sales_document',
            'egreso' => 'expense_document',
            'remuneracion' => 'payroll_record',
            'obligacion' => 'legal_obligation',
            default => null,
        };
    }

    private function parameterCode(string $name): ?string
    {
        return match ($this->norm($name)) {
            'retencionhonorarios' => 'RETENCION_HONORARIOS',
            'topeimponibleprevisionaluf' => 'TOPE_IMPONIBLE_PREVISIONAL_UF',
            'topeafcuf' => 'TOPE_AFC_UF',
            'cotizacionempleador' => 'COTIZACION_EMPLEADOR',
            'siscotizacionempleador' => 'SIS_RATE',
            default => null,
        };
    }

    private function reportKey(string $sheet): string
    {
        return match ($sheet) {
            '01_Config' => 'configuracion',
            '02_Parametros_Legales' => 'parametros_legales',
            '02b_UF' => 'uf',
            '04_Clientes' => 'clientes',
            '05_Proyectos' => 'proyectos',
            '06_Personal' => 'personal',
            '06b_Asignaciones' => 'asignaciones',
            '07_Horas' => 'horas',
            '08_Remuneraciones' => 'remuneraciones',
            '09_Ingresos' => 'ingresos',
            '10_Egresos' => 'egresos',
            '11_Obligaciones' => 'obligaciones',
            '12_Presupuesto' => 'presupuesto',
            '16_Escenarios' => 'escenarios',
            '19_Movimientos_Caja' => 'movimientos',
            default => $sheet,
        };
    }

    private function seedReportBuckets(array $keys): void
    {
        foreach ($keys as $key) {
            $this->report['sheets'][$key] = [
                'leidos' => 0,
                'validos' => 0,
                'insertados' => 0,
                'omitidos' => 0,
                'warnings' => [],
                'errores' => [],
            ];
        }
    }

    private function read(string $key): void
    {
        $this->report['sheets'][$key]['leidos']++;
    }

    private function valid(string $key): void
    {
        $this->report['sheets'][$key]['validos']++;
    }

    private function inserted(string $key): void
    {
        $this->report['sheets'][$key]['insertados']++;
    }

    private function skipped(string $key): void
    {
        $this->report['sheets'][$key]['omitidos']++;
    }

    private function warning(string $key, string $message): void
    {
        $this->report['sheets'][$key]['warnings'][] = $message;
    }

    private function error(string $key, string $message): void
    {
        $this->report['sheets'][$key]['errores'][] = $message;
    }

    private function summary(): array
    {
        return collect($this->report['sheets'])->reduce(function (array $carry, array $bucket): array {
            foreach (['leidos', 'validos', 'insertados', 'omitidos'] as $key) {
                $carry[$key] += $bucket[$key];
            }

            $carry['warnings'] += count($bucket['warnings']);
            $carry['errores'] += count($bucket['errores']);

            return $carry;
        }, ['leidos' => 0, 'validos' => 0, 'insertados' => 0, 'omitidos' => 0, 'warnings' => 0, 'errores' => 0]);
    }

    private function code(mixed $value): ?string
    {
        $value = $this->str($value);

        if (! $value) {
            return null;
        }

        return trim(explode(' - ', $value, 2)[0]);
    }

    private function str(mixed $value): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Si' : 'No';
        }

        return trim((string) $value);
    }

    private function status(mixed $value): string
    {
        $value = $this->str($value);

        if (! $value) {
            return 'Pendiente';
        }

        return ucfirst(strtolower($value));
    }

    private function movementStatus(mixed $value): string
    {
        return match ($this->norm((string) $value)) {
            'anulado', 'voided' => 'voided',
            default => 'posted',
        };
    }

    private function yes(mixed $value): bool
    {
        return in_array($this->norm((string) $value), ['si', 'sí', 'yes', 'true', '1', 'afecto', 'activo'], true);
    }

    private function number(mixed $value): float
    {
        if ($this->blank($value)) {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $clean = str_replace(['$', ' ', '%'], '', (string) $value);
        $clean = str_replace('.', '', $clean);
        $clean = str_replace(',', '.', $clean);

        return is_numeric($clean) ? (float) $clean : 0.0;
    }

    private function money(mixed $value): float
    {
        return round($this->number($value), 2);
    }

    private function percent(mixed $value): float
    {
        $number = $this->number($value);

        return $number > 1 ? round($number / 100, 6) : round($number, 6);
    }

    private function nullablePercent(mixed $value): ?float
    {
        return $this->blank($value) ? null : $this->percent($value);
    }

    private function date(mixed $value): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 60000) {
            return Carbon::createFromTimestampUTC((int) round(((float) $value - 25569) * 86400))->toDateString();
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function norm(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $value) ?? '');
    }

    private function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
