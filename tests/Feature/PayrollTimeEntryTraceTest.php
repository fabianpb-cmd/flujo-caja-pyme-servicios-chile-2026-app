<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ApprovalStatus;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Company;
use App\Models\LegalParameter;
use App\Models\PayrollRecord;
use App\Models\PayrollRecordTimeEntry;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\RecordStatus;
use App\Models\TimeEntry;
use App\Models\UfValue;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\PayrollBatchService;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayrollTimeEntryTraceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private int $approvedStatusId;

    private CashAccount $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'code' => 'CMP-PAY-TRACE',
            'name' => 'Empresa Trazabilidad Payroll',
            'status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role' => 'admin',
            'active' => true,
        ]);

        LegalParameter::query()->create([
            'company_id' => $this->company->id,
            'parameter_code' => 'RETENCION_HONORARIOS',
            'parameter_name' => 'Retención honorarios',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'value' => 0.1525,
            'unit' => '%',
            'active' => true,
        ]);
        UfValue::query()->create([
            'company_id' => $this->company->id,
            'value_date' => '2026-08-01',
            'value' => 40844.79,
        ]);

        $this->approvedStatusId = ApprovalStatus::query()->create([
            'company_id' => $this->company->id,
            'code' => 'approved',
            'name' => 'Aprobado',
            'active' => true,
        ])->id;

        $this->cashAccount = CashAccount::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CTA-PAY-TRACE',
            'name' => 'Banco Trazabilidad',
            'currency' => 'CLP',
            'opening_balance' => 1000000,
            'is_active' => true,
        ]);
    }

    public function test_hourly_batch_generation_links_the_exact_time_entries_and_keeps_snapshots(): void
    {
        [$person, $project, $assignment] = $this->hourlyFixtures();
        $entries = collect([
            $this->timeEntry($person, $project, $assignment, '2026-08-04', 2),
            $this->timeEntry($person, $project, $assignment, '2026-08-05', 3),
            $this->timeEntry($person, $project, $assignment, '2026-08-06', 5),
        ]);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();

        $this->assertSame(1, $summary['generated']);
        $this->assertSame(3, PayrollRecordTimeEntry::query()->where('payroll_record_id', $record->id)->count());
        $this->assertEqualsCanonicalizing($entries->pluck('id')->all(), $record->timeEntries()->pluck('time_entries.id')->all());
        $this->assertSame(10.0, (float) $record->hours_approved);
        $this->assertGreaterThan(0, (float) $record->gross_amount);
        $this->assertGreaterThan(0, (float) $record->net_pay);
        $this->assertSame('2026-08-01', $record->period_date?->toDateString());
        $this->assertSame('2026-08-01', $record->legal_snapshot['period'] ?? null);
    }

    public function test_hourly_batch_recalculation_resynchronizes_the_same_payroll_record_without_duplicates(): void
    {
        [$person, $project, $assignment] = $this->hourlyFixtures();
        $first = $this->timeEntry($person, $project, $assignment, '2026-08-04', 4);
        $second = $this->timeEntry($person, $project, $assignment, '2026-08-05', 6);

        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $this->assertSame(10.0, (float) $record->hours_approved);

        \App\Models\PayrollAdjustment::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'period_date' => '2026-08-01',
            'type' => 'BONUS_TAXABLE',
            'amount' => 5000,
            'active' => true,
        ]);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01', true);

        $record->refresh();
        $this->assertSame(1, $summary['updated']);
        $this->assertSame($record->id, PayrollRecord::query()->where('person_id', $person->id)->firstOrFail()->id);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], PayrollRecordTimeEntry::query()->where('payroll_record_id', $record->id)->pluck('time_entry_id')->all());
        $this->assertSame(2, PayrollRecordTimeEntry::query()->where('payroll_record_id', $record->id)->count());
        $this->assertSame(10.0, (float) $record->hours_approved);
        $this->assertSame(5000.0, (float) $record->bonuses);
    }

    public function test_hourly_batch_with_multiple_projects_links_all_consumed_entries_without_creating_a_second_payroll(): void
    {
        $projectA = $this->project('CLI-HOURLY-A', 'PRY-HOURLY-A');
        $projectB = $this->project('CLI-HOURLY-B', 'PRY-HOURLY-B');
        $person = Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'PER-HOURLY-MULTI-'.uniqid(),
            'name' => 'Persona Horaria Multi '.uniqid(),
            'modality' => 'Honorarios por hora',
            'hourly_value' => 1.00,
            'hourly_rate_unit_type' => 'UF',
            'status' => 'active',
        ]);

        $assignmentA = $this->assignment($person, $projectA, [
            'hourly_value' => null,
            'project_value' => null,
        ]);
        $assignmentB = $this->assignment($person, $projectB, [
            'hourly_value' => null,
            'project_value' => null,
        ]);

        $first = $this->timeEntry($person, $projectA, $assignmentA, '2026-08-04', 10, ['hourly_value' => 40845]);
        $second = $this->timeEntry($person, $projectB, $assignmentB, '2026-08-05', 12, ['hourly_value' => 40845]);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $explanationJson = json_encode(app(PayrollService::class)->explain($record), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame(1, $summary['generated']);
        $this->assertSame(1, PayrollRecord::query()->where('person_id', $person->id)->count());
        $this->assertNull($record->project_id);
        $this->assertSame(22.0, (float) $record->hours_approved);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $record->timeEntries()->pluck('time_entries.id')->all());
        $this->assertSame(2, PayrollRecordTimeEntry::query()->where('payroll_record_id', $record->id)->count());
        $this->assertSame('OK', $record->calculation_status);
        $this->assertStringContainsString('Varios proyectos', $explanationJson);
        $this->assertStringContainsString('UF 1,00 / HH', $explanationJson);
    }

    public function test_manual_payroll_crud_create_and_update_also_synchronize_hourly_trace(): void
    {
        [$person, $project, $assignment] = $this->hourlyFixtures();
        $first = $this->timeEntry($person, $project, $assignment, '2026-08-04', 4);
        $second = $this->timeEntry($person, $project, $assignment, '2026-08-05', 8);

        $create = $this->actingAs($this->admin)->post(route('operational.store', 'payroll-records'), [
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
        ]);

        $create->assertRedirect(route('operational.index', 'payroll-records'));

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $record->timeEntries()->pluck('time_entries.id')->all());
        PayrollRecordTimeEntry::query()->where('payroll_record_id', $record->id)->delete();
        $this->assertSame(0, $record->timeEntries()->count());

        $update = $this->actingAs($this->admin)
            ->from(route('operational.edit', ['payroll-records', $record->id]))
            ->put(route('operational.update', ['payroll-records', $record->id]), [
                'person_id' => $person->id,
                'project_id' => $project->id,
                'period_date' => '2026-08-01',
                'payment_date' => '',
                'amount_basis' => 'GROSS',
                'monthly_value' => '',
                'hourly_value' => '',
                'project_value' => '',
                'bonuses' => '0',
                'non_taxable_allowances' => '0',
                'advances' => '0',
                'other_deductions' => '0',
                'status' => 'Pendiente',
            ]);

        $update->assertRedirect(route('operational.show', ['payroll-records', $record->id]));

        $record->refresh();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $record->timeEntries()->pluck('time_entries.id')->all());
        $this->assertSame(12.0, (float) $record->hours_approved);
    }

    public function test_double_consumption_is_blocked_with_a_functional_message(): void
    {
        [$person, $project, $assignment] = $this->hourlyFixtures();
        $entry = $this->timeEntry($person, $project, $assignment, '2026-08-04', 4);

        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $existingRecord = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $this->assertSame(1, PayrollRecordTimeEntry::query()->where('time_entry_id', $entry->id)->count());

        $response = $this->actingAs($this->admin)
            ->from(route('operational.create', 'payroll-records'))
            ->post(route('operational.store', 'payroll-records'), [
                'person_id' => $person->id,
                'project_id' => $project->id,
                'period_date' => '2026-08-01',
            ]);

        $response->assertRedirect(route('operational.create', 'payroll-records'));
        $response->assertSessionHasErrors([
            'payroll' => 'Una o más horas aprobadas ya están asociadas a otra remuneración.',
        ]);
        $this->assertSame(1, PayrollRecord::query()->where('person_id', $person->id)->count());
        $this->assertSame($existingRecord->id, PayrollRecord::query()->where('person_id', $person->id)->firstOrFail()->id);
        $this->assertSame(1, PayrollRecordTimeEntry::query()->where('time_entry_id', $entry->id)->count());
    }

    public function test_consumed_single_entry_batch_is_blocked_for_edit_update_and_delete(): void
    {
        [$person, $project, $assignment] = $this->hourlyFixtures();
        $entry = $this->timeEntry($person, $project, $assignment, '2026-08-04', 4, [
            'period_batch_id' => (string) Str::uuid(),
        ]);
        $record = $this->createHourlyPayrollRecord($person, $project, '2026-08-01');
        app(PayrollService::class)->syncHourlyTimeEntryTrace($record, $person);

        $show = $this->actingAs($this->admin)->get(route('operational.show', ['time-entries', $entry->id]));
        $show->assertOk();
        $show->assertSee('remuneraciones por hora', false);
        $show->assertDontSee('<a class="btn btn-primary" href="'.route('operational.edit', ['time-entries', $entry->id]).'">Editar</a>', false);

        $edit = $this->actingAs($this->admin)->get(route('operational.edit', ['time-entries', $entry->id]));
        $edit->assertRedirect(route('operational.show', ['time-entries', $entry->id]));
        $edit->assertSessionHasErrors('dependencies');

        $update = $this->actingAs($this->admin)
            ->from(route('operational.edit', ['time-entries', $entry->id]))
            ->put(route('operational.update', ['time-entries', $entry->id]), [
                'person_id' => $person->id,
                'project_id' => $project->id,
                'activity_id' => $entry->activity_id,
                'period_batch_id' => $entry->period_batch_id,
                'period_start_date' => '2026-08-04',
                'period_end_date' => '2026-08-04',
                'period_total_hours' => 5,
                'approval_status_id' => $this->approvedStatusId,
                'payment_status' => 'pending',
            ]);

        $update->assertRedirect(route('operational.show', ['time-entries', $entry->id]));
        $update->assertSessionHasErrors('dependencies');
        $this->assertSame(4.0, (float) $entry->fresh()->hours_worked);

        $delete = $this->actingAs($this->admin)->delete(route('operational.destroy', ['time-entries', $entry->id]));
        $delete->assertRedirect(route('operational.show', ['time-entries', $entry->id]));
        $delete->assertSessionHasErrors('dependencies');
        $this->assertDatabaseHas('time_entries', ['id' => $entry->id]);
    }

    public function test_period_batch_is_fully_blocked_when_any_entry_is_linked_to_hourly_payroll(): void
    {
        [$person, $project, $assignment] = $this->hourlyFixtures();
        $batchId = (string) Str::uuid();
        $entries = collect([
            $this->timeEntry($person, $project, $assignment, '2026-08-03', 1.67, ['period_batch_id' => $batchId]),
            $this->timeEntry($person, $project, $assignment, '2026-08-04', 1.67, ['period_batch_id' => $batchId]),
            $this->timeEntry($person, $project, $assignment, '2026-08-05', 1.67, ['period_batch_id' => $batchId]),
            $this->timeEntry($person, $project, $assignment, '2026-08-06', 1.67, ['period_batch_id' => $batchId]),
            $this->timeEntry($person, $project, $assignment, '2026-08-07', 1.67, ['period_batch_id' => $batchId]),
            $this->timeEntry($person, $project, $assignment, '2026-08-10', 1.65, ['period_batch_id' => $batchId]),
        ]);

        $record = $this->createHourlyPayrollRecord($person, $project, '2026-08-01');
        $record->timeEntries()->attach($entries->first()->id);

        $edit = $this->actingAs($this->admin)->get(route('operational.edit', ['time-entries', $entries->first()->id]));
        $edit->assertRedirect(route('operational.show', ['time-entries', $entries->first()->id]));
        $edit->assertSessionHasErrors('dependencies');

        $update = $this->actingAs($this->admin)
            ->from(route('operational.edit', ['time-entries', $entries->first()->id]))
            ->put(route('operational.update', ['time-entries', $entries->first()->id]), [
                'entry_mode' => 'period',
                'person_id' => $person->id,
                'project_id' => $project->id,
                'activity_id' => $entries->first()->activity_id,
                'cost_center_id' => '',
                'period_start_date' => '2026-08-03',
                'period_end_date' => '2026-08-10',
                'period_total_hours' => 10,
                'approval_status_id' => $this->approvedStatusId,
                'payment_status' => 'pending',
            ]);

        $update->assertRedirect(route('operational.show', ['time-entries', $entries->first()->id]));
        $update->assertSessionHasErrors('dependencies');
        $this->assertSame(6, TimeEntry::query()->where('period_batch_id', $batchId)->count());
        $this->assertEqualsWithDelta(10.0, (float) TimeEntry::query()->where('period_batch_id', $batchId)->sum('hours_worked'), 0.01);

        $delete = $this->actingAs($this->admin)->delete(route('operational.destroy', ['time-entries', $entries->first()->id]));
        $delete->assertRedirect(route('operational.show', ['time-entries', $entries->first()->id]));
        $delete->assertSessionHasErrors('dependencies');
        $this->assertSame(6, TimeEntry::query()->where('period_batch_id', $batchId)->count());
    }

    public function test_monthly_and_project_payrolls_do_not_create_time_entry_links(): void
    {
        [$monthlyPerson, $monthlyProject, $monthlyAssignment] = $this->monthlyFixtures();
        $this->timeEntry($monthlyPerson, $monthlyProject, $monthlyAssignment, '2026-08-04', 8);

        [$projectPerson, $projectModel, $projectAssignment] = $this->projectFixtures();
        $this->timeEntry($projectPerson, $projectModel, $projectAssignment, '2026-08-05', 6);

        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');

        $this->assertSame(0, PayrollRecordTimeEntry::query()->count());
        $this->assertSame(0, PayrollRecord::query()->where('person_id', $monthlyPerson->id)->firstOrFail()->timeEntries()->count());
        $this->assertSame(0, PayrollRecord::query()->where('person_id', $projectPerson->id)->firstOrFail()->timeEntries()->count());
    }

    public function test_paying_payroll_does_not_mutate_time_entry_payment_status(): void
    {
        [$person, $project, $assignment] = $this->hourlyFixtures();
        $entry = $this->timeEntry($person, $project, $assignment, '2026-08-04', 4);

        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');
        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $record->update(['payment_date' => '2026-08-25']);

        app(CashMovementService::class)->create([
            'company_id' => $this->company->id,
            'code' => 'MOV-PAY-TRACE-01',
            'movement_type' => 'Egreso',
            'source_document_type' => 'payroll_record',
            'source_document_code' => $record->code,
            'project_id' => $project->id,
            'movement_date' => '2026-08-25',
            'income' => 0,
            'expense' => (float) $record->net_pay,
            'cash_account_id' => $this->cashAccount->id,
            'status' => 'posted',
        ], $this->admin);

        $this->assertSame('Pagado', $record->fresh()->status);
        $this->assertSame('pending', $entry->fresh()->payment_status);
        $this->assertSame(1, CashMovement::query()->where('source_document_code', $record->code)->count());
    }

    private function hourlyFixtures(): array
    {
        $project = $this->project('CLI-HOURLY', 'PRY-HOURLY');
        $person = Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'PER-HOURLY-'.uniqid(),
            'name' => 'Persona Horaria '.uniqid(),
            'modality' => 'Honorarios por hora',
            'hourly_value' => 2000,
            'status' => 'active',
        ]);

        $assignment = $this->assignment($person, $project, [
            'hourly_value' => null,
            'project_value' => null,
        ]);

        return [$person, $project, $assignment];
    }

    private function monthlyFixtures(): array
    {
        $project = $this->project('CLI-MONTHLY', 'PRY-MONTHLY');
        $person = Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'PER-MONTHLY-'.uniqid(),
            'name' => 'Persona Mensual '.uniqid(),
            'modality' => 'Honorarios mensual',
            'monthly_value' => 900000,
            'status' => 'active',
        ]);

        $assignment = $this->assignment($person, $project, [
            'project_value' => null,
        ]);

        return [$person, $project, $assignment];
    }

    private function projectFixtures(): array
    {
        $project = $this->project('CLI-PROJECT', 'PRY-PROJECT');
        $person = Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'PER-PROJECT-'.uniqid(),
            'name' => 'Persona Proyecto '.uniqid(),
            'modality' => 'Honorarios por proyecto',
            'status' => 'active',
        ]);

        $assignment = $this->assignment($person, $project, [
            'project_value' => 500000,
        ]);

        return [$person, $project, $assignment];
    }

    private function project(string $clientCode, string $projectCode): Project
    {
        $client = Client::query()->create([
            'company_id' => $this->company->id,
            'code' => $clientCode,
            'legal_name' => 'Cliente '.$clientCode,
            'client_status_id' => $this->statusId('client', 'active'),
        ]);

        return Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $client->id,
            'code' => $projectCode,
            'name' => $projectCode,
            'project_status_id' => $this->statusId('project', 'active'),
            'billing_status_id' => $this->statusId('billing', 'pending'),
        ]);
    }

    private function assignment(Person $person, Project $project, array $overrides = []): ProjectAssignment
    {
        return ProjectAssignment::query()->create(array_merge([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'code' => 'ASI-'.uniqid(),
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'assignment_status_id' => $this->statusId('assignment', 'active'),
        ], $overrides));
    }

    private function timeEntry(Person $person, Project $project, ProjectAssignment $assignment, string $date, float $hours, array $overrides = []): TimeEntry
    {
        $activity = Activity::query()->create([
            'company_id' => $this->company->id,
            'code' => 'ACT-'.uniqid(),
            'name' => 'Actividad '.uniqid(),
            'active' => true,
        ]);

        return TimeEntry::query()->create(array_merge([
            'company_id' => $this->company->id,
            'code' => 'HOR-'.uniqid(),
            'person_id' => $person->id,
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'activity' => $activity->name,
            'activity_id' => $activity->id,
            'entry_date' => $date,
            'hours_worked' => $hours,
            'hours_approved' => $hours,
            'hourly_value' => 2000,
            'calculated_amount' => round($hours * 2000, 2),
            'approval_status_id' => $this->approvedStatusId,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ], $overrides));
    }

    private function createHourlyPayrollRecord(Person $person, Project $project, string $period): PayrollRecord
    {
        return PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-'.uniqid(),
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => $period,
            'hours_approved' => 0,
            'hourly_value' => 2000,
            'base_salary' => 0,
            'gross_amount' => 0,
            'employee_retention' => 0,
            'employer_cost' => 0,
            'net_pay' => 0,
            'status' => 'Borrador',
            'calculation_status' => 'OK',
            'legal_snapshot' => ['period' => $period],
        ]);
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
}
