<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
