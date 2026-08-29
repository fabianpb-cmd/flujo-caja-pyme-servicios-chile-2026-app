<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\TimeEntry;
use App\Services\Import\FinanceExcelImporter;
use App\Services\Import\XlsxWorkbookReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;
use ZipArchive;

class FinanceExcelImporterTimeEntryBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_importer_generates_and_preserves_period_batch_ids_for_productive_time_entries(): void
    {
        $company = Company::query()->create([
            'code' => 'CMP-IMPORT-TIME',
            'name' => 'Empresa Importador Horas',
            'status' => 'active',
        ]);

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-IMPORT-TIME',
            'legal_name' => 'Cliente Importador Horas',
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-IMPORT-TIME',
            'name' => 'Proyecto Importador Horas',
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-IMPORT-TIME',
            'first_names' => 'Irma',
            'paternal_surname' => 'Importa',
            'name' => 'Irma Importa',
            'modality' => 'Dependiente mensual',
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-IMPORT-TIME',
        ]);

        $preservedBatchId = (string) Str::uuid();
        TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HOR-IMPORT-KEEP',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'period_batch_id' => $preservedBatchId,
            'entry_date' => '2026-08-04',
            'activity' => 'Actividad anterior',
            'hours_worked' => 1,
            'hours_approved' => 1,
            'hourly_value' => 1000,
            'payment_status' => 'pending',
        ]);

        $path = $this->hoursWorkbookPath([
            [
                'ID Registro' => 'HOR-IMPORT-KEEP',
                'ID Persona' => 'PER-IMPORT-TIME',
                'ID Cliente' => 'CLI-IMPORT-TIME',
                'ID Proyecto lógico' => 'PRY-IMPORT-TIME',
                'Fecha' => '2026-08-04',
                'Actividad' => 'Actividad actualizada',
                'Horas trabajadas' => '4',
                'Horas aprobadas' => '4',
                'Valor hora ($)' => '2500',
                'Monto calculado ($)' => '10000',
                'Estado aprobación' => 'Aprobado',
                'Estado pago' => 'Pendiente',
                'Periodo pago' => '2026-08-01',
                'Centro costo' => 'Centro A',
                'Observaciones' => 'Actualizado',
            ],
            [
                'ID Registro' => 'HOR-IMPORT-NEW',
                'ID Persona' => 'PER-IMPORT-TIME',
                'ID Cliente' => 'CLI-IMPORT-TIME',
                'ID Proyecto lógico' => 'PRY-IMPORT-TIME',
                'Fecha' => '2026-08-05',
                'Actividad' => 'Actividad nueva',
                'Horas trabajadas' => '6',
                'Horas aprobadas' => '6',
                'Valor hora ($)' => '3000',
                'Monto calculado ($)' => '18000',
                'Estado aprobación' => 'Aprobado',
                'Estado pago' => 'Pendiente',
                'Periodo pago' => '2026-08-01',
                'Centro costo' => 'Centro B',
                'Observaciones' => 'Nuevo',
            ],
        ]);

        try {
            $importer = app(FinanceExcelImporter::class);
            $reflection = new ReflectionClass($importer);

            $this->setPrivateProperty($reflection, $importer, 'reader', new XlsxWorkbookReader($path));
            $this->setPrivateProperty($reflection, $importer, 'dryRun', false);
            $this->setPrivateProperty($reflection, $importer, 'company', $company);
            $this->setPrivateProperty($reflection, $importer, 'known', [
                'clients' => [$client->code => true],
                'projects' => [$project->code => true],
                'people' => [$person->code => true],
                'assignments' => [$assignment->code => true],
                'scenarios' => [],
                'cash_accounts' => [],
                'sales_documents' => [],
                'expense_documents' => [],
                'payroll_records' => [],
                'legal_obligations' => [],
            ]);
            $this->setPrivateProperty($reflection, $importer, 'report', [
                'source' => $path,
                'dry_run' => false,
                'generated_at' => now()->toIso8601String(),
                'sheets' => [
                    'horas' => [
                        'leidos' => 0,
                        'validos' => 0,
                        'insertados' => 0,
                        'omitidos' => 0,
                        'warnings' => [],
                        'errores' => [],
                    ],
                ],
                'qa' => [],
            ]);

            $method = $reflection->getMethod('importTimeEntries');
            $method->setAccessible(true);
            $method->invoke($importer);
        } finally {
            @unlink($path);
        }

        $updated = TimeEntry::query()->where('company_id', $company->id)->where('code', 'HOR-IMPORT-KEEP')->firstOrFail();
        $created = TimeEntry::query()->where('company_id', $company->id)->where('code', 'HOR-IMPORT-NEW')->firstOrFail();

        $this->assertSame($preservedBatchId, $updated->period_batch_id);
        $this->assertSame($assignment->id, $updated->assignment_id);
        $this->assertSame(4.0, (float) $updated->hours_worked);
        $this->assertSame(2500.0, (float) $updated->hourly_value);

        $this->assertNotNull($created->period_batch_id);
        $this->assertNotSame($preservedBatchId, $created->period_batch_id);
        $this->assertSame($assignment->id, $created->assignment_id);
        $this->assertSame(6.0, (float) $created->hours_worked);
    }

    private function setPrivateProperty(ReflectionClass $reflection, object $target, string $property, mixed $value): void
    {
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($target, $value);
    }

    private function hoursWorkbookPath(array $rows): string
    {
        $headers = [
            'ID Registro',
            'ID Persona',
            'ID Cliente',
            'ID Proyecto lógico',
            'Fecha',
            'Actividad',
            'Horas trabajadas',
            'Horas aprobadas',
            'Valor hora ($)',
            'Monto calculado ($)',
            'Estado aprobación',
            'Estado pago',
            'Periodo pago',
            'Centro costo',
            'Observaciones',
        ];

        $sheetRows = [
            5 => $headers,
        ];

        foreach (array_values($rows) as $index => $row) {
            $sheetRows[6 + $index] = array_map(
                fn (string $header): string => (string) ($row[$header] ?? ''),
                $headers
            );
        }

        $sheetXmlRows = [];
        foreach ($sheetRows as $rowNumber => $values) {
            $cells = [];
            foreach (array_values($values) as $index => $value) {
                $cells[] = sprintf(
                    '<c r="%s%d" t="inlineStr"><is><t>%s</t></is></c>',
                    $this->columnLetter($index + 1),
                    $rowNumber,
                    htmlspecialchars($value, ENT_XML1)
                );
            }

            $sheetXmlRows[] = sprintf('<row r="%d">%s</row>', $rowNumber, implode('', $cells));
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.implode('', $sheetXmlRows).'</sheetData>'
            .'</worksheet>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="07_Horas" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';

        $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';

        $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';

        $path = tempnam(sys_get_temp_dir(), 'hours-import-');
        if ($path === false) {
            $this->fail('No fue posible crear un archivo temporal para el workbook de prueba.');
        }

        $xlsxPath = $path.'.xlsx';
        @unlink($path);

        $zip = new ZipArchive();
        $opened = $zip->open($xlsxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true, 'No fue posible crear el XLSX de prueba.');

        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', $rootRelsXml);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        return $xlsxPath;
    }

    private function columnLetter(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)).$letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }
}
