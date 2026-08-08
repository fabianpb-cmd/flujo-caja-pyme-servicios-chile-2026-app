<?php

namespace App\Services\Import;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class XlsxWorkbookReader
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_RELS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const NS_OFFICE_RELS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private ZipArchive $zip;

    /** @var array<string, string> */
    private array $sheetPaths = [];

    /** @var array<int, string> */
    private array $sharedStrings = [];

    /** @var array<int, bool> */
    private array $dateStyles = [];

    public function __construct(private readonly string $path)
    {
        if (! is_file($path)) {
            throw new RuntimeException("Archivo no encontrado: {$path}");
        }

        $this->zip = new ZipArchive();

        if ($this->zip->open($path) !== true) {
            throw new RuntimeException("No se pudo abrir el XLSX: {$path}");
        }

        $this->loadSharedStrings();
        $this->loadDateStyles();
        $this->loadSheetPaths();
    }

    public function __destruct()
    {
        $this->zip->close();
    }

    /** @return list<string> */
    public function sheetNames(): array
    {
        return array_keys($this->sheetPaths);
    }

    /** @return list<array<string, mixed>> */
    public function tableRows(string $sheetName, int $headerRow = 5, int $firstDataRow = 6): array
    {
        $rows = $this->rows($sheetName);
        $header = $rows[$headerRow] ?? [];
        $headers = [];

        foreach ($header as $index => $label) {
            $label = trim((string) $label);
            if ($label !== '') {
                $headers[$index] = $label;
            }
        }

        $out = [];

        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber < $firstDataRow) {
                continue;
            }

            $mapped = [];
            $hasValue = false;

            foreach ($headers as $index => $label) {
                $value = $row[$index] ?? null;
                $mapped[$label] = $value;
                $hasValue = $hasValue || ! $this->blank($value);
            }

            if ($hasValue) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /** @return array<int, array<int, mixed>> */
    public function rows(string $sheetName): array
    {
        $path = $this->sheetPaths[$sheetName] ?? null;

        if (! $path) {
            throw new RuntimeException("Hoja no encontrada: {$sheetName}");
        }

        $xml = $this->xml($path);
        $rows = [];
        $main = $xml->children(self::NS_MAIN);

        foreach ($main->sheetData->row as $rowNode) {
            $rowAttributes = $rowNode->attributes();
            $rowIndex = (int) $rowAttributes['r'];
            $row = [];

            foreach ($rowNode->children(self::NS_MAIN)->c as $cell) {
                $cellAttributes = $cell->attributes();
                $ref = (string) $cellAttributes['r'];
                $columnIndex = $this->columnIndex($ref);
                $row[$columnIndex] = $this->cellValue($cell);
            }

            if ($row !== []) {
                $rows[$rowIndex] = $row;
            }
        }

        return $rows;
    }

    private function loadSharedStrings(): void
    {
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return;
        }

        $xml = $this->xml('xl/sharedStrings.xml');
        $main = $xml->children(self::NS_MAIN);

        $index = 0;

        foreach ($main->si as $item) {
            $texts = [];
            $itemMain = $item->children(self::NS_MAIN);

            if (isset($itemMain->t)) {
                $texts[] = (string) $itemMain->t;
            }

            foreach ($itemMain->r as $run) {
                $runMain = $run->children(self::NS_MAIN);
                if (isset($runMain->t)) {
                    $texts[] = (string) $runMain->t;
                }
            }

            $this->sharedStrings[$index] = implode('', $texts);
            $index++;
        }
    }

    private function loadDateStyles(): void
    {
        if ($this->zip->locateName('xl/styles.xml') === false) {
            return;
        }

        $xml = $this->xml('xl/styles.xml');
        $main = $xml->children(self::NS_MAIN);
        $customFormats = [];

        foreach ($main->numFmts->numFmt ?? [] as $format) {
            $attributes = $format->attributes();
            $customFormats[(int) $attributes['numFmtId']] = strtolower((string) $attributes['formatCode']);
        }

        $builtInDateIds = array_fill_keys([14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47], true);

        foreach ($main->cellXfs->xf ?? [] as $styleIndex => $style) {
            $attributes = $style->attributes();
            $numFmtId = (int) $attributes['numFmtId'];
            $formatCode = $customFormats[$numFmtId] ?? '';
            $isDate = isset($builtInDateIds[$numFmtId]) || (bool) preg_match('/[ymdhis]/', preg_replace('/"[^"]*"/', '', $formatCode));
            $this->dateStyles[(int) $styleIndex] = $isDate;
        }
    }

    private function loadSheetPaths(): void
    {
        $workbook = $this->xml('xl/workbook.xml');
        $rels = $this->xml('xl/_rels/workbook.xml.rels');
        $workbookMain = $workbook->children(self::NS_MAIN);
        $relsMain = $rels->children(self::NS_RELS);
        $targets = [];

        foreach ($relsMain->Relationship as $rel) {
            $attributes = $rel->attributes();
            $targets[(string) $attributes['Id']] = 'xl/'.ltrim((string) $attributes['Target'], '/');
        }

        foreach ($workbookMain->sheets->sheet as $sheet) {
            $attributes = $sheet->attributes();
            $relationshipAttributes = $sheet->attributes(self::NS_OFFICE_RELS);
            $name = (string) ($attributes['name'] ?? '');
            $rid = (string) ($relationshipAttributes['id'] ?? '');

            if ($name !== '' && isset($targets[$rid])) {
                $this->sheetPaths[$name] = $targets[$rid];
            }
        }
    }

    private function cellValue(SimpleXMLElement $cell): mixed
    {
        $attributes = $cell->attributes();
        $type = (string) ($attributes['t'] ?? '');
        $style = (string) ($attributes['s'] ?? '');

        if ($type === 'inlineStr') {
            $main = $cell->children(self::NS_MAIN);

            return isset($main->is->t) ? (string) $main->is->t : null;
        }

        if ($type === 's') {
            $main = $cell->children(self::NS_MAIN);
            $index = isset($main->v) ? (int) $main->v : -1;

            return $this->sharedStrings[$index] ?? null;
        }

        if ($type === 'b') {
            return ((string) $cell->v) === '1';
        }

        $main = $cell->children(self::NS_MAIN);

        if (! isset($main->v)) {
            return null;
        }

        $raw = (string) $main->v;

        if ($raw === '') {
            return null;
        }

        if ($style !== '' && ($this->dateStyles[(int) $style] ?? false) && is_numeric($raw)) {
            return $this->excelDate((float) $raw);
        }

        return is_numeric($raw) ? (float) $raw : $raw;
    }

    private function xml(string $path): SimpleXMLElement
    {
        $contents = $this->zip->getFromName($path);

        if ($contents === false) {
            throw new RuntimeException("Parte XLSX no encontrada: {$path}");
        }

        $xml = simplexml_load_string($contents);

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException("XML XLSX invalido: {$path}");
        }

        return $xml;
    }

    private function columnIndex(string $ref): int
    {
        preg_match('/^[A-Z]+/', strtoupper($ref), $matches);
        $letters = $matches[0] ?? 'A';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index;
    }

    private function excelDate(float $serial): string
    {
        $seconds = (int) round(($serial - 25569) * 86400);

        return gmdate('Y-m-d', $seconds);
    }

    private function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
