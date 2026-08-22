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

    public function test_commitment_uses_assignment_cost_rate_for_monthly_person_without_affecting_monthly_payroll(): void
    {
        $project = $this->project(['sale_net' => 1000000]);
        $person = $this->person('HONORARIOS_MENSUAL', [
            'name' => 'Persona Mensual',
            'monthly_value' => 900000,
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $this->uf->id,
            'hourly_value' => 0.50,
        ]);

        $assignment = $this->assignment($person, $project, [
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $this->uf->id,
            'hourly_value' => 0.70,
            'monthly_hours' => 10,
        ]);

        $summary = app(ProjectCommitmentService::class)->summarizeProject($project);
        $expectedCommitment = round(app(HourlyRateService::class)->resolveAssignmentRate($assignment, '2026-08-01') * 10, 2);

        $payrollWithoutProject = app(PayrollService::class)->calculate($person, '2026-08-01');
        $payrollWithProject = app(PayrollService::class)->calculate($person, '2026-08-01', ['project_id' => $project->id]);

        $this->assertTrue($summary['calculation_complete']);
        $this->assertSame($expectedCommitment, $summary['personnel_committed_cost']);
        $this->assertSame(round(1000000 - $expectedCommitment, 2), $summary['projected_personnel_margin']);
        $this->assertSame($payrollWithoutProject['gross_amount'], $payrollWithProject['gross_amount']);
        $this->assertSame($payrollWithoutProject['base_salary'], $payrollWithProject['base_salary']);
    }

    public function test_commitment_uses_assignment_cost_hours_while_project_modality_payroll_uses_project_value(): void
    {
        $project = $this->project(['sale_net' => 800000]);
        $person = $this->person('POR_PROYECTO', [
            'name' => 'Persona Proyecto',
            'hourly_value' => 0.40,
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $this->uf->id,
        ]);

        $assignment = $this->assignment($person, $project, [
            'hourly_value' => 0.60,
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $this->uf->id,
            'project_value' => 100,
            'monthly_hours' => 20,
        ]);

        $summary = app(ProjectCommitmentService::class)->summarizeProject($project);
        $expectedCommitment = round(app(HourlyRateService::class)->resolveAssignmentRate($assignment, '2026-08-01') * 20, 2);
        $expectedProjectValue = app(HourlyRateService::class)->resolveAssignmentProjectValue($assignment, '2026-08-01');
        $payroll = app(PayrollService::class)->calculate($person, '2026-08-01', ['project_id' => $project->id]);

        $this->assertTrue($summary['calculation_complete']);
        $this->assertSame($expectedCommitment, $summary['personnel_committed_cost']);
        $this->assertSame($expectedProjectValue, (float) $payroll['gross_amount']);
        $this->assertNotSame(round($expectedCommitment + $expectedProjectValue, 2), $summary['personnel_committed_cost']);
    }

    public function test_commitment_and_payroll_remain_separate_for_hourly_person(): void
    {
        $project = $this->project(['sale_net' => 1000000]);
        $person = $this->person('PAGO_POR_HORA', [
            'name' => 'Persona Hora',
            'hourly_value' => 0.40,
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $this->uf->id,
        ]);

        $assignment = $this->assignment($person, $project, [
            'hourly_value' => 0.65,
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $this->uf->id,
            'monthly_hours' => 15,
        ]);

        $summary = app(ProjectCommitmentService::class)->summarizeProject($project);
        $expectedCommitment = round(app(HourlyRateService::class)->resolveAssignmentRate($assignment, '2026-08-01') * 15, 2);
        $payroll = app(PayrollService::class)->calculate($person, '2026-08-01', ['project_id' => $project->id, 'hours_approved' => 10]);
        $expectedPayrollRate = app(HourlyRateService::class)->resolvePersonRate($person, '2026-08-01');

        $this->assertTrue($summary['calculation_complete']);
        $this->assertSame($expectedCommitment, $summary['personnel_committed_cost']);
        $this->assertSame(round($expectedPayrollRate * 10, 2), (float) $payroll['base_salary']);
        $this->assertNotSame(round(app(HourlyRateService::class)->resolveAssignmentRate($assignment, '2026-08-01') * 10, 2), (float) $payroll['base_salary']);
    }

    public function test_commitment_falls_back_to_person_cost_rate_when_assignment_rate_is_missing(): void
    {
        $project = $this->project(['sale_net' => 300000]);
        $person = $this->person('PAGO_POR_HORA', [
            'name' => 'Persona Fallback',
            'hourly_value' => 1000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
        ]);

        $assignment = $this->assignment($person, $project, [
            'hourly_value' => null,
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
            'monthly_hours' => 12,
        ]);

        $summary = app(ProjectCommitmentService::class)->summarizeProject($project);
        $expectedCommitment = round(app(HourlyRateService::class)->resolvePersonRate($person, '2026-08-01') * 12, 2);

        $this->assertTrue($summary['calculation_complete']);
        $this->assertSame($expectedCommitment, $summary['personnel_committed_cost']);
        $this->assertSame($assignment->id, $summary['assignments'][0]['assignment_id']);
    }

    public function test_commitment_is_incomplete_when_assignment_and_person_cost_rates_are_missing_even_if_project_has_contract_rate(): void
    {
        $project = $this->project([
            'sale_net' => 500000,
            'contracted_hourly_rate' => 35000,
            'sales_currency_id' => $this->clp->id,
        ]);
        $person = $this->person('PAGO_POR_HORA', [
            'name' => 'Persona Sin Valor HH',
            'hourly_value' => 0,
        ]);

        $this->assignment($person, $project, [
            'hourly_value' => null,
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
            'monthly_hours' => 12,
        ]);

        $summary = app(ProjectCommitmentService::class)->summarizeProject($project);

        $this->assertFalse($summary['calculation_complete']);
        $this->assertNull($summary['personnel_committed_cost']);
        $this->assertStringContainsString('falta el Valor HH de costeo de la Asignación y de la Persona', implode(' ', $summary['warnings']));
    }

    public function test_multiple_assignments_for_same_person_keep_project_specific_costing_rates(): void
    {
        $projectA = $this->project(['code' => 'PRY-COST-A', 'name' => 'Proyecto Costeo A', 'sale_net' => 500000]);
        $projectB = $this->project(['code' => 'PRY-COST-B', 'name' => 'Proyecto Costeo B', 'sale_net' => 500000]);
        $person = $this->person('PAGO_POR_HORA', [
            'name' => 'Persona Multi Proyecto',
            'hourly_value' => 0.50,
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $this->uf->id,
        ]);

        $assignmentA = $this->assignment($person, $projectA, [
            'hourly_value' => 0.70,
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $this->uf->id,
            'monthly_hours' => 10,
        ]);
        $assignmentB = $this->assignment($person, $projectB, [
            'hourly_value' => 0.90,
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $this->uf->id,
            'monthly_hours' => 10,
        ]);

        $summaryA = app(ProjectCommitmentService::class)->summarizeProject($projectA);
        $summaryB = app(ProjectCommitmentService::class)->summarizeProject($projectB);

        $this->assertSame(round(app(HourlyRateService::class)->resolveAssignmentRate($assignmentA, '2026-08-01') * 10, 2), $summaryA['personnel_committed_cost']);
        $this->assertSame(round(app(HourlyRateService::class)->resolveAssignmentRate($assignmentB, '2026-08-01') * 10, 2), $summaryB['personnel_committed_cost']);
        $this->assertNotSame($summaryA['personnel_committed_cost'], $summaryB['personnel_committed_cost']);
    }

    public function test_preview_assignment_excludes_current_assignment_when_editing(): void
    {
        $project = $this->project(['sale_net' => 500000]);
        $personA = $this->person('PAGO_POR_HORA', ['name' => 'Persona Edit A', 'hourly_value' => 1000, 'hourly_rate_currency_id' => $this->clp->id]);
        $personB = $this->person('PAGO_POR_HORA', ['name' => 'Persona Edit B', 'hourly_value' => 1000, 'hourly_rate_currency_id' => $this->clp->id]);

        $this->assignment($personA, $project, [
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

        $this->assertSame(80000.0, $preview['current_personnel_committed_cost']);
        $this->assertSame(50000.0, $preview['assignment_estimated_cost']);
        $this->assertSame(130000.0, $preview['after_save_personnel_committed_cost']);
    }

    public function test_negative_margin_warning_is_emitted_when_commitment_exceeds_sale_net(): void
    {
        $project = $this->project(['sale_net' => 50000]);
        $person = $this->person('PAGO_POR_HORA', [
            'name' => 'Persona Negativa',
            'hourly_value' => 1000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
        ]);

        $this->assignment($person, $project, [
            'hourly_value' => null,
            'monthly_hours' => 80,
        ]);

        $summary = app(ProjectCommitmentService::class)->summarizeProject($project);

        $this->assertTrue($summary['calculation_complete']);
        $this->assertTrue($summary['negative_margin']);
        $this->assertSame(30000.0, $summary['negative_margin_amount']);
        $this->assertContains('El costo de personal comprometido supera la venta neta del proyecto.', $summary['warnings']);
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
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $this->clp->id,
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
