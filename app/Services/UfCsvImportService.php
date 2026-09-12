<?php

namespace App\Services;

use App\Models\UfValue;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class UfCsvImportService
{
    private const MONTHS = ['Ene' => 1, 'Feb' => 2, 'Mar' => 3, 'Abr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Ago' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dic' => 12];

    public function import(UploadedFile $file, int $companyId, int $year): array
    {
        if ($year < 1900 || $year > 2200) {
            throw new DomainException('El año de importación no es válido.');
        }

        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new DomainException('No se pudo leer el archivo CSV.');
        }

        try {
            $header = fgetcsv($handle, 0, ';');
            if ($header === false) {
                throw new DomainException('El archivo CSV está vacío.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            if ($header !== array_merge(['Día'], array_keys(self::MONTHS))) {
                throw new DomainException('El encabezado debe contener Día y las columnas Ene a Dic.');
            }

            $rows = [];
            while (($columns = fgetcsv($handle, 0, ';')) !== false) {
                if (count(array_filter($columns, fn ($value): bool => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                $day = filter_var($columns[0] ?? null, FILTER_VALIDATE_INT);
                if ($day === false || $day < 1 || $day > 31) {
                    throw new DomainException('El día del archivo CSV no es válido.');
                }
                foreach (array_values(self::MONTHS) as $offset => $month) {
                    $raw = trim((string) ($columns[$offset + 1] ?? ''));
                    if ($raw === '') {
                        continue;
                    }
                    $date = Carbon::createFromDate($year, $month, 1)->setDay($day);
                    if ((int) $date->month !== $month || (int) $date->day !== $day) {
                        throw new DomainException('El archivo contiene una fecha imposible.');
                    }
                    $normalized = str_replace(',', '.', str_replace('.', '', $raw));
                    if (! is_numeric($normalized) || (float) $normalized < 0) {
                        throw new DomainException('El valor UF del archivo no es válido.');
                    }
                    $rows[$date->toDateString()] = (float) $normalized;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new DomainException('El archivo CSV no contiene valores UF.');
        }

        return DB::transaction(function () use ($rows, $companyId): array {
            $summary = ['processed' => count($rows), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            foreach ($rows as $date => $value) {
                $existing = UfValue::query()->forCompany($companyId)->whereDate('value_date', $date)->first();
                if ($existing === null) {
                    UfValue::query()->create(['company_id' => $companyId, 'value_date' => $date, 'value' => $value, 'source' => 'SII', 'source_name' => 'SII', 'active' => true]);
                    $summary['created']++;
                    continue;
                }
                if ((float) $existing->value === $value && $existing->source === 'SII' && $existing->source_name === 'SII' && $existing->active === true) {
                    $summary['unchanged']++;
                    continue;
                }
                $existing->forceFill(['value' => $value, 'source' => 'SII', 'source_name' => 'SII', 'active' => true])->save();
                $summary['updated']++;
            }
            return $summary;
        });
    }
}
