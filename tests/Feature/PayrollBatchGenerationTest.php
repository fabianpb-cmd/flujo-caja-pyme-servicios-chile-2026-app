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
use App\Models\RecordStatus;
use App\Models\TimeEntry;
use App\Models\UfValue;
use App\Models\User;
use App\Services\PayrollBatchService;
use App\Services\PayrollService;
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
            'status' => PayrollService::STATUS_PENDING_PAYMENT_DATE,
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

    public function test_project_mode_missing_assignment_project_value_marks_record_for_review_and_counts_warning(): void
    {
        $person = $this->person([
            'modality' => 'Honorarios por proyecto',
            'monthly_value' => 0,
            'employment_mode_id' => null,
        ]);
        $project = $this->project('PRY-BATCH-PROJ-MISS');
        $this->assignment($person, $project, '2026-08-01', '2026-08-31', [
            'project_value' => null,
        ]);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01', true);

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();

        $this->assertSame(1, $summary['evaluated']);
        $this->assertSame(1, $summary['generated']);
        $this->assertSame(1, $summary['warnings']);
        $this->assertSame('REQUIERE_REVISION', $record->calculation_status);
        $this->assertSame('Requiere revisión', $record->status);
        $this->assertStringContainsString('Valor proyecto/hito no configurado para la asignación vigente.', (string) $record->calculation_notes);
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

    public function test_hourly_payroll_uses_person_clp_rate_for_single_assignment(): void
    {
        $person = $this->person([
            'modality' => 'Honorarios por hora',
            'monthly_value' => 0,
            'hourly_value' => 2000,
            'employment_mode_id' => null,
        ]);
        $project = $this->project('PRY-HOURLY-CLP');
        $assignment = $this->assignment($person, $project, '2026-08-01', '2026-08-31', [
            'hourly_value' => null,
            'project_value' => null,
        ]);

        $this->approvedTimeEntry($person, $project, $assignment, '2026-08-05', 10, 2000);

        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();

        $this->assertSame($project->id, $record->project_id);
        $this->assertSame('OK', $record->calculation_status);
        $this->assertSame(10.0, (float) $record->hours_approved);
        $this->assertSame(2000.0, (float) $record->hourly_value);
        $this->assertStringNotContainsString('Tarifa de remuneración por hora no configurada', (string) $record->calculation_notes);
    }

    public function test_hourly_payroll_uses_person_uf_rate_for_single_assignment(): void
    {
        $person = $this->person([
            'modality' => 'Honorarios por hora',
            'monthly_value' => 0,
            'hourly_value' => 1.00,
            'hourly_rate_unit_type' => 'UF',
            'employment_mode_id' => null,
        ]);
        $project = $this->project('PRY-HOURLY-UF');
        $assignment = $this->assignment($person, $project, '2026-08-01', '2026-08-31', [
            'hourly_value' => null,
            'project_value' => null,
        ]);

        $this->approvedTimeEntry($person, $project, $assignment, '2026-08-05', 10, 40845);

        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $explanationJson = json_encode(app(PayrollService::class)->explain($record), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame($project->id, $record->project_id);
        $this->assertSame('OK', $record->calculation_status);
        $this->assertSame(10.0, (float) $record->hours_approved);
        $this->assertSame(app(PayrollService::class)->payrollDefaultValues($person->fresh(['hourlyRateCurrency']), '2026-08-01', $project->id)['hourly_value'], (float) $record->hourly_value);
        $this->assertStringContainsString('UF 1,00 / HH', $explanationJson);
        $this->assertStringNotContainsString('Tarifa de remuneración por hora no configurada', (string) $record->calculation_notes);
    }

    public function test_hourly_payroll_uses_latest_official_uf_when_period_day_is_missing(): void
    {
        $person = $this->person([
            'modality' => 'Honorarios por hora',
            'monthly_value' => 0,
            'hourly_value' => 1.00,
            'hourly_rate_unit_type' => 'UF',
            'employment_mode_id' => null,
        ]);
        $project = $this->project('PRY-HOURLY-UF-MISS');
        $assignment = $this->assignment($person, $project, '2026-09-01', '2026-09-30', [
            'hourly_value' => null,
            'project_value' => null,
        ]);

        $this->approvedTimeEntry($person, $project, $assignment, '2026-09-01', 10, 0);
        UfValue::query()->where('company_id', $this->company->id)->delete();
        UfValue::query()->create([
            'company_id' => $this->company->id,
            'value_date' => '2026-08-31',
            'value' => 41000,
            'active' => true,
        ]);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-09-01');

        $record = PayrollRecord::query()->where('person_id', $person->id)->whereDate('period_date', '2026-09-01')->firstOrFail();

        $this->assertSame(1, $summary['evaluated']);
        $this->assertSame(1, $summary['generated']);
        $this->assertSame(0, $summary['warnings']);
        $this->assertSame(0, $summary['errors']);
        $this->assertSame('OK', $record->calculation_status);
        $this->assertSame('Borrador', $record->status);
        $this->assertSame(41000.0, (float) $record->hourly_value);
        $this->assertSame(410000.0, (float) $record->base_salary);
        $this->assertStringNotContainsString('Falta UF oficial para 2026-09-01.', (string) $record->calculation_notes);
    }

    public function test_hourly_payroll_with_multiple_assignments_in_same_project_stays_calculable_and_persists_the_project(): void
    {
        $person = $this->person([
            'name' => 'Jaime Soriano',
            'modality' => 'Honorarios por hora',
            'monthly_value' => 0,
            'hourly_value' => 1.00,
            'hourly_rate_unit_type' => 'UF',
            'employment_mode_id' => null,
        ]);
        $project = $this->project('PRY-ALERTA');
        $assignmentA = $this->assignment($person, $project, '2026-08-01', '2026-08-31', [
            'code' => 'ASI-JS-01',
            'hourly_value' => null,
            'project_value' => null,
        ]);
        $assignmentB = $this->assignment($person, $project, '2026-08-10', '2026-08-31', [
            'code' => 'ASI-JS-02',
            'hourly_value' => null,
            'project_value' => null,
        ]);

        $this->approvedTimeEntry($person, $project, $assignmentA, '2026-08-24', 10, 40845);
        $this->approvedTimeEntry($person, $project, $assignmentB, '2026-08-28', 12, 40845);

        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $explanationJson = json_encode(app(PayrollService::class)->explain($record), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame($project->id, $record->project_id);
        $this->assertSame('OK', $record->calculation_status);
        $this->assertSame('Borrador', $record->status);
        $this->assertSame(22.0, (float) $record->hours_approved);
        $this->assertSame(app(PayrollService::class)->payrollDefaultValues($person->fresh(['hourlyRateCurrency']), '2026-08-01', $project->id)['hourly_value'], (float) $record->hourly_value);
        $this->assertStringNotContainsString('Tarifa de remuneración por hora no configurada', (string) $record->calculation_notes);
        $this->assertStringNotContainsString('Proyecto pendiente', (string) $record->calculation_notes);
        $this->assertStringContainsString('Múltiples asignaciones', $explanationJson);
        $this->assertStringNotContainsString('No configurada', $explanationJson);
        $this->assertStringContainsString('UF 1,00 / HH', $explanationJson);
    }

    public function test_hourly_payroll_with_multiple_projects_keeps_project_null_and_calculates_normally(): void
    {
        $person = $this->person([
            'modality' => 'Honorarios por hora',
            'monthly_value' => 0,
            'hourly_value' => 1.00,
            'hourly_rate_unit_type' => 'UF',
            'employment_mode_id' => null,
        ]);
        $projectA = $this->project('PRY-HOUR-A');
        $projectB = $this->project('PRY-HOUR-B');
        $assignmentA = $this->assignment($person, $projectA, '2026-08-01', '2026-08-31', [
            'hourly_value' => null,
            'project_value' => null,
        ]);
        $assignmentB = $this->assignment($person, $projectB, '2026-08-01', '2026-08-31', [
            'hourly_value' => null,
            'project_value' => null,
        ]);

        $this->approvedTimeEntry($person, $projectA, $assignmentA, '2026-08-05', 10, 40845);
        $this->approvedTimeEntry($person, $projectB, $assignmentB, '2026-08-06', 12, 40845);

        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $explanationJson = json_encode(app(PayrollService::class)->explain($record), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertNull($record->project_id);
        $this->assertSame('OK', $record->calculation_status);
        $this->assertSame(22.0, (float) $record->hours_approved);
        $this->assertSame(app(PayrollService::class)->payrollDefaultValues($person->fresh(['hourlyRateCurrency']), '2026-08-01', null)['hourly_value'], (float) $record->hourly_value);
        $this->assertStringContainsString('Varios proyectos', $explanationJson);
        $this->assertStringNotContainsString('Tarifa de remuneración por hora no configurada', (string) $record->calculation_notes);
    }

    public function test_hourly_payroll_without_person_rate_still_requires_review(): void
    {
        $person = $this->person([
            'modality' => 'Honorarios por hora',
            'monthly_value' => 0,
            'hourly_value' => null,
            'employment_mode_id' => null,
        ]);
        $project = $this->project('PRY-HOURLY-MISSING');
        $assignment = $this->assignment($person, $project, '2026-08-01', '2026-08-31', [
            'hourly_value' => null,
            'project_value' => null,
        ]);

        $this->approvedTimeEntry($person, $project, $assignment, '2026-08-05', 10, 0);

        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();

        $this->assertSame('REQUIERE_REVISION', $record->calculation_status);
        $this->assertStringContainsString('Tarifa de remuneración por hora no configurada en la ficha de Personal.', (string) $record->calculation_notes);
    }

    public function test_route_generates_period_and_returns_summary(): void
    {
        $this->person();

        $response = $this->actingAs($this->admin)->post(route('payroll.generate-period'), ['period' => '08/2026']);

        $response->assertRedirect(route('operational.index', ['resource' => 'payroll-records', 'period' => '08/2026']));
        $response->assertSessionHas('payroll_batch_summary');
        $this->assertSame(1, PayrollRecord::query()->count());
    }

    public function test_route_recalculates_drafts_for_the_exact_requested_month(): void
    {
        $person = $this->person(['monthly_value' => 1000000]);

        $september = PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-SEP',
            'person_id' => $person->id,
            'period_date' => '2026-09-01',
            'gross_amount' => 123,
            'net_pay' => 123,
            'employer_cost' => 123,
            'status' => 'Borrador',
        ]);

        $october = PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-OCT',
            'person_id' => $person->id,
            'period_date' => '2026-10-01',
            'gross_amount' => 456,
            'net_pay' => 456,
            'employer_cost' => 456,
            'status' => 'Borrador',
        ]);

        $response = $this->actingAs($this->admin)->post(route('payroll.recalculate-drafts'), ['period' => '09/2026']);

        $response->assertRedirect(route('operational.index', ['resource' => 'payroll-records', 'period' => '09/2026']));
        $response->assertSessionHas('payroll_batch_summary', function (array $summary): bool {
            return ($summary['period_date'] ?? null) === '2026-09-01';
        });

        $this->assertNotSame(123.0, (float) $september->fresh()->gross_amount);
        $this->assertSame(456.0, (float) $october->fresh()->gross_amount);
        $this->assertSame('2026-09-01', $september->fresh()->period_date?->toDateString());
        $this->assertSame('2026-10-01', $october->fresh()->period_date?->toDateString());
        $this->assertSame(1, PayrollRecord::query()->where('person_id', $person->id)->whereDate('period_date', '2026-09-01')->count());
        $this->assertSame(1, PayrollRecord::query()->where('person_id', $person->id)->whereDate('period_date', '2026-10-01')->count());
    }

    public function test_manual_override_inputs_ignore_historical_values_that_match_automatic_sources(): void
    {
        $person = $this->person([
            'modality' => 'Honorarios por proyecto',
            'monthly_value' => 0,
            'employment_mode_id' => null,
        ]);
        $project = $this->project('PRY-HIST-AUTO');
        $assignment = $this->assignment($person, $project, '2026-08-01', '2026-08-31', [
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => \App\Models\Currency::query()->where('company_id', $this->company->id)->where('code', 'UF')->value('id'),
            'hourly_value' => 0.50,
            'project_value' => 100.00,
        ]);

        TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HRS-BATCH-HIST',
            'person_id' => $person->id,
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-15',
            'activity' => 'Trabajo histórico',
            'hours_worked' => 10,
            'hours_approved' => 10,
            'hourly_value' => 20422,
            'calculated_amount' => 204220,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        $record = PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'hours_approved' => 10,
            'hourly_value' => 20422,
            'project_value' => 4084479,
            'gross_amount' => 4084479,
            'net_pay' => 3461596,
            'employer_cost' => 4084479,
            'status' => 'Borrador',
        ]);

        $overrides = app(PayrollService::class)->manualOverrideInputs($record);

        $this->assertSame([], $overrides);
    }

    public function test_recalculate_refreshes_hours_from_time_entries_while_preserving_other_manual_overrides(): void
    {
        $person = $this->person([
            'modality' => 'Honorarios por proyecto',
            'monthly_value' => 0,
            'employment_mode_id' => null,
        ]);
        $project = $this->project('PRY-HIST-OVR');
        $assignment = $this->assignment($person, $project, '2026-08-01', '2026-08-31', [
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => \App\Models\Currency::query()->where('company_id', $this->company->id)->where('code', 'UF')->value('id'),
            'hourly_value' => 0.50,
            'project_value' => 100.00,
        ]);

        TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HRS-BATCH-OVR',
            'person_id' => $person->id,
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-15',
            'activity' => 'Trabajo override',
            'hours_worked' => 10,
            'hours_approved' => 10,
            'hourly_value' => 20422,
            'calculated_amount' => 204220,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        $record = PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'hours_approved' => 12,
            'hourly_value' => 25000,
            'project_value' => 5000000,
            'gross_amount' => 5000000,
            'net_pay' => 4237500,
            'employer_cost' => 5000000,
            'status' => 'Borrador',
        ]);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01', true);
        $record->refresh();
        $summaryAgain = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01', true);
        $record->refresh();

        $this->assertSame(1, $summary['updated']);
        $this->assertSame(1, $summaryAgain['updated']);
        $this->assertSame(10.0, (float) $record->hours_approved);
        $this->assertSame(25000.0, (float) $record->hourly_value);
        $this->assertSame(5000000.0, (float) $record->project_value);
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

    private function assignment(Person $person, Project $project, string $start, ?string $end, array $overrides = []): ProjectAssignment
    {
        return ProjectAssignment::query()->create(array_merge([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'code' => 'ASI-'.uniqid(),
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'active',
            'assignment_status_id' => $this->statusId('assignment', 'active'),
        ], $overrides));
    }

    private function statusId(string $domain, string $code): int
    {
        return RecordStatus::query()->firstOrCreate(
            [
                'company_id' => $this->company->id,
                'domain' => $domain,
                'code' => $code,
            ],
            [
                'name' => strtoupper($code),
                'active' => true,
            ]
        )->id;
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

    private function approvedTimeEntry(Person $person, Project $project, ProjectAssignment $assignment, string $date, float $hours, float $hourlyValue): TimeEntry
    {
        return TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HRS-'.uniqid(),
            'person_id' => $person->id,
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => $date,
            'activity' => 'Trabajo payroll',
            'hours_worked' => $hours,
            'hours_approved' => $hours,
            'hourly_value' => $hourlyValue,
            'calculated_amount' => round($hours * $hourlyValue, 2),
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);
    }
}
