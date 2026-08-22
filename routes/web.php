<?php

use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GeographyController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\OperationalCrudController;
use App\Http\Controllers\PayrollBatchController;
use App\Http\Controllers\SalesPrefacturationController;
use App\Http\Controllers\TwoFactorChallengeController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;

Route::get('/', [AuthController::class, 'home'])->name('home');
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['guest'])->group(function (): void {
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.login');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.login.store');
});

Route::middleware(['auth', 'absolute.session'])->group(function (): void {
    Route::get('/mi-cuenta/seguridad', [AccountSecurityController::class, 'show'])->name('account.security');
    Route::post('/mi-cuenta/seguridad/two-factor', [AccountSecurityController::class, 'enable'])->name('account.security.enable-2fa');
    Route::post('/mi-cuenta/seguridad/two-factor/confirm', [AccountSecurityController::class, 'confirm'])->name('account.security.confirm-2fa');
    Route::delete('/mi-cuenta/seguridad/two-factor', [AccountSecurityController::class, 'disable'])->name('account.security.disable-2fa');
    Route::post('/mi-cuenta/seguridad/two-factor/recovery-codes', [AccountSecurityController::class, 'regenerateRecoveryCodes'])->name('account.security.regenerate-recovery-codes');

    Route::get('/user/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('/user/confirm-password', [ConfirmablePasswordController::class, 'store'])->name('password.confirm.store');
});

Route::middleware(['auth', 'absolute.session', 'admin.2fa'])->controller(ManagementController::class)->group(function (): void {
    Route::get('/dashboard', 'dashboard')->name('dashboard');
    Route::get('/gestion/obligaciones', 'obligations')->name('management.obligations');
    Route::get('/gestion/presupuesto', 'budgets')->name('management.budgets');
    Route::get('/gestion/flujos', 'flows')->name('management.flows');
    Route::get('/gestion/rentabilidad', 'profitability')->name('management.profitability');
});

Route::middleware(['auth', 'absolute.session', 'admin.2fa', 'admin'])->prefix('administracion/usuarios')->name('admin.users.')->controller(UserManagementController::class)->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::get('/crear', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/{user}/editar', 'edit')->name('edit');
    Route::put('/{user}', 'update')->name('update');
    Route::patch('/{user}/estado', 'toggleActive')->name('toggle-active');
    Route::get('/{user}/password', 'editPassword')->name('password.edit');
    Route::put('/{user}/password', 'updatePassword')->name('password.update');
    Route::delete('/{user}/two-factor', 'resetTwoFactor')->name('two-factor.reset');
});

Route::middleware(['auth', 'absolute.session', 'admin.2fa'])->group(function (): void {
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
    Route::post('/', 'store')->name('store');
    Route::get('/{record}', 'show')->name('show');
    Route::get('/{record}/editar', 'edit')->name('edit');
    Route::put('/{record}', 'update')->name('update');
    Route::patch('/{record}/active', 'toggleActive')->name('toggle-active');
    Route::delete('/{record}', 'destroy')->name('destroy');
});
