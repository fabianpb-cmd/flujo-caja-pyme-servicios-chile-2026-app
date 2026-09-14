<?php

use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\BankRegularizationController;
use App\Http\Controllers\BankStatementController;
use App\Http\Controllers\GeographyController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\OperationalCrudController;
use App\Http\Controllers\PayrollBatchController;
use App\Http\Controllers\ProjectBillingMilestoneController;
use App\Http\Controllers\SalesPrefacturationController;
use App\Http\Controllers\TwoFactorChallengeController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;

Route::get('/', [AuthController::class, 'home'])->middleware('sensitive.no-store')->name('home');
Route::get('/login', [AuthController::class, 'showLogin'])->middleware('sensitive.no-store')->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('sensitive.no-store')->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->middleware(['auth', 'sensitive.no-store'])->name('logout');

Route::middleware(['guest', 'sensitive.no-store'])->group(function (): void {
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.login');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('two-factor.login.store');
});

Route::middleware(['auth', 'absolute.session'])->group(function (): void {
    Route::get('/mi-cuenta/seguridad', [AccountSecurityController::class, 'show'])->middleware('sensitive.no-store')->name('account.security');
    Route::post('/mi-cuenta/seguridad/two-factor', [AccountSecurityController::class, 'enable'])->middleware('sensitive.no-store')->name('account.security.enable-2fa');
    Route::post('/mi-cuenta/seguridad/two-factor/confirm', [AccountSecurityController::class, 'confirm'])->middleware('sensitive.no-store')->name('account.security.confirm-2fa');
    Route::delete('/mi-cuenta/seguridad/two-factor', [AccountSecurityController::class, 'disable'])->middleware('sensitive.no-store')->name('account.security.disable-2fa');
    Route::post('/mi-cuenta/seguridad/two-factor/recovery-codes', [AccountSecurityController::class, 'regenerateRecoveryCodes'])->middleware('sensitive.no-store')->name('account.security.regenerate-recovery-codes');

    Route::get('/user/confirm-password', [ConfirmablePasswordController::class, 'show'])->middleware('sensitive.no-store')->name('password.confirm');
    Route::post('/user/confirm-password', [ConfirmablePasswordController::class, 'store'])->middleware('sensitive.no-store')->name('password.confirm.store');
});

Route::post('/ayudante/preguntar', [AssistantController::class, 'ask'])
    ->middleware(['auth', 'absolute.session', 'admin.2fa', 'sensitive.no-store'])
    ->name('assistant.ask');

Route::middleware(['auth', 'absolute.session', 'admin.2fa'])->controller(ManagementController::class)->group(function (): void {
    Route::get('/dashboard', 'dashboard')->name('dashboard');
    Route::get('/gestion/obligaciones', 'obligations')->name('management.obligations');
    Route::get('/gestion/agenda-financiera', 'financialAgenda')->name('management.financial-agenda');
    Route::get('/gestion/presupuesto', 'budgets')->name('management.budgets');
    Route::get('/gestion/flujos', 'flows')->name('management.flows');
    Route::get('/gestion/rentabilidad', 'profitability')->name('management.profitability');
});

Route::middleware(['auth', 'absolute.session', 'admin.2fa'])->controller(BankReconciliationController::class)->group(function (): void {
    Route::get('/tesoreria/conciliacion-bancaria', 'index')->name('bank-reconciliation.index');
    Route::post('/tesoreria/conciliacion-bancaria', 'store')->name('bank-reconciliation.store');
    Route::post('/tesoreria/conciliacion-bancaria/{reconciliation}/conciliar', 'reconcile')->name('bank-reconciliation.reconcile');
});

Route::middleware(['auth', 'absolute.session', 'admin.2fa'])->controller(BankRegularizationController::class)->group(function (): void {
    Route::get('/tesoreria/regularizacion-bancaria', 'index')->name('bank-regularization.index');
    Route::post('/tesoreria/regularizacion-bancaria/asignar', 'assign')->name('bank-regularization.assign');
    Route::post('/tesoreria/regularizacion-bancaria/{assignment}/revertir', 'reverse')->name('bank-regularization.reverse');
});

Route::middleware(['auth', 'absolute.session', 'admin.2fa'])->controller(BankStatementController::class)->group(function (): void {
    Route::get('/tesoreria/cartolas-bancarias', 'index')->name('bank-statements.index');
    Route::post('/tesoreria/cartolas-bancarias/importar', 'import')->name('bank-statements.import');
    Route::post('/tesoreria/cartolas-bancarias/matching', 'match')->name('bank-statements.match');
    Route::post('/tesoreria/cartolas-bancarias/matching/{match}/revertir', 'reverse')->name('bank-statements.reverse');
    Route::post('/tesoreria/cartolas-bancarias/lineas/{line}/ignorar', 'ignore')->name('bank-statements.ignore');
});

Route::middleware(['auth', 'absolute.session', 'admin.2fa', 'admin'])->prefix('administracion/usuarios')->name('admin.users.')->controller(UserManagementController::class)->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::get('/crear', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/{user}/editar', 'edit')->name('edit');
    Route::put('/{user}', 'update')->name('update');
    Route::patch('/{user}/estado', 'toggleActive')->name('toggle-active');
    Route::get('/{user}/password', 'editPassword')->middleware('sensitive.no-store')->name('password.edit');
    Route::put('/{user}/password', 'updatePassword')->middleware('sensitive.no-store')->name('password.update');
    Route::delete('/{user}/two-factor', 'resetTwoFactor')->middleware('sensitive.no-store')->name('two-factor.reset');
});

Route::middleware(['auth', 'absolute.session', 'admin.2fa'])->group(function (): void {
    Route::post('/proyectos/{project}/hitos', [ProjectBillingMilestoneController::class, 'store'])->name('projects.milestones.store');
    Route::put('/proyectos/{project}/hitos/{milestone}', [ProjectBillingMilestoneController::class, 'update'])->name('projects.milestones.update');
    Route::delete('/proyectos/{project}/hitos/{milestone}', [ProjectBillingMilestoneController::class, 'destroy'])->name('projects.milestones.destroy');
    Route::post('/proyectos/{project}/hitos/{milestone}/vista-previa', [ProjectBillingMilestoneController::class, 'preview'])->name('projects.milestones.preview');
    Route::post('/proyectos/{project}/hitos/{milestone}/facturar', [ProjectBillingMilestoneController::class, 'issue'])->name('projects.milestones.issue');
    Route::get('/geografia/regiones/{region}/comunas', [GeographyController::class, 'communes'])->name('geography.regions.communes');
    Route::post('/operacion/payroll-records/generar-periodo', [PayrollBatchController::class, 'generate'])->name('payroll.generate-period');
    Route::post('/operacion/payroll-records/recalcular-borradores', [PayrollBatchController::class, 'recalculateDrafts'])->name('payroll.recalculate-drafts');
    Route::post('/ventas/prefacturacion/calcular', [SalesPrefacturationController::class, 'preview'])->name('sales-prefacturation.preview');
    Route::post('/ventas/prefacturacion/generar-borrador', [SalesPrefacturationController::class, 'generateDraft'])->name('sales-prefacturation.generate-draft');
    Route::post('/sesion/mantener', [AuthController::class, 'keepAlive'])->name('session.keep-alive');

    Route::get('/ventas/facturas', function (Request $request, OperationalCrudController $controller) {
        return $controller->index($request, 'sales-documents');
    })->name('sales-documents.index');

    Route::get('/ventas/cuentas-por-cobrar', function (Request $request, OperationalCrudController $controller) {
        return $controller->index($request, 'sales-documents');
    })->name('receivables.index');

    Route::get('/gastos/egresos', function (Request $request, OperationalCrudController $controller) {
        return $controller->index($request, 'expense-documents');
    })->name('expense-documents.index');

    Route::get('/gastos/cuentas-por-pagar', function (Request $request, OperationalCrudController $controller) {
        return $controller->index($request, 'expense-documents');
    })->name('payables.index');
});

Route::middleware(['auth', 'absolute.session', 'admin.2fa'])->prefix('operacion/{resource}')->name('operational.')->controller(OperationalCrudController::class)->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::get('/crear', 'create')->name('create');
    Route::post('/commitment-preview', 'assignmentCommitmentPreview')->name('assignment-commitment-preview');
    Route::post('/period-preview', 'timeEntryPeriodPreview')->name('time-entry-period-preview');
    Route::post('/', 'store')->name('store');
    Route::post('/{record}/confirmar', 'confirmPayrollRecord')->name('confirm');
    Route::post('/{record}/emitir', 'confirmSalesDocument')->name('sales-documents.confirm');
    Route::get('/{record}', 'show')->name('show');
    Route::get('/{record}/editar', 'edit')->name('edit');
    Route::put('/{record}', 'update')->name('update');
    Route::patch('/{record}/active', 'toggleActive')->name('toggle-active');
    Route::delete('/{record}', 'destroy')->name('destroy');
});

Route::post('/operacion/uf-values/import', [OperationalCrudController::class, 'importUf'])
    ->middleware(['auth', 'absolute.session', 'admin.2fa', 'admin'])
    ->name('operational.uf-values.import');
