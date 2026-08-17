<?php

namespace Tests\Feature;

use App\Models\Afp;
use App\Models\AfpRate;
use App\Models\Client;
use App\Models\Company;
use App\Models\ContractType;
use App\Models\LegalParameter;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\UfValue;
use App\Models\User;
use App\Services\PayrollBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollBatchGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private int $contractTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['code' => 'CMP-PAY-BATCH', 'name' => 'Empresa Batch', 'status' => 'active']);
        $this->admin = User::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Admin Batch',
            'email' => 'batch@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        $this->seed(\Database\Seeders\IncomeTaxBracketSeeder::class);
        $this->contractTypeId = ContractType::query()->create([
            'company_id' => $this->company->id,
            'domain' => 'employment',
            'code' => 'INDEFINIDO',
            'name' => 'Indefinido',
            'active' => true,
        ])->id;

        $this->seedAfp();
        foreach (['2026-08-01', '2026-08-15'] as $date) {
            UfValue::query()->create(['company_id' => $this->company->id, 'value_date' => $date, 'value' => 40844.79]);
        }
        $this->seedLegal();
    }

    public function test_dependent_valid_all_month_is_generated_as_draft(): void
    {
        $person = $this->person(['monthly_value' => 1000000]);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $this->assertSame(1, $summary['generated']);
        $this->assertSame('Borrador', $record->status);
        $this->assertSame(31, $record->worked_days);
        $this->assertSame(1000000.0, (float) $record->base_salary);
    }

    public function test_mid_month_start_is_generated_proportionally(): void
    {
        $person = $this->person(['monthly_value' => 900000, 'start_date' => '2026-08-16']);

        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $this->assertSame(16, $record->worked_days);
        $this->assertSame(480000.0, (float) $record->base_salary);
    }

    public function test_mid_month_end_is_generated_proportionally(): void
    {
        $person = $this->person(['monthly_value' => 900000, 'end_date' => '2026-08-15']);

        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $this->assertSame(15, $record->worked_days);
        $this->assertSame(450000.0, (float) $record->base_salary);
    }

    public function test_person_ended_before_period_is_omitted(): void
    {
        $this->person(['end_date' => '2026-07-31']);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $this->assertSame(0, $summary['evaluated']);
        $this->assertSame(0, PayrollRecord::query()->count());
    }

    public function test_honorarios_are_generated_with_retention_without_dependent_charges(): void
    {
        $person = $this->person(['modality' => 'Honorarios mensual', 'monthly_value' => 100000]);

        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $this->assertSame(15250.0, (float) $record->employee_retention);
        $this->assertSame(84750.0, (float) $record->net_pay);
        $this->assertSame(0.0, (float) $record->afp_mandatory);
        $this->assertSame(0.0, (float) $record->employer_cost - (float) $record->gross_amount);
    }

    public function test_generation_is_idempotent_and_recalculates_existing_draft(): void
    {
        $person = $this->person(['monthly_value' => 1000000]);
        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');
        PayrollAdjustment::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'period_date' => '2026-08-01',
            'type' => 'BONUS_TAXABLE',
            'amount' => 50000,
            'active' => true,
        ]);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01', true);

        $this->assertSame(1, PayrollRecord::query()->where('person_id', $person->id)->count());
        $this->assertSame(1, $summary['updated']);
        $this->assertSame(1050000.0, (float) PayrollRecord::query()->where('person_id', $person->id)->firstOrFail()->gross_amount);
    }

    public function test_missing_payment_date_draft_is_recalculated_instead_of_being_omitted(): void
    {
        $person = $this->person(['monthly_value' => 1000000]);

        PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-BATCH-02',
            'person_id' => $person->id,
            'period_date' => '2026-08-01',
            'payment_date' => null,
            'gross_amount' => 1000,
            'net_pay' => 1000,
            'employer_cost' => 1000,
            'status' => 'Pendiente de fecha de pago',
        ]);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $this->assertSame(1, $summary['evaluated']);
        $this->assertSame(1, $summary['updated']);
        $this->assertSame(0, $summary['omitted']);
        $this->assertSame(1, PayrollRecord::query()->where('person_id', $person->id)->count());
    }

    public function test_confirmed_existing_payroll_is_not_replaced(): void
    {
        $person = $this->person(['monthly_value' => 1000000]);
        PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'period_date' => '2026-08-01',
            'status' => 'Confirmado',
            'gross_amount' => 123,
            'net_pay' => 123,
            'employer_cost' => 123,
        ]);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01', true);

        $this->assertSame(1, $summary['omitted']);
        $this->assertSame(123.0, (float) PayrollRecord::query()->where('person_id', $person->id)->firstOrFail()->net_pay);
    }

    public function test_missing_parameter_marks_one_record_for_review_without_blocking_others(): void
    {
        $this->person(['modality' => 'Honorarios mensual', 'monthly_value' => 100000]);
        $dependent = $this->person(['monthly_value' => 1000000]);
        UfValue::query()->where('company_id', $this->company->id)->delete();

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $this->assertSame(2, $summary['evaluated']);
        $this->assertSame(1, PayrollRecord::query()->where('status', 'Borrador')->count());
        $this->assertSame('Requiere revisión', PayrollRecord::query()->where('person_id', $dependent->id)->firstOrFail()->status);
    }

    public function test_project_is_preselected_only_when_single_assignment_is_valid_for_period(): void
    {
        $person = $this->person();
        $validProject = $this->project('PRY-VALID');
        $expiredProject = $this->project('PRY-OLD');
        $this->assignment($person, $validProject, '2026-08-01', null);
        $this->assignment($person, $expiredProject, '2026-01-01', '2026-07-31');

        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $this->assertSame($validProject->id, PayrollRecord::query()->where('person_id', $person->id)->firstOrFail()->project_id);
    }

    public function test_route_generates_period_and_returns_summary(): void
    {
        $this->person();

        $response = $this->actingAs($this->admin)->post(route('payroll.generate-period'), ['period' => '08/2026']);

        $response->assertRedirect(route('operational.index', ['resource' => 'payroll-records', 'period' => '08/2026']));
        $response->assertSessionHas('payroll_batch_summary');
        $this->assertSame(1, PayrollRecord::query()->count());
    }

    private function person(array $overrides = []): Person
    {
        return Person::query()->create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'Persona Batch '.uniqid(),
            'modality' => 'Dependiente mensual',
            'monthly_value' => 1000000,
            'hourly_value' => 0,
            'status' => 'active',
            'start_date' => '2026-01-01',
            'employment_contract_type_id' => $this->contractTypeId,
            'afp_id' => Afp::query()->where('code', 'HABITAT')->value('id'),
        ], $overrides));
    }

    private function project(string $code): Project
    {
        $client = Client::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'CLI-BATCH'],
            ['legal_name' => 'Cliente Batch']
        );

        return Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $client->id,
            'code' => $code,
            'name' => $code,
        ]);
    }

    private function assignment(Person $person, Project $project, string $start, ?string $end): ProjectAssignment
    {
        return ProjectAssignment::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'code' => 'ASI-'.uniqid(),
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'active',
        ]);
    }

    private function seedLegal(): void
    {
        foreach ([
            'RETENCION_HONORARIOS' => 0.1525,
            'AFP_TRABAJADOR' => 0.10,
            'SALUD_MINIMA' => 0.07,
            'AFC_TRABAJADOR_INDEFINIDO' => 0.006,
            'AFC_EMPLEADOR_INDEFINIDO' => 0.024,
            'AFC_EMPLEADOR_PLAZO_FIJO' => 0.03,
            'LEY_16744_BASICA' => 0.009,
            'LEY_16744_ADICIONAL' => 0,
            'SANNA_RATE' => 0.0003,
            'TOPE_IMPONIBLE_UF' => 90.0,
            'TOPE_AFC_UF' => 135.2,
            'COTIZACION_EMPLEADOR' => 0.035,
            'SIS_RATE' => 0,
        ] as $code => $value) {
            LegalParameter::query()->create([
                'company_id' => $this->company->id,
                'parameter_code' => $code,
                'parameter_name' => $code,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'value' => $value,
                'unit' => '%',
                'active' => true,
            ]);
        }
    }

    private function seedAfp(): void
    {
        $afp = Afp::query()->create(['code' => 'HABITAT', 'name' => 'Habitat', 'is_active' => true]);
        AfpRate::query()->create([
            'afp_id' => $afp->id,
            'valid_from' => '2026-01-01',
            'employee_commission_rate' => 0.0127,
            'employer_commission_rate' => 0,
            'insurance_rate' => 0,
        ]);
    }
}
