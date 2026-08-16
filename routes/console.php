<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\Import\FinanceExcelImporter;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('finance:import-excel {archivo : Ruta del XLSX V3} {--dry-run : Valida y genera reporte sin escribir en BD}', function (FinanceExcelImporter $importer) {
    $path = (string) $this->argument('archivo');
    $dryRun = (bool) $this->option('dry-run');
    $report = $importer->import($path, $dryRun);
    $summary = $report['summary'];

    $this->info(($dryRun ? 'Dry run' : 'Importacion').' completada.');
    $this->line('Reporte: storage/app/import_report.json');
    $this->line("Leidos: {$summary['leidos']} | Validos: {$summary['validos']} | Insertados: {$summary['insertados']} | Omitidos: {$summary['omitidos']} | Warnings: {$summary['warnings']} | Errores: {$summary['errores']}");

    return $summary['errores'] > 0 ? Command::FAILURE : Command::SUCCESS;
})->purpose('Importa datos funcionales del Excel V3 y genera QA local compacto');

Artisan::command('uat:clear-data {--force : Omite la confirmacion interactiva}', function () {
    if (config('app.env') === 'production') {
        $this->error('El comando uat:clear-data está deshabilitado en producción.');

        return Command::FAILURE;
    }

    if (! in_array(config('app.env'), ['local', 'testing', 'testing-mysql', 'uat'], true)) {
        $this->error('El comando uat:clear-data solo puede ejecutarse en entornos locales o UAT.');

        return Command::FAILURE;
    }

    if (! $this->option('force') && ! $this->confirm('Esto eliminará todos los datos operacionales/test y conservará catálogos, parámetros, empresas y usuarios. ¿Continuar?')) {
        $this->warn('Operación cancelada.');

        return Command::SUCCESS;
    }

    $groups = [
        'Clientes' => ['clients'],
        'Proyectos' => ['projects'],
        'Personal' => ['people'],
        'Asignaciones/Horas' => ['project_assignments', 'time_entries', 'sales_document_time_entries'],
        'Remuneraciones' => ['payroll_adjustments', 'payroll_records'],
        'Ventas/Gastos/Movimientos' => ['cash_movements', 'sales_documents', 'expense_documents', 'legal_obligations'],
        'Cierres/Presupuestos/Auditoria' => ['monthly_closures', 'budgets', 'audit_logs'],
        'Tesoreria' => ['cash_accounts'],
        'Demo managers' => ['project_managers'],
    ];

    $order = [
        'sales_document_time_entries',
        'payroll_adjustments',
        'cash_movements',
        'monthly_closures',
        'budgets',
        'legal_obligations',
        'audit_logs',
        'sales_documents',
        'expense_documents',
        'payroll_records',
        'time_entries',
        'project_assignments',
        'projects',
        'people',
        'clients',
        'cash_accounts',
        'project_managers',
    ];

    $deleted = [];
    $total = 0;

    $driver = DB::getDriverName();

    try {
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        DB::transaction(function () use ($order, &$deleted, &$total): void {
            foreach ($order as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $count = (int) DB::table($table)->count();
                if ($count === 0) {
                    $deleted[$table] = 0;
                    continue;
                }

                DB::table($table)->delete();
                $deleted[$table] = $count;
                $total += $count;
            }
        });
    } finally {
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    $this->info("Datos operacionales eliminados: {$total} registros");

    foreach ($groups as $label => $tables) {
        $groupTotal = array_sum(array_intersect_key($deleted, array_flip($tables)));
        $this->line("{$label}: {$groupTotal}");
    }

    $this->line('Empresa/Admin preservados: OK');
    $this->line('Catálogos preservados: OK');
    $this->line('Parámetros legales/UF preservados: OK');

    return Command::SUCCESS;
})->purpose('Limpia datos operacionales/UAT conservando empresas, usuarios, catálogos y parámetros');
