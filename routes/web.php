<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\OperationalCrudController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::get('/', [AuthController::class, 'home'])->name('home');
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::middleware('auth')->controller(ManagementController::class)->group(function (): void {
    Route::get('/dashboard', 'dashboard')->name('dashboard');
    Route::get('/gestion/obligaciones', 'obligations')->name('management.obligations');
    Route::get('/gestion/presupuesto', 'budgets')->name('management.budgets');
    Route::get('/gestion/flujos', 'flows')->name('management.flows');
    Route::get('/gestion/rentabilidad', 'profitability')->name('management.profitability');
});

Route::middleware('auth')->group(function (): void {
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

Route::middleware('auth')->prefix('operacion/{resource}')->name('operational.')->controller(OperationalCrudController::class)->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::get('/crear', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/{record}', 'show')->name('show');
    Route::get('/{record}/editar', 'edit')->name('edit');
    Route::put('/{record}', 'update')->name('update');
    Route::patch('/{record}/active', 'toggleActive')->name('toggle-active');
    Route::delete('/{record}', 'destroy')->name('destroy');
});
