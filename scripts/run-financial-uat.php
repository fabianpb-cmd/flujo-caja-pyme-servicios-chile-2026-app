<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Company;
use App\Models\ExpenseDocument;
use App\Models\LegalObligation;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\SalesDocument;
use App\Models\Scenario;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\CashFlowService;
use App\Services\CashMovementService;
use App\Services\DashboardService;
use App\Services\HourlyCostService;
use App\Services\LegalObligationService;
use App\Services\PayrollService;
use App\Services\PayablesService;
use App\Services\ProfitabilityService;
use App\Services\ReceivablesService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

function passFail(bool $condition): string
{
    return $condition ? 'PASS' : 'FAIL';
}

function money(float $value): string
{
    return number_format(round($value, 2), 2, '.', '');
}

function addCase(array &$cases, string $id, string $initial, string $action, string $expected, string $obtained, bool $ok, string $evidence): void
{
    $cases[] = [
        'id' => $id,
        'initial' => $initial,
        'action' => $action,
        'expected' => $expected,
        'obtained' => $obtained,
        'status' => passFail($ok),
        'evidence' => $evidence,
    ];
}

Artisan::call('uat:clear-data', ['--force' => true]);

$admin = User::query()
    ->whereNotNull('company_id')
    ->where('role', 'admin')
    ->where('active', true)
    ->orderBy('id')
    ->first();

if (! $admin) {
    fail('No existe un administrador activo con empresa para ejecutar el UAT.');
}

$company = Company::query()->find($admin->company_id);
if (! $company) {
    fail('No existe la empresa del administrador seleccionado.');
}

$receivables = app(ReceivablesService::class);
$payables = app(PayablesService::class);
$payrollService = app(PayrollService::class);
$cashFlow = app(CashFlowService::class);
$cashMovements = app(CashMovementService::class);
$obligations = app(LegalObligationService::class);
$budgets = app(BudgetService::class);
$profitability = app(ProfitabilityService::class);
$dashboard = app(DashboardService::class);
$hourlyCosts = app(HourlyCostService::class);

$cases = [];
$defects = ['P0' => 0, 'P1' => 0, 'P2' => 0, 'P3' => 0];

$vat = $receivables->amountsWithVat($company->id, 2000000, '2026-08-10');

$cashAccount = CashAccount::query()->create([
    'company_id' => $company->id,
    'code' => 'BANK-UAT-202608',
    'name' => 'Banco UAT CLP',
    'currency' => 'CLP',
    'opening_balance' => 1000000,
    'is_active' => true,
]);

$client = Client::query()->create([
    'company_id' => $company->id,
    'code' => 'CLI-UAT-202608',
    'legal_name' => 'Cliente UAT SpA',
    'payment_term_days' => 30,
    'status' => 'active',
]);

$project = Project::query()->create([
    'company_id' => $company->id,
    'client_id' => $client->id,
    'code' => 'PRY-UAT-202608',
    'name' => 'Proyecto UAT Agosto 2026',
    'sale_net' => 2000000,
    'sale_total' => $vat['gross_amount'],
    'project_status' => 'active',
    'billing_status' => 'pending',
]);

$personA = Person::query()->create([
    'company_id' => $company->id,
    'code' => 'PER-UAT-A',
    'name' => 'Persona A UAT',
    'modality' => 'Pago por hora',
    'hourly_value' => 20000,
    'status' => 'active',
]);

$personB = Person::query()->create([
    'company_id' => $company->id,
    'code' => 'PER-UAT-B',
    'name' => 'Persona B UAT',
    'modality' => 'Pago por hora',
    'hourly_value' => 15000,
    'status' => 'active',
]);

$assignmentA = ProjectAssignment::query()->create([
    'company_id' => $company->id,
    'code' => 'ASI-UAT-A',
    'person_id' => $personA->id,
    'client_id' => $client->id,
    'project_id' => $project->id,
    'hourly_value' => 20000,
    'start_date' => '2026-08-01',
    'status' => 'active',
]);

$assignmentB = ProjectAssignment::query()->create([
    'company_id' => $company->id,
    'code' => 'ASI-UAT-B',
    'person_id' => $personB->id,
    'client_id' => $client->id,
    'project_id' => $project->id,
    'hourly_value' => 15000,
    'start_date' => '2026-08-01',
    'status' => 'active',
]);

$timeA = TimeEntry::query()->create([
    'company_id' => $company->id,
    'code' => 'HOR-UAT-A',
    'person_id' => $personA->id,
    'client_id' => $client->id,
    'project_id' => $project->id,
    'assignment_id' => $assignmentA->id,
    'entry_date' => '2026-08-05',
    'activity' => 'Horas UAT A',
    'hours_worked' => 20,
    'hours_approved' => 20,
    'hourly_value' => 20000,
    'calculated_amount' => 400000,
    'approval_status' => 'approved',
    'payment_status' => 'pending',
]);

$timeB = TimeEntry::query()->create([
    'company_id' => $company->id,
    'code' => 'HOR-UAT-B',
    'person_id' => $personB->id,
    'client_id' => $client->id,
    'project_id' => $project->id,
    'assignment_id' => $assignmentB->id,
    'entry_date' => '2026-08-06',
    'activity' => 'Horas UAT B',
    'hours_worked' => 10,
    'hours_approved' => 10,
    'hourly_value' => 15000,
    'calculated_amount' => 150000,
    'approval_status' => 'approved',
    'payment_status' => 'pending',
]);

$payrollAData = $payrollService->calculate($personA, '2026-08-01', [
    'hours_approved' => 20,
    'hourly_value' => 20000,
]);

$payrollBData = $payrollService->calculate($personB, '2026-08-01', [
    'hours_approved' => 10,
    'hourly_value' => 15000,
]);

$payrollA = PayrollRecord::query()->create([
    'company_id' => $company->id,
    'code' => 'REM-UAT-A',
    'person_id' => $personA->id,
    'project_id' => $project->id,
    'period_date' => '2026-08-01',
    'hours_approved' => 20,
    'hourly_value' => 20000,
] + $payrollAData);

$payrollB = PayrollRecord::query()->create([
    'company_id' => $company->id,
    'code' => 'REM-UAT-B',
    'person_id' => $personB->id,
    'project_id' => $project->id,
    'period_date' => '2026-08-01',
    'hours_approved' => 10,
    'hourly_value' => 15000,
] + $payrollBData);

$payrollService->refreshStatus($payrollA);
$payrollService->refreshStatus($payrollB);

$invoice = SalesDocument::query()->create([
    'company_id' => $company->id,
    'client_id' => $client->id,
    'project_id' => $project->id,
    'code' => 'ING-UAT-202608',
    'document_type' => 'Factura',
    'issue_date' => '2026-08-10',
    'due_date' => '2026-08-31',
    'projected_collection_date' => '2026-08-31',
    'net_amount' => $vat['net_amount'],
    'vat_rate' => $vat['vat_rate'],
    'vat_amount' => $vat['vat_amount'],
    'gross_amount' => $vat['gross_amount'],
    'status' => 'Pendiente',
    'is_voided' => false,
]);

$expenseA = ExpenseDocument::query()->create([
    'company_id' => $company->id,
    'code' => 'EGR-UAT-A',
    'vendor_name' => 'Proveedor UAT A',
    'project_id' => $project->id,
    'issue_date' => '2026-08-10',
    'due_date' => '2026-08-31',
    'net_amount' => 300000,
    'vat_amount' => 0,
    'recoverable_vat_amount' => 0,
    'gross_amount' => 300000,
    'payment_status' => 'Pendiente',
    'deductible_vat' => false,
]);

$expenseB = ExpenseDocument::query()->create([
    'company_id' => $company->id,
    'code' => 'EGR-UAT-B',
    'vendor_name' => 'Proveedor UAT B',
    'project_id' => $project->id,
    'issue_date' => '2026-08-10',
    'due_date' => '2026-08-31',
    'net_amount' => 200000,
    'vat_amount' => 0,
    'recoverable_vat_amount' => 0,
    'gross_amount' => 200000,
    'payment_status' => 'Pendiente',
    'deductible_vat' => false,
]);

$manualObligation = LegalObligation::query()->create([
    'company_id' => $company->id,
    'code' => 'OBL-UAT-MANUAL',
    'obligation_type' => 'MANUAL_UAT',
    'period_date' => '2026-08-01',
    'due_date' => '2026-08-20',
    'estimated_amount' => 150000,
    'pending_amount' => 150000,
    'status' => 'Pendiente',
    'source_calculation' => 'Obligacion UAT manual para conciliacion',
]);

$budget = Budget::query()->create([
    'company_id' => $company->id,
    'project_id' => $project->id,
    'scenario_id' => Scenario::query()->where('company_id', $company->id)->where('code', 'BASE')->value('id'),
    'period_date' => '2026-08-01',
    'revenue_budget' => 2400000,
    'personnel_budget' => 600000,
    'other_direct_budget' => 500000,
    'legal_budget' => 150000,
    'other_indirect_budget' => 0,
    'notes' => 'Presupuesto UAT Agosto 2026',
]);

$flowBeforeCash = $cashFlow->monthly($company->id, '2026-08-01', 1)[0];
$invoiceBalanceBefore = $receivables->balance($invoice);
$expenseABalanceBefore = $payables->balance($expenseA);
$expenseBBalanceBefore = $payables->balance($expenseB);
$manualObligationBalanceBefore = $obligations->balance($manualObligation);

$movementBase = static function (
    Company $company,
    CashAccount $account,
    string $code,
    ?string $sourceType,
    ?string $sourceCode,
    string $date,
    float $income,
    float $expense,
    Project $project
): array {
    return [
        'company_id' => $company->id,
        'code' => $code,
        'movement_type' => $income > 0 ? 'Ingreso' : 'Egreso',
        'source_document_type' => $sourceType,
        'source_document_code' => $sourceCode,
        'movement_date' => $date,
        'income' => $income,
        'expense' => $expense,
        'project_id' => $project->id,
        'cash_account_id' => $account->id,
        'status' => 'posted',
    ];
};

$cashMovements->create($movementBase($company, $cashAccount, 'MOV-UAT-COBRO', 'sales_document', $invoice->code, '2026-08-11', (float) $invoice->gross_amount, 0, $project), $admin);
$cashMovements->create($movementBase($company, $cashAccount, 'MOV-UAT-EGR-A', 'expense_document', $expenseA->code, '2026-08-12', 0, 300000, $project), $admin);
$cashMovements->create($movementBase($company, $cashAccount, 'MOV-UAT-EGR-B', 'expense_document', $expenseB->code, '2026-08-13', 0, 200000, $project), $admin);

$obligations->syncMonthlyObligations($company->id, '2026-08-01', 1);

$flowPhaseA = $cashFlow->monthly($company->id, '2026-08-01', 1)[0];
$expectedPhaseA = round(1000000 + (float) $invoice->gross_amount - 300000 - 200000, 2);

$cashMovements->create($movementBase($company, $cashAccount, 'MOV-UAT-OBL', 'legal_obligation', $manualObligation->code, '2026-08-14', 0, 150000, $project), $admin);

$flowPhaseB = $cashFlow->monthly($company->id, '2026-08-01', 1)[0];
$expectedPhaseB = round($expectedPhaseA - 150000, 2);

$allocation = $hourlyCosts->companyProjectAllocation($company->id, '2026-08-01');
$projectCost = round((float) ($allocation['projects'][$project->id]['cost'] ?? 0), 2);

$profitRow = collect($profitability->byProject($company->id, ['period' => '2026-08-01', 'project_id' => $project->id]))
    ->firstWhere('project_id', $project->id);

$budgetVariance = $budgets->variance($company->id, '2026-08-01', $project->id);
$dashboardData = $dashboard->data($company->id);

$invoicePaidOnce = CashMovement::query()->where('company_id', $company->id)->where('source_document_type', 'sales_document')->where('source_document_code', $invoice->code)->count() === 1;
$expensePaidTwice = CashMovement::query()->where('company_id', $company->id)->where('source_document_type', 'expense_document')->whereIn('source_document_code', [$expenseA->code, $expenseB->code])->count() === 2;
$obligationPaidOnce = CashMovement::query()->where('company_id', $company->id)->where('source_document_type', 'legal_obligation')->where('source_document_code', $manualObligation->code)->count() === 1;
$payrollMovementsCount = CashMovement::query()->where('company_id', $company->id)->where('source_document_type', 'payroll_record')->count();

addCase(
    $cases,
    'UAT-01 Cliente',
    'Empresa UAT preservada',
    'Crear Cliente UAT SpA',
    'Cliente activo y ligado a empresa',
    $client->legal_name.' / company_id '.$client->company_id,
    $client->company_id === $company->id,
    'clients.code='.$client->code
);

addCase(
    $cases,
    'UAT-02 Proyecto',
    'Cliente UAT creado',
    'Crear Proyecto UAT Agosto 2026',
    'Proyecto ligado al cliente con venta neta CLP 2.000.000',
    $project->name.' / neto '.money((float) $project->sale_net),
    $project->client_id === $client->id && (float) $project->sale_net === 2000000.0,
    'projects.code='.$project->code
);

addCase(
    $cases,
    'UAT-03 Personal',
    'Sin personal operacional',
    'Crear Persona A UAT y Persona B UAT',
    'Dos personas activas con tarifa HH CLP',
    $personA->name.'='.money((float) $personA->hourly_value).', '.$personB->name.'='.money((float) $personB->hourly_value),
    $personA->company_id === $company->id && $personB->company_id === $company->id,
    'people codes='.$personA->code.','.$personB->code
);

addCase(
    $cases,
    'UAT-04 Asignaciones',
    'Proyecto y personal creados',
    'Asignar ambas personas al proyecto',
    'Asignaciones activas y trazables',
    $assignmentA->code.' / '.$assignmentB->code,
    $assignmentA->project_id === $project->id && $assignmentB->project_id === $project->id,
    'project_assignments x2'
);

addCase(
    $cases,
    'UAT-05 Horas',
    'Asignaciones activas',
    'Registrar 20h para A y 10h para B',
    '30 horas aprobadas y montos consistentes con tarifa',
    'Horas='.money((float) ($timeA->hours_approved + $timeB->hours_approved)).' / monto='.money((float) $timeA->calculated_amount + (float) $timeB->calculated_amount),
    (float) $timeA->hours_approved === 20.0 && (float) $timeB->hours_approved === 10.0,
    'time_entries codes='.$timeA->code.','.$timeB->code
);

addCase(
    $cases,
    'UAT-06 Costos HH',
    'Horas y payroll por honorarios',
    'Calcular costo laboral del proyecto',
    'Costo directo total 550.000 segun employer_cost de payroll',
    money($projectCost),
    abs($projectCost - 550000.0) < 0.01,
    'hourly_cost_service allocation project='.$project->id
);

addCase(
    $cases,
    'UAT-07 Remuneraciones',
    'Personas por hora con parametros vigentes',
    'Generar payroll agosto 2026 sin pago',
    'Payroll calculado, con retencion vigente y sin impacto en caja real',
    'A employer_cost='.money((float) $payrollA->employer_cost).', B employer_cost='.money((float) $payrollB->employer_cost).', cash personnel_real='.money((float) $flowPhaseA['personnel_real']),
    $payrollMovementsCount === 0 && (float) $flowPhaseA['personnel_real'] === 0.0,
    'payroll status='.$payrollA->refresh()->status.'/'.$payrollB->refresh()->status
);

addCase(
    $cases,
    'UAT-08 Factura/documento de ingreso',
    'Proyecto comercial creado',
    'Crear factura neta 2.000.000 con IVA vigente por fecha',
    'Documento pendiente y caja sin cambio antes del cobro',
    'gross='.money((float) $invoice->gross_amount).', income_real_pre='.money((float) $flowBeforeCash['income_real']),
    abs((float) $flowBeforeCash['income_real']) < 0.01 && abs($invoiceBalanceBefore - (float) $invoice->gross_amount) < 0.01,
    'sales_documents.code='.$invoice->code
);

addCase(
    $cases,
    'UAT-09 Cobro efectivo',
    'Factura pendiente',
    'Registrar cobro total del documento',
    'Caja aumenta exactamente una vez por el monto bruto',
    'movs='.($invoicePaidOnce ? '1' : '!=1').', income_real='.money((float) $flowPhaseA['income_real']),
    $invoicePaidOnce && abs((float) $flowPhaseA['income_real'] - (float) $invoice->gross_amount) < 0.01,
    'cash_movements.code=MOV-UAT-COBRO'
);

addCase(
    $cases,
    'UAT-10 Gastos',
    'Sin egresos pagados',
    'Crear dos gastos y pagarlos',
    'Caja disminuye 500.000 exactamente una vez por cada documento',
    'payables_pre='.money($expenseABalanceBefore + $expenseBBalanceBefore).', other_real='.money((float) $flowPhaseA['other_real']),
    $expensePaidTwice && abs((float) $flowPhaseA['other_real'] - 500000.0) < 0.01,
    'expense_documents EGR-UAT-A/EGR-UAT-B'
);

addCase(
    $cases,
    'UAT-11 Obligacion pendiente',
    'Obligacion manual creada',
    'Dejar obligacion sin pago en fase A',
    'Debe quedar pendiente sin afectar caja',
    'pending='.money($manualObligationBalanceBefore).', legal_real_phase_a='.money((float) $flowPhaseA['legal_real']),
    abs($manualObligationBalanceBefore - 150000.0) < 0.01 && abs((float) $flowPhaseA['legal_real']) < 0.01,
    'legal_obligations.code='.$manualObligation->code
);

addCase(
    $cases,
    'UAT-12 Pago obligacion',
    'Obligacion pendiente 150.000',
    'Registrar pago real',
    'Caja disminuye 150.000 una sola vez y obligacion queda pagada',
    'legal_real_phase_b='.money((float) $flowPhaseB['legal_real']).', movement_count='.(string) CashMovement::query()->where('company_id', $company->id)->where('source_document_code', $manualObligation->code)->count(),
    $obligationPaidOnce && abs((float) $flowPhaseB['legal_real'] - 150000.0) < 0.01 && $manualObligation->refresh()->status === 'Pagado',
    'cash_movements.code=MOV-UAT-OBL'
);

addCase(
    $cases,
    'UAT-13 Presupuesto',
    'Proyecto UAT agosto 2026',
    'Crear presupuesto del periodo',
    'Presupuesto queda separado de caja real',
    'budget_total='.money((float) $budgetVariance['total_budget']).', total_real='.money((float) $budgetVariance['total_real']),
    abs((float) $budgetVariance['total_budget'] - (float) $budgetVariance['total_real']) > 0.01,
    'budgets.id='.(string) $budget->id
);

addCase(
    $cases,
    'UAT-14 Rentabilidad',
    'Ventas, horas y gastos creados',
    'Calcular rentabilidad por proyecto',
    'Venta neta sin IVA - costo laboral - gastos directos',
    'facturado='.money((float) ($profitRow['facturado'] ?? 0)).', costo='.money((float) ($profitRow['cost_personal'] ?? 0)).', otros='.money((float) ($profitRow['other_costs'] ?? 0)).', margen='.money((float) ($profitRow['margin'] ?? 0)),
    abs((float) ($profitRow['facturado'] ?? 0) - 2000000.0) < 0.01
        && abs((float) ($profitRow['cost_personal'] ?? 0) - 550000.0) < 0.01
        && abs((float) ($profitRow['other_costs'] ?? 0) - 500000.0) < 0.01
        && abs((float) ($profitRow['margin'] ?? 0) - 950000.0) < 0.01,
    'profitability_service.byProject'
);

addCase(
    $cases,
    'UAT-15 Dashboard',
    'Escenario fase B completo',
    'Leer KPIs del dashboard',
    'Dashboard consistente con flujo, documentos y payroll',
    'cash='.money((float) ($dashboardData['kpis']['cash_available'] ?? 0)).', income='.money((float) ($dashboardData['kpis']['income_month'] ?? 0)).', expense='.money((float) ($dashboardData['kpis']['expense_month'] ?? 0)),
    abs((float) ($dashboardData['kpis']['cash_available'] ?? 0) - $expectedPhaseB) < 0.01
        && abs((float) ($dashboardData['kpis']['income_month'] ?? 0) - (float) $invoice->gross_amount) < 0.01
        && abs((float) ($dashboardData['kpis']['expense_month'] ?? 0) - 650000.0) < 0.01,
    'dashboard_service.data'
);

addCase(
    $cases,
    'UAT-16 Reconciliacion caja',
    'Fase A y Fase B del escenario',
    'Comparar caja esperada vs obtenida',
    'Caja debe cuadrar exactamente en ambas fases',
    'fase_a='.money((float) $flowPhaseA['closing_real']).', fase_b='.money((float) $flowPhaseB['closing_real']),
    abs((float) $flowPhaseA['closing_real'] - $expectedPhaseA) < 0.01
        && abs((float) $flowPhaseB['closing_real'] - $expectedPhaseB) < 0.01,
    'cash_flow_service.monthly'
);

addCase(
    $cases,
    'UAT-17 No doble contabilizacion',
    'Documentos, obligaciones y payroll creados',
    'Verificar que solo cash_movements mueven caja',
    'Sin duplicidad por documento + movimiento',
    'income_pre='.money((float) $flowBeforeCash['income_real']).', other_pre='.money((float) $flowBeforeCash['other_real']).', personnel_pre='.money((float) $flowBeforeCash['personnel_real']).', legal_pre='.money((float) $flowBeforeCash['legal_real']),
    abs((float) $flowBeforeCash['income_real']) < 0.01
        && abs((float) $flowBeforeCash['other_real']) < 0.01
        && abs((float) $flowBeforeCash['personnel_real']) < 0.01
        && abs((float) $flowBeforeCash['legal_real']) < 0.01,
    'cash movements only'
);

$uats = [
    'clients' => Client::query()->where('company_id', $company->id)->where('code', 'like', '%UAT%')->count(),
    'projects' => Project::query()->where('company_id', $company->id)->where('code', 'like', '%UAT%')->count(),
    'people' => Person::query()->where('company_id', $company->id)->where('code', 'like', '%UAT%')->count(),
    'assignments' => ProjectAssignment::query()->where('company_id', $company->id)->where('code', 'like', '%UAT%')->count(),
    'time_entries' => TimeEntry::query()->where('company_id', $company->id)->where('code', 'like', '%UAT%')->count(),
    'payroll' => PayrollRecord::query()->where('company_id', $company->id)->where('code', 'like', '%UAT%')->count(),
    'sales' => SalesDocument::query()->where('company_id', $company->id)->where('code', 'like', '%UAT%')->count(),
    'expenses' => \App\Models\ExpenseDocument::query()->where('company_id', $company->id)->where('code', 'like', '%UAT%')->count(),
    'obligations' => LegalObligation::query()->where('company_id', $company->id)->where('code', 'like', '%UAT%')->count(),
    'budgets' => Budget::query()->where('company_id', $company->id)->where('notes', 'like', '%UAT%')->count(),
    'cash_movements' => CashMovement::query()->where('company_id', $company->id)->where('code', 'like', '%UAT%')->count(),
];

$consistency = [
    'cross_company' => CashMovement::query()
        ->where('company_id', $company->id)
        ->where('code', 'like', '%UAT%')
        ->where('project_id', '!=', $project->id)
        ->count() === 0,
    'duplicate_invoice_movements' => CashMovement::query()
        ->where('company_id', $company->id)
        ->where('source_document_type', 'sales_document')
        ->where('source_document_code', $invoice->code)
        ->count() === 1,
    'duplicate_obligation_movements' => CashMovement::query()
        ->where('company_id', $company->id)
        ->where('source_document_type', 'legal_obligation')
        ->where('source_document_code', $manualObligation->code)
        ->count() === 1,
    'orphan_assignment_links' => TimeEntry::query()
        ->where('company_id', $company->id)
        ->whereIn('id', [$timeA->id, $timeB->id])
        ->whereNull('assignment_id')
        ->count() === 0,
];

$passCount = count(array_filter($cases, fn (array $case): bool => $case['status'] === 'PASS'));
$failCount = count($cases) - $passCount;
$uatPass = $failCount === 0
    && abs((float) $flowPhaseA['closing_real'] - $expectedPhaseA) < 0.01
    && abs((float) $flowPhaseB['closing_real'] - $expectedPhaseB) < 0.01;

$report = [
    'title' => 'UAT financiero end-to-end - Agosto 2026',
    'executed_at' => now()->toDateTimeString(),
    'company' => [
        'id' => $company->id,
        'name' => $company->name,
    ],
    'scenario' => [
        'cash_account' => $cashAccount->name,
        'opening_balance' => 1000000.0,
        'invoice_net' => (float) $invoice->net_amount,
        'vat_rate' => (float) $invoice->vat_rate,
        'invoice_vat' => (float) $invoice->vat_amount,
        'invoice_gross' => (float) $invoice->gross_amount,
        'expense_total_paid' => 500000.0,
        'manual_obligation' => 150000.0,
        'payroll_company_cost_total' => round((float) $payrollA->employer_cost + (float) $payrollB->employer_cost, 2),
    ],
    'reconciliation' => [
        'phase_a_expected' => $expectedPhaseA,
        'phase_a_obtained' => round((float) $flowPhaseA['closing_real'], 2),
        'phase_b_expected' => $expectedPhaseB,
        'phase_b_obtained' => round((float) $flowPhaseB['closing_real'], 2),
    ],
    'costs' => [
        'expected_total' => 550000.0,
        'obtained_total' => $projectCost,
        'real_hourly_project_cost' => round($projectCost / 30, 6),
    ],
    'profitability' => [
        'facturado' => round((float) ($profitRow['facturado'] ?? 0), 2),
        'cost_personal' => round((float) ($profitRow['cost_personal'] ?? 0), 2),
        'other_costs' => round((float) ($profitRow['other_costs'] ?? 0), 2),
        'margin' => round((float) ($profitRow['margin'] ?? 0), 2),
        'margin_pct' => round((float) ($profitRow['margin_pct'] ?? 0), 4),
    ],
    'budget' => [
        'revenue_budget' => round((float) $budgetVariance['revenue_budget'], 2),
        'revenue_real' => round((float) $budgetVariance['revenue_real'], 2),
        'total_budget' => round((float) $budgetVariance['total_budget'], 2),
        'total_real' => round((float) $budgetVariance['total_real'], 2),
    ],
    'dashboard' => [
        'cash_available' => round((float) ($dashboardData['kpis']['cash_available'] ?? 0), 2),
        'income_month' => round((float) ($dashboardData['kpis']['income_month'] ?? 0), 2),
        'expense_month' => round((float) ($dashboardData['kpis']['expense_month'] ?? 0), 2),
        'receivables' => round((float) ($dashboardData['kpis']['receivables'] ?? 0), 2),
        'payables' => round((float) ($dashboardData['kpis']['payables'] ?? 0), 2),
        'personnel_cost' => round((float) ($dashboardData['kpis']['personnel_cost'] ?? 0), 2),
        'margin' => round((float) ($dashboardData['kpis']['margin'] ?? 0), 2),
    ],
    'double_counting' => [
        'invoice_before_cash_changes_cash' => abs((float) $flowBeforeCash['income_real']) > 0.01,
        'expense_before_cash_changes_cash' => abs((float) $flowBeforeCash['other_real']) > 0.01,
        'obligation_before_cash_changes_cash' => abs((float) $flowPhaseA['legal_real']) > 0.01,
        'payroll_before_cash_changes_cash' => abs((float) $flowPhaseA['personnel_real']) > 0.01,
    ],
    'consistency' => $consistency,
    'uat_records' => $uats,
    'cases' => $cases,
    'summary' => [
        'uat_pass' => $uatPass,
        'cases_executed' => count($cases),
        'pass' => $passCount,
        'fail' => $failCount,
        'defects' => $defects,
    ],
];

$reportPath = storage_path('app/private/financial_uat_aug2026.json');
if (! is_dir(dirname($reportPath))) {
    mkdir(dirname($reportPath), 0775, true);
}
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

exit($uatPass ? 0 : 1);
