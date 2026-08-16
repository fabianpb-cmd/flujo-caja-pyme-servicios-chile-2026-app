<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Company;
use App\Models\Person;
use App\Models\RecordStatus;
use App\Models\Region;
use App\Models\User;
use App\Services\CatalogService;
use App\Support\ChileanRut;
use Database\Seeders\ChileGeographySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeopleMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_chile_geography_seeder_is_idempotent(): void
    {
        $seeder = app(ChileGeographySeeder::class);
        $seeder->run();
        $firstRegionCount = Region::count();
        $firstCommuneCount = Commune::count();

        $seeder->run();

        $this->assertSame(16, $firstRegionCount);
        $this->assertSame(346, $firstCommuneCount);
        $this->assertSame($firstRegionCount, Region::count());
        $this->assertSame($firstCommuneCount, Commune::count());
        $this->assertSame(1, Region::query()->where('code', 'CL-RM')->count());
    }

    public function test_people_form_and_index_use_chilean_profile_fields(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        app(ChileGeographySeeder::class)->run();

        $response = $this->actingAs($admin)->get(route('operational.create', 'people'));

        $response->assertOk();
        $response->assertSee('¿Cómo completar la ficha de Personal?', false);
        $response->assertSee('Datos personales', false);
        $response->assertSee('Dirección', false);
        $response->assertSee('data-parent-field="region_id"', false);
        $response->assertSee('placeholder="12.345.678-5"', false);
        $response->assertSee('placeholder="+56 9 1234 5678"', false);
        $response->assertSee('type="tel"', false);
        $response->assertSee('data-bs-title="Puede ingresarlo con o sin puntos. El sistema valida automáticamente el dígito verificador."', false);
        $response->assertSee('data-bs-title="Unidad en que se pactó la tarifa por hora. Puede ser UF o una moneda habilitada."', false);
    }

    public function test_people_crud_validates_rut_and_commune_dependency(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        app(ChileGeographySeeder::class)->run();

        $rm = Region::query()->where('code', 'CL-RM')->firstOrFail();
        $tarapaca = Region::query()->where('code', 'CL-TA')->firstOrFail();
        $lasCondes = Commune::query()->where('region_id', $rm->id)->where('name', 'Las Condes')->firstOrFail();
        $iquique = Commune::query()->where('region_id', $tarapaca->id)->where('name', 'Iquique')->firstOrFail();
        $rut = '12345678-'.ChileanRut::checkDigit('12345678');

        $payload = $this->personPayload($company->id, $rm->id, $lasCondes->id, $rut, 'PER-001');
        $response = $this->actingAs($admin)->post(route('operational.store', 'people'), $payload);
        $response->assertRedirect(route('operational.index', 'people'));

        $person = Person::query()->where('company_id', $company->id)->where('rut', $rut)->firstOrFail();
        $this->assertSame('Juan Pérez', $person->full_name);
        $this->assertSame('Juan Pérez', $person->name);

        $duplicate = $this->actingAs($admin)->post(route('operational.store', 'people'), $this->personPayload($company->id, $rm->id, $lasCondes->id, $rut, 'PER-002'));
        $duplicate->assertSessionHasErrors('rut');

        $invalidRut = $this->actingAs($admin)->post(route('operational.store', 'people'), $this->personPayload($company->id, $rm->id, $lasCondes->id, '12345678-4', 'PER-004'));
        $invalidRut->assertSessionHasErrors([
            'rut' => 'El RUT ingresado no es válido. Revise el número y dígito verificador.',
        ]);

        $invalidCommune = $this->actingAs($admin)->post(route('operational.store', 'people'), $this->personPayload($company->id, $rm->id, $iquique->id, '11111111-'.ChileanRut::checkDigit('11111111'), 'PER-003'));
        $invalidCommune->assertSessionHasErrors('commune_id');

        $person = Person::query()->where('company_id', $company->id)->where('rut', $rut)->firstOrFail();
        $editInvalid = $this->actingAs($admin)->put(route('operational.update', ['people', $person->id]), array_merge($this->personPayload($company->id, $rm->id, $lasCondes->id, $rut, 'PER-001'), ['rut' => '12345678-4']));
        $editInvalid->assertSessionHasErrors('rut');
    }

    public function test_people_form_is_accessible_to_company_users(): void
    {
        [$company] = $this->companyWithAdmin();

        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Operador',
            'email' => 'operador-people@test.local',
            'password' => 'password',
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($user)->get(route('operational.index', 'people'))->assertOk();
        $this->actingAs($user)->get(route('operational.create', 'people'))->assertOk();
    }

    public function test_legacy_name_can_be_backfilled_into_first_names(): void
    {
        [$company] = $this->companyWithAdmin();

        Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-LEG',
            'name' => 'María López',
            'modality' => 'Dependiente mensual',
            'status' => 'active',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->workerStatusId($company->id, 'active'),
        ]);

        app(CatalogService::class)->backfillCompany($company->id);

        $person = Person::query()->where('code', 'PER-LEG')->firstOrFail();
        $this->assertSame('María López', $person->first_names);
    }

    private function companyWithAdmin(): array
    {
        $company = Company::query()->create(['code' => 'CMP-PPL', 'name' => 'Empresa Personal', 'status' => 'active']);
        app(CatalogService::class)->seedDefaultsForCompany($company->id);
        app(ChileGeographySeeder::class)->run();

        $admin = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Admin Personal',
            'email' => uniqid('people').'@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        return [$company, $admin];
    }

    private function employmentModeId(int $companyId, string $code): int
    {
        return \App\Models\EmploymentMode::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->valueOrFail('id');
    }

    private function workerStatusId(int $companyId, string $code): int
    {
        return RecordStatus::query()
            ->where('company_id', $companyId)
            ->where('domain', 'worker')
            ->where('code', $code)
            ->valueOrFail('id');
    }

    private function personPayload(int $companyId, int $regionId, int $communeId, string $rut, string $code): array
    {
        return [
            'code' => $code,
            'first_names' => 'Juan',
            'paternal_surname' => 'Pérez',
            'maternal_surname' => null,
            'rut' => $rut,
            'birth_date' => '1990-01-15',
            'nationality' => 'Chilena',
            'email' => 'juan.'.$rut.'@test.local',
            'phone_country_code' => '+56',
            'phone_number' => '912345678',
            'secondary_phone' => '223334444',
            'region_id' => $regionId,
            'commune_id' => $communeId,
            'address_street' => 'Av. Apoquindo',
            'address_number' => '4501',
            'address_unit' => '1203',
            'postal_code' => '7550000',
            'address_reference' => 'Cerca del metro',
            'position_id' => null,
            'employment_mode_id' => $this->employmentModeId($companyId, 'DEPENDIENTE_MENSUAL'),
            'employment_contract_type_id' => null,
            'afp_id' => null,
            'health_system_id' => null,
            'monthly_value' => 1000000,
            'hourly_value' => 12500,
            'monthly_hours' => 180,
            'worker_status_id' => $this->workerStatusId($companyId, 'active'),
            'start_date' => '2026-08-01',
            'end_date' => null,
            'bank_id' => null,
            'bank_account_type_id' => null,
            'bank_account_number' => null,
            'bank_account_holder_rut' => null,
            'emergency_contact_name' => 'Ana López',
            'emergency_contact_relationship' => 'Madre',
            'emergency_contact_phone' => '912000000',
        ];
    }
}
