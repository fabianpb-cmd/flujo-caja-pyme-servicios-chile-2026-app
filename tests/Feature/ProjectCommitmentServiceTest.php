<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\EmploymentMode;
use App\Models\LegalParameter;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\RecordStatus;
use App\Models\UfValue;
use App\Services\CatalogService;
use App\Services\HourlyRateService;
use App\Services\PayrollService;
use App\Services\ProjectCommitmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectCommitmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Client $client;
    private Currency $clp;
    private Currency $uf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'code' => 'CMP-COMMIT',
            'name' => 'Empresa Compromiso',
            'status' => 'active',
        ]);

        app(CatalogService::class)->seedDefaultsForCompany($this->company->id);

        $this->clp = $this->currency('CLP', 'Peso chileno');
        $this->uf = $this->currency('UF', 'Unidad de Fomento');

        $this->client = Client::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CLI-COMMIT',
            'legal_name' => 'Cliente Compromiso',
            'client_status_id' => $this->statusId('client', 'active'),
        ]);

        LegalParameter::query()->create([
            'company_id' => $this->company->id,
            'parameter_code' => 'RETENCION_HONORARIOS',
            'parameter_name' => 'Retención honorarios',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'value' => 0.1525,
            'unit' => '%',
        ]);

        foreach (['2026-08-01', '2026-09-01'] as $date) {
            UfValue::query()->create([
                'company_id' => $this->company->id,
                'value_date' => $date,
                'value' => 40844.79,
            ]);
        }
    }

    public function test_hourly_assignment_projects_committed_cost_from_monthly_hours(): void
    {
        $project = $this->project(['sale_net' => 1000000]);
        $person = $this->person('PAGO_POR_HORA', ['name' => 'Persona Hora A']);
        $assignment = $this->assignment($person, $project, [
            'hourly_value' => 1000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
            'monthly_hours' => 80,
        ]);

        $summary = app(ProjectCommitmentService::class)->summarizeProject($project);
        $expected = (float) app(PayrollService::class)->calculate($person, '2026-08-01', [
            'project_id' => $project->id,
            'hours_approved' => 80,
            'hourly_value' => 1000,
        ])['employer_cost'];

        $this->assertTrue($summary['calculation_complete']);
        $this->assertSame(round($expected, 2), $summary['personnel_committed_cost']);
        $this->assertSame(round(1000000 - $expected, 2), $summary['projected_personnel_margin']);
        $this->assertSame(round(($expected / 1000000) * 100, 1), $summary['committed_percentage']);
        $this->assertSame(1, $summary['assignment_count']);
        $this->assertEmpty($summary['warnings']);
    }

    public function test_multiple_assignments_use_each_effective_hourly_rate_including_project_fallback(): void
    {
        $project = $this->project([
            'sale_net' => 2000000,
            'contracted_hourly_rate' => 1500,
            'sales_currency_id' => $this->clp->id,
        ]);

        $personA = $this->person('PAGO_POR_HORA', ['name' => 'Persona A']);
        $personB = $this->person('PAGO_POR_HORA', ['name' => 'Persona B']);

        $assignmentA = $this->assignment($personA, $project, [
            'hourly_value' => 1200,
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
            'monthly_hours' => 40,
        ]);
        $assignmentB = $this->assignment($personB, $project, [
            'hourly_value' => null,
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
            'monthly_hours' => 25,
        ]);

        $summary = app(ProjectCommitmentService::class)->summarizeProject($project);

        $expectedA = (float) app(PayrollService::class)->calculate($personA, '2026-08-01', [
            'project_id' => $project->id,
            'hours_approved' => 40,
            'hourly_value' => 1200,
        ])['employer_cost'];
        $expectedB = (float) app(PayrollService::class)->calculate($personB, '2026-08-01', [
            'project_id' => $project->id,
            'hours_approved' => 25,
            'hourly_value' => (float) app(HourlyRateService::class)->resolveProjectRate($project, '2026-08-01'),
        ])['employer_cost'];

        $this->assertTrue($summary['calculation_complete']);
        $this->assertSame(round($expectedA + $expectedB, 2), $summary['personnel_committed_cost']);
        $this->assertSame(2, $summary['assignment_count']);

        $breakdown = collect($summary['assignments'])->keyBy('assignment_id');
        $this->assertSame(round($expectedA, 2), $breakdown[$assignmentA->id]['committed_cost']);
        $this->assertSame(round($expectedB, 2), $breakdown[$assignmentB->id]['committed_cost']);
    }

    public function test_project_modality_uses_project_value_once_without_adding_hourly_rate(): void
    {
        $project = $this->project(['sale_net' => 500000]);
        $person = $this->person('POR_PROYECTO', ['name' => 'Persona Proyecto']);
        $assignment = $this->assignment($person, $project, [
            'hourly_value' => 9999,
            'project_value' => 100000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
            'monthly_hours' => 999,
        ]);

        $summary = app(ProjectCommitmentService::class)->summarizeProject($project);
        $expected = (float) app(PayrollService::class)->calculate($person, '2026-08-01', [
            'project_id' => $project->id,
            'project_value' => 100000,
        ])['employer_cost'];

        $this->assertTrue($summary['calculation_complete']);
        $this->assertSame(round($expected, 2), $summary['personnel_committed_cost']);
        $this->assertNotSame(round($expected + 9999, 2), $summary['personnel_committed_cost']);
        $this->assertSame($assignment->id, $summary['assignments'][0]['assignment_id']);
    }

    public function test_summary_reports_zero_and_negative_personnel_margin_cases(): void
    {
        $projectExact = $this->project(['code' => 'PRY-EQUAL', 'name' => 'Proyecto Equal', 'sale_net' => 80000]);
        $personExact = $this->person('PAGO_POR_HORA', ['name' => 'Persona Equal']);
        $this->assignment($personExact, $projectExact, [
            'hourly_value' => 1000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
            'monthly_hours' => 80,
        ]);

        $equal = app(ProjectCommitmentService::class)->summarizeProject($projectExact);
        $this->assertTrue($equal['calculation_complete']);
        $this->assertSame(0.0, $equal['projected_personnel_margin']);
        $this->assertFalse($equal['negative_margin']);

        $projectNegative = $this->project(['code' => 'PRY-NEG', 'name' => 'Proyecto Negativo', 'sale_net' => 50000]);
        $personNegative = $this->person('PAGO_POR_HORA', ['name' => 'Persona Negativa']);
        $this->assignment($personNegative, $projectNegative, [
            'hourly_value' => 1000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
            'monthly_hours' => 80,
        ]);

        $negative = app(ProjectCommitmentService::class)->summarizeProject($projectNegative);

        $this->assertTrue($negative['calculation_complete']);
        $this->assertTrue($negative['negative_margin']);
        $this->assertSame(30000.0, $negative['negative_margin_amount']);
        $this->assertContains('El costo de personal comprometido supera la venta neta del proyecto.', $negative['warnings']);
    }

    public function test_preview_assignment_excludes_current_assignment_when_editing(): void
    {
        $project = $this->project(['sale_net' => 500000]);
        $personA = $this->person('PAGO_POR_HORA', ['name' => 'Persona Edit A']);
        $personB = $this->person('PAGO_POR_HORA', ['name' => 'Persona Edit B']);

        $assignmentA = $this->assignment($personA, $project, [
            'hourly_value' => 1000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
            'monthly_hours' => 80,
        ]);
        $assignmentB = $this->assignment($personB, $project, [
            'hourly_value' => 1000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
            'monthly_hours' => 50,
        ]);

        $preview = app(ProjectCommitmentService::class)->previewAssignment($assignmentB, $assignmentB->id);

        $expectedA = (float) app(PayrollService::class)->calculate($personA, '2026-08-01', [
            'project_id' => $project->id,
            'hours_approved' => 80,
            'hourly_value' => 1000,
        ])['employer_cost'];
        $expectedB = (float) app(PayrollService::class)->calculate($personB, '2026-08-01', [
            'project_id' => $project->id,
            'hours_approved' => 50,
            'hourly_value' => 1000,
        ])['employer_cost'];

        $this->assertSame(round($expectedA, 2), $preview['current_personnel_committed_cost']);
        $this->assertSame(round($expectedB, 2), $preview['assignment_estimated_cost']);
        $this->assertSame(round($expectedA + $expectedB, 2), $preview['after_save_personnel_committed_cost']);
    }

    public function test_missing_conversion_marks_calculation_incomplete_without_fake_zero(): void
    {
        UfValue::query()->where('company_id', $this->company->id)->delete();

        $project = $this->project(['sale_net' => 1000000]);
        $person = $this->person('PAGO_POR_HORA', ['name' => 'Persona UF']);
        $this->assignment($person, $project, [
            'hourly_value' => 0.50,
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $this->uf->id,
            'monthly_hours' => 20,
        ]);

        $summary = app(ProjectCommitmentService::class)->summarizeProject($project);

        $this->assertFalse($summary['calculation_complete']);
        $this->assertNull($summary['personnel_committed_cost']);
        $this->assertNull($summary['projected_personnel_margin']);
        $this->assertNotEmpty($summary['warnings']);
    }

    public function test_monthly_commitment_is_incomplete_when_cost_cannot_be_allocated_unambiguously(): void
    {
        $projectA = $this->project(['code' => 'PRY-MTH-A', 'name' => 'Proyecto Mensual A', 'sale_net' => 900000]);
        $projectB = $this->project(['code' => 'PRY-MTH-B', 'name' => 'Proyecto Mensual B', 'sale_net' => 900000]);
        $person = $this->person('HONORARIOS_MENSUAL', [
            'name' => 'Persona Mensual',
            'monthly_value' => 500000,
        ]);

        $this->assignment($person, $projectA, [
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
        ]);
        $this->assignment($person, $projectB, [
            'code' => 'ASI-MTH-B',
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
        ]);

        $summary = app(ProjectCommitmentService::class)->summarizeProject($projectA);

        $this->assertFalse($summary['calculation_complete']);
        $this->assertNull($summary['personnel_committed_cost']);
        $this->assertStringContainsString('no puede distribuir el costo mensual de forma inequívoca', implode(' ', $summary['warnings']));
    }

    private function project(array $overrides = []): Project
    {
        return Project::query()->create(array_merge([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'sales_currency_id' => $this->clp->id,
            'code' => 'PRY-'.uniqid(),
            'name' => 'Proyecto '.uniqid(),
            'sale_net' => 1000000,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'project_status_id' => $this->statusId('project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId('billing', 'pending'),
        ], $overrides));
    }

    private function person(string $modeCode, array $overrides = []): Person
    {
        $modality = match ($modeCode) {
            'PAGO_POR_HORA' => 'Pago por hora',
            'POR_PROYECTO' => 'Por proyecto',
            'HONORARIOS_MENSUAL' => 'Honorarios mensual',
            default => 'Dependiente mensual',
        };

        return Person::query()->create(array_merge([
            'company_id' => $this->company->id,
            'code' => 'PER-'.uniqid(),
            'name' => 'Persona '.uniqid(),
            'modality' => $modality,
            'employment_mode_id' => $this->employmentModeId($modeCode),
            'worker_status_id' => $this->statusId('worker', 'active'),
            'monthly_value' => 0,
            'hourly_value' => 0,
            'status' => 'active',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ], $overrides));
    }

    private function assignment(Person $person, Project $project, array $overrides = []): ProjectAssignment
    {
        return ProjectAssignment::query()->create(array_merge([
            'company_id' => $this->company->id,
            'client_id' => $project->client_id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'assignment_status_id' => $this->statusId('assignment', 'active'),
            'code' => 'ASI-'.uniqid(),
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
            'hourly_value' => null,
            'project_value' => null,
            'monthly_hours' => null,
        ], $overrides));
    }

    private function statusId(string $domain, string $code): int
    {
        return RecordStatus::query()
            ->where('company_id', $this->company->id)
            ->where('domain', $domain)
            ->where('code', $code)
            ->valueOrFail('id');
    }

    private function employmentModeId(string $code): int
    {
        return EmploymentMode::query()
            ->where('company_id', $this->company->id)
            ->where('code', $code)
            ->valueOrFail('id');
    }

    private function currency(string $code, string $name): Currency
    {
        return Currency::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'code' => $code],
            [
                'name' => $name,
                'symbol' => match ($code) {
                    'CLP' => '$',
                    'UF' => 'UF',
                    'USD' => 'US$',
                    default => $code,
                },
                'minor_units' => $code === 'CLP' ? 0 : 2,
                'active' => true,
                'sort_order' => 100,
            ]
        );
    }
}
