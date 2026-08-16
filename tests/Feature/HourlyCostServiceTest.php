<?php

namespace Tests\Feature;

use App\Models\ApprovalStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\HourlyCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HourlyCostServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private Client $client;
    private Project $projectA;
    private Project $projectB;
    private int $approvedId;
    private int $rejectedId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['code' => 'CMP-HHCOST', 'name' => 'Empresa HH', 'status' => 'active']);
        $this->admin = User::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Admin HH',
            'email' => 'hh@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);
        $this->client = Client::query()->create(['company_id' => $this->company->id, 'code' => 'CLI-HH', 'legal_name' => 'Cliente HH']);
        $this->projectA = Project::query()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'code' => 'PRY-A', 'name' => 'Proyecto A']);
        $this->projectB = Project::query()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'code' => 'PRY-B', 'name' => 'Proyecto B']);
        $this->approvedId = ApprovalStatus::query()->create(['company_id' => $this->company->id, 'code' => 'approved', 'name' => 'Aprobado', 'active' => true])->id;
        $this->rejectedId = ApprovalStatus::query()->create(['company_id' => $this->company->id, 'code' => 'rejected', 'name' => 'Rechazado', 'active' => true])->id;
    }

    public function test_real_hourly_cost_uses_company_cost_divided_by_approved_hours(): void
    {
        $person = $this->person(['monthly_hours' => 160]);
        $payroll = $this->payroll($person, 2000000, '2026-08-01');
        $this->time($person, $this->projectA, 160, $this->approvedId);

        $metrics = app(HourlyCostService::class)->forPayroll($payroll);

        $this->assertSame(160.0, $metrics['worked_hours']);
        $this->assertSame(12500.0, $metrics['real_hourly_cost']);
    }

    public function test_zero_hours_returns_controlled_message_without_dividing(): void
    {
        $person = $this->person(['monthly_hours' => 160]);
        $payroll = $this->payroll($person, 2000000, '2026-08-01');

        $metrics = app(HourlyCostService::class)->forPayroll($payroll);

        $this->assertSame(0.0, $metrics['worked_hours']);
        $this->assertNull($metrics['real_hourly_cost']);
        $this->assertStringContainsString('no existen horas aprobadas', $metrics['real_hourly_cost_message']);
    }

    public function test_reference_capacity_is_prorated_for_partial_person(): void
    {
        $person = $this->person([
            'monthly_hours' => 160,
            'start_date' => '2026-08-16',
        ]);
        $payroll = $this->payroll($person, 1000000, '2026-08-01', 16, 31);

        $metrics = app(HourlyCostService::class)->forPayroll($payroll);

        $this->assertSame(round(160 * (16 / 31), 4), $metrics['reference_capacity_hours']);
    }

    public function test_rejected_hours_are_excluded_from_real_cost_denominator(): void
    {
        $person = $this->person(['monthly_hours' => 160]);
        $payroll = $this->payroll($person, 2000000, '2026-08-01');
        $this->time($person, $this->projectA, 100, $this->approvedId);
        $this->time($person, $this->projectA, 60, $this->rejectedId);

        $metrics = app(HourlyCostService::class)->forPayroll($payroll);

        $this->assertSame(100.0, $metrics['worked_hours']);
        $this->assertSame(20000.0, $metrics['real_hourly_cost']);
    }

    public function test_multiple_projects_allocate_cost_without_duplication(): void
    {
        $person = $this->person(['monthly_hours' => 160]);
        $payroll = $this->payroll($person, 2000000, '2026-08-01');
        $this->time($person, $this->projectA, 100, $this->approvedId);
        $this->time($person, $this->projectB, 60, $this->approvedId);

        $metrics = app(HourlyCostService::class)->forPayroll($payroll);

        $this->assertSame(160.0, $metrics['worked_hours']);
        $this->assertSame(2000000.0, $metrics['allocated_cost']);
        $this->assertSame(0.0, $metrics['unassigned_cost']);
        $this->assertCount(2, $metrics['project_breakdown']);
    }

    public function test_honorarios_use_existing_company_cost_as_source(): void
    {
        $person = $this->person(['modality' => 'Honorarios mensual', 'monthly_hours' => 80]);
        $payroll = $this->payroll($person, 900000, '2026-08-01');
        $this->time($person, $this->projectA, 45, $this->approvedId);

        $metrics = app(HourlyCostService::class)->forPayroll($payroll);

        $this->assertSame(45.0, $metrics['worked_hours']);
        $this->assertSame(20000.0, $metrics['real_hourly_cost']);
    }

    public function test_payroll_show_page_displays_formatted_hourly_cost_metrics(): void
    {
        $person = $this->person(['monthly_hours' => 160]);
        $payroll = $this->payroll($person, 2000000, '2026-08-01');
        $this->time($person, $this->projectA, 160, $this->approvedId);

        $response = $this->actingAs($this->admin)->get(route('operational.show', ['payroll-records', $payroll->id]));

        $response->assertOk();
        $response->assertSee('Costo HH del período');
        $response->assertSee('$ 12.500', false);
        $response->assertSee('160 h', false);
    }

    private function person(array $overrides = []): Person
    {
        return Person::query()->create(array_merge([
            'company_id' => $this->company->id,
            'code' => 'PER-'.uniqid(),
            'name' => 'Persona HH',
            'modality' => 'Dependiente mensual',
            'monthly_hours' => 160,
            'status' => 'active',
        ], $overrides));
    }

    private function payroll(Person $person, float $companyCost, string $period, ?int $workedDays = null, ?int $monthDays = null): PayrollRecord
    {
        return PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'project_id' => $this->projectA->id,
            'period_date' => $period,
            'worked_days' => $workedDays,
            'month_days' => $monthDays,
            'employer_cost' => $companyCost,
            'net_pay' => $companyCost,
            'status' => 'Confirmado',
        ]);
    }

    private function time(Person $person, Project $project, float $hours, int $approvalStatusId): TimeEntry
    {
        return TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HOR-'.uniqid(),
            'person_id' => $person->id,
            'client_id' => $this->client->id,
            'project_id' => $project->id,
            'entry_date' => '2026-08-10',
            'activity' => 'Actividad',
            'hours_worked' => $hours,
            'hours_approved' => $hours,
            'hourly_value' => 0,
            'approval_status_id' => $approvalStatusId,
            'approval_status' => $approvalStatusId === $this->approvedId ? 'approved' : 'rejected',
            'payment_status' => 'pending',
        ]);
    }
}
