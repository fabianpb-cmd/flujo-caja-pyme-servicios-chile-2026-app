<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\UfValue;
use App\Models\User;
use App\Services\LegalParameterService;
use App\Services\UfCsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UfCsvImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sii_csv_parses_values_upserts_idempotently_and_preserves_absent_dates(): void
    {
        [$company] = $this->companyAndAdmin('UF-A');
        UfValue::query()->create(['company_id' => $company->id, 'value_date' => '2026-12-01', 'value' => 42000, 'source' => 'manual', 'notes' => 'No borrar']);
        $csv = "Día;Ene;Feb;Mar;Abr;May;Jun;Jul;Ago;Sep;Oct;Nov;Dic\n12;;;;;;;;;40.910,10;;;\n9;;;;;;;;;;41.130,94;;\n";

        $first = app(UfCsvImportService::class)->import(UploadedFile::fake()->createWithContent('uf.csv', $csv), $company->id, 2026);
        $second = app(UfCsvImportService::class)->import(UploadedFile::fake()->createWithContent('uf.csv', $csv), $company->id, 2026);

        $this->assertSame(2, $first['processed']);
        $this->assertSame(2, $first['created']);
        $this->assertSame(2, $second['unchanged']);
        $this->assertSame('40910.1000', UfValue::query()->where('company_id', $company->id)->whereDate('value_date', '2026-09-12')->value('value'));
        $this->assertSame('41130.9400', UfValue::query()->where('company_id', $company->id)->whereDate('value_date', '2026-10-09')->value('value'));
        $this->assertSame('SII', UfValue::query()->where('company_id', $company->id)->whereDate('value_date', '2026-09-12')->value('source'));
        $this->assertTrue((bool) UfValue::query()->where('company_id', $company->id)->whereDate('value_date', '2026-09-12')->value('active'));
        $this->assertSame('No borrar', UfValue::query()->where('company_id', $company->id)->whereDate('value_date', '2026-12-01')->value('notes'));
        $this->assertSame('40910.1000', app(LegalParameterService::class)->ufValueExact($company->id, '2026-09-12'));
    }

    public function test_existing_value_is_updated_and_import_is_tenant_scoped(): void
    {
        [$company] = $this->companyAndAdmin('UF-B');
        $other = Company::query()->create(['code' => 'UF-OTHER', 'name' => 'Otra', 'status' => 'active']);
        UfValue::query()->create(['company_id' => $other->id, 'value_date' => '2026-09-12', 'value' => 1, 'source' => 'manual', 'active' => true]);
        UfValue::query()->create(['company_id' => $company->id, 'value_date' => '2026-09-12', 'value' => 1, 'source' => 'manual', 'active' => true]);

        $csv = "Día;Ene;Feb;Mar;Abr;May;Jun;Jul;Ago;Sep;Oct;Nov;Dic\n12;;;;;;;;;40.910,10;;;\n";
        app(UfCsvImportService::class)->import(UploadedFile::fake()->createWithContent('uf.csv', $csv), $company->id, 2026);

        $this->assertSame('40910.1000', UfValue::query()->where('company_id', $company->id)->value('value'));
        $this->assertSame('1.0000', UfValue::query()->where('company_id', $other->id)->value('value'));
    }

    public function test_invalid_csv_and_impossible_date_roll_back_without_partial_rows(): void
    {
        [$company] = $this->companyAndAdmin('UF-C');
        $service = app(UfCsvImportService::class);
        $badHeader = UploadedFile::fake()->createWithContent('bad.csv', "Día;Ene\n1;1,00\n");
        $this->expectException(\DomainException::class);
        $service->import($badHeader, $company->id, 2026);
        $this->assertDatabaseCount('uf_values', 0);
    }

    public function test_impossible_date_rolls_back_and_admin_route_requires_admin(): void
    {
        [$company, $admin] = $this->companyAndAdmin('UF-D');
        $csv = "Día;Ene;Feb;Mar;Abr;May;Jun;Jul;Ago;Sep;Oct;Nov;Dic\n30;;40.000,00;;;;;;;;;;\n";
        $response = $this->actingAs($admin)->post(route('operational.uf-values.import'), ['year' => 2026, 'file' => UploadedFile::fake()->createWithContent('uf.csv', $csv)]);
        $response->assertSessionHasErrors('uf_import');
        $this->assertDatabaseCount('uf_values', 0);

        $nonAdmin = User::query()->create(['company_id' => $company->id, 'name' => 'Operador', 'email' => 'uf-operator@example.test', 'password' => 'password', 'role' => 'operator', 'active' => true]);
        $this->actingAs($nonAdmin)->post(route('operational.uf-values.import'), ['year' => 2026, 'file' => UploadedFile::fake()->createWithContent('uf.csv', "Día;Ene;Feb;Mar;Abr;May;Jun;Jul;Ago;Sep;Oct;Nov;Dic\n1;40.000,00;;;;;;;;;;;\n")])->assertForbidden();
    }

    private function companyAndAdmin(string $code): array
    {
        $company = Company::query()->create(['code' => $code, 'name' => $code, 'status' => 'active']);
        $admin = User::query()->create(['company_id' => $company->id, 'name' => 'Admin UF', 'email' => strtolower($code) . '@example.test', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        return [$company, $admin];
    }
}
