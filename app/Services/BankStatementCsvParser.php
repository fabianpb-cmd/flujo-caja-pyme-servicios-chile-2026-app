<?php

namespace App\Services;

use App\Support\UiFormatter;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Parses only the normalized CSV interchange supported by V1.2. Mapping is
 * deliberately narrow so an ambiguous bank export never becomes evidence.
 */
class BankStatementCsvParser
{
    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        'fecha' => 'transaction_date',
        'transaction_date' => 'transaction_date',
        'fecha_movimiento' => 'transaction_date',
        'descripcion' => 'description',
        'description' => 'description',
        'glosa' => 'description',
        'detalle' => 'description',
        'referencia' => 'reference',
        'ref' => 'reference',
        'fecha_valor' => 'value_date',
        'value_date' => 'value_date',
        'ingreso' => 'income',
        'abono' => 'income',
        'credito' => 'income',
        'credit' => 'income',
        'egreso' => 'expense',
        'cargo' => 'expense',
        'debito' => 'expense',
        'debit' => 'expense',
        'monto' => 'signed_amount',
        'importe' => 'signed_amount',
        'amount' => 'signed_amount',
        'id_externo' => 'external_id',
        'external_id' => 'external_id',
        'id_transaccion' => 'external_id',
        'transaction_id' => 'external_id',
    ];

    /**
     * @return array{rows: array<int, array{transaction_date: string, value_date: ?string, description: string, reference: ?string, amount: float, direction: string, external_id: ?string}>, statement_from: string, statement_to: string}
     */
    public function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new DomainException('No se pudo leer el archivo CSV de cartola.');
        }

        try {
            $first = fgets($handle);
            if ($first === false || trim($first) === '') {
                throw new DomainException('El archivo CSV de cartola está vacío.');
            }
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            rewind($handle);
            $header = fgetcsv($handle, 0, $delimiter);
            if ($header === false) {
                throw new DomainException('El archivo CSV de cartola no contiene encabezados.');
            }
            $mapping = $this->mapHeaders($header);
            $rows = [];
            $rowNumber = 1;

            while (($columns = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;
                if (count(array_filter($columns, fn ($value): bool => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if (count($columns) > count($header) && trim(implode('', array_slice($columns, count($header)))) !== '') {
                    throw new DomainException("La fila {$rowNumber} tiene más columnas que el encabezado.");
                }
                $rows[] = $this->parseRow($columns, $mapping, $rowNumber);
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new DomainException('El archivo CSV de cartola no contiene movimientos.');
        }

        $dates = array_column($rows, 'transaction_date');

        return [
            'rows' => $rows,
            'statement_from' => min($dates),
            'statement_to' => max($dates),
        ];
    }

    /** @param array<int, string|null> $header
     * @return array<string, int>
     */
    private function mapHeaders(array $header): array
    {
        $mapping = [];
        foreach ($header as $index => $raw) {
            $normalized = $this->normalizeHeader((string) $raw, $index === 0);
            $field = self::HEADER_ALIASES[$normalized] ?? null;
            if ($field === null) {
                continue;
            }
            if (array_key_exists($field, $mapping)) {
                throw new DomainException("El encabezado CSV es ambiguo para {$field}.");
            }
            $mapping[$field] = $index;
        }

        foreach (['transaction_date', 'description'] as $required) {
            if (! array_key_exists($required, $mapping)) {
                throw new DomainException('El CSV requiere las columnas fecha y descripcion.');
            }
        }
        $hasSplitAmounts = isset($mapping['income']) || isset($mapping['expense']);
        if ($hasSplitAmounts && isset($mapping['signed_amount'])) {
            throw new DomainException('El CSV no puede mezclar monto firmado con columnas ingreso/egreso.');
        }
        if (! $hasSplitAmounts && ! isset($mapping['signed_amount'])) {
            throw new DomainException('El CSV requiere ingreso/egreso o una columna monto firmado.');
        }

        return $mapping;
    }

    /** @param array<int, string|null> $columns
     * @param array<string, int> $mapping
     * @return array{transaction_date: string, value_date: ?string, description: string, reference: ?string, amount: float, direction: string, external_id: ?string}
     */
    private function parseRow(array $columns, array $mapping, int $rowNumber): array
    {
        $value = fn (string $field): string => trim((string) ($columns[$mapping[$field] ?? -1] ?? ''));
        $transactionDate = $this->parseDate($value('transaction_date'), $rowNumber, 'fecha');
        $description = $value('description');
        if ($description === '') {
            throw new DomainException("La fila {$rowNumber} requiere descripción.");
        }

        if (isset($mapping['signed_amount'])) {
            $signed = $this->parseAmount($value('signed_amount'), $rowNumber);
            if ($signed === 0.0) {
                throw new DomainException("La fila {$rowNumber} requiere un monto distinto de cero.");
            }
            $direction = $signed > 0 ? 'income' : 'expense';
            $amount = abs($signed);
        } else {
            $income = isset($mapping['income']) ? $this->parseAmount($value('income'), $rowNumber, true) : 0.0;
            $expense = isset($mapping['expense']) ? $this->parseAmount($value('expense'), $rowNumber, true) : 0.0;
            if (($income > 0 && $expense > 0) || ($income === 0.0 && $expense === 0.0)) {
                throw new DomainException("La fila {$rowNumber} requiere exactamente un ingreso o un egreso.");
            }
            $direction = $income > 0 ? 'income' : 'expense';
            $amount = $income > 0 ? $income : $expense;
        }

        return [
            'transaction_date' => $transactionDate,
            'value_date' => isset($mapping['value_date']) && $value('value_date') !== '' ? $this->parseDate($value('value_date'), $rowNumber, 'fecha valor') : null,
            'description' => $description,
            'reference' => $value('reference') !== '' ? $value('reference') : null,
            'amount' => UiFormatter::roundAmount($amount, 'CLP'),
            'direction' => $direction,
            'external_id' => isset($mapping['external_id']) && $value('external_id') !== '' ? $value('external_id') : null,
        ];
    }

    private function normalizeHeader(string $value, bool $first): string
    {
        if ($first) {
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?: $value;
        }

        return trim(Str::of(Str::ascii($value))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString());
    }

    private function parseDate(string $value, int $rowNumber, string $label): string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date !== false && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
                // Try the remaining explicitly supported formats.
            }
        }
        throw new DomainException("La {$label} de la fila {$rowNumber} no es válida.");
    }

    private function parseAmount(string $value, int $rowNumber, bool $blankAsZero = false): float
    {
        $value = trim(str_replace(['$', 'CLP', ' '], '', $value));
        if ($value === '') {
            if ($blankAsZero) {
                return 0.0;
            }
            throw new DomainException("El monto de la fila {$rowNumber} no es válido.");
        }

        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '+-');
        $comma = strrpos($unsigned, ',');
        $dot = strrpos($unsigned, '.');
        if ($comma !== false && $dot !== false) {
            $normalized = $comma > $dot
                ? str_replace(',', '.', str_replace('.', '', $unsigned))
                : str_replace(',', '', $unsigned);
        } elseif ($comma !== false) {
            $tail = strlen($unsigned) - $comma - 1;
            $normalized = $tail <= 2 ? str_replace(',', '.', $unsigned) : str_replace(',', '', $unsigned);
        } elseif ($dot !== false) {
            $tail = strlen($unsigned) - $dot - 1;
            $normalized = $tail <= 2 ? $unsigned : str_replace('.', '', $unsigned);
        } else {
            $normalized = $unsigned;
        }
        if (! preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            throw new DomainException("El monto de la fila {$rowNumber} no es válido.");
        }

        return ($negative ? -1 : 1) * (float) $normalized;
    }
}
