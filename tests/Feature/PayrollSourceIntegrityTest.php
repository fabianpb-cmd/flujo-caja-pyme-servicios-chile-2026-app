<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ApprovalStatus;
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
use App\Models\User;
use App\Services\PayrollBatchService;
use App\Services\PayrollService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollSourceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private int $approvedStatusId;
    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'code' => 'CMP-PAY-SRC',
            'name' => 'Empresa Integridad Payroll',
            'status' => 'active',
        ]);
        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role' => 'admin',
            'active' => true,
        ]);
        $this->approvedStatusId = ApprovalStatus::query()->create([
            'company_id' => $this->company->id,
            'code' => 'approved',
            'name' => 'Aprobado',
            'active' => true,
        ])->id;
        $this->activity = Activity::query()->create([
            'company_id' => $this->company->id,
            'code' => 'ACT-PAY-SRC',
            'name' => 'Actividad Payroll',
            'active' => true,
        ]);
        LegalParameter::query()->create([
            'company_id' => $this->company->id,
            'parameter_code' => 'RETENCION_HONORARIOS',
            'parameter_name' => 'Retención honorarios',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'value' => 0.1525,
            'unit' => '%',
            'active' => true,
        ]);
    }

    public function test_hourly_manual_payroll_ignores_arbitrary_hours_and_rate_without_time_entries(): void
    {
        [$person, $project] = $this->hourlyPersonWithAssignment();

        $response = $this->actingAs($this->admin)->post(route('operational.store', 'payroll-records'), [
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'amount_basis' => 'GROSS',
            'hours_approved' => 100,
            'hourly_value' => 100000,
            'bonuses' => 0,
            'non_taxable_allowances' => 0,
            'advances' => 0,
            'other_deductions' => 0,
        ]);

        $response->assertRedirect(route('operational.index', 'payroll-records'));

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $this->assertSame(0.0, (float) $record->hours_approved);
        $this->assertSame(50000.0, (float) $record->hourly_value);
        $this->assertSame(0.0, (float) $record->gross_amount);
        $this->assertSame(0, $record->timeEntries()->count());
        $this->assertSame('Requiere revisión', $record->status);
        $this->assertStringContainsString('Sin horas aprobadas', (string) $record->calculation_notes);
    }

    public function test_hourly_manual_payroll_uses_traced_hours_and_person_rate_instead_of_request_values(): void
    {
        [$person, $project, $assignment] = $this->hourlyPersonWithAssignment(['hourly_value' => 50000]);
        $first = $this->approvedTimeEntry($person, $project, $assignment, '2026-08-05', 3);
        $second = $this->approvedTimeEntry($person, $project, $assignment, '2026-08-06', 5);

        $response = $this->actingAs($this->admin)->post(route('operational.store', 'payroll-records'), [
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'amount_basis' => 'GROSS',
            'hours_approved' => 100,
            'hourly_value' => 100000,
            'bonuses' => 0,
            'non_taxable_allowances' => 0,
            'advances' => 0,
            'other_deductions' => 0,
        ]);

        $response->assertRedirect(route('operational.index', 'payroll-records'));

        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();
        $this->assertSame(8.0, (float) $record->hours_approved);
        $this->assertSame(50000.0, (float) $record->hourly_value);
        $this->assertSame(400000.0, (float) $record->gross_amount);
        $this->assertSame('OK', $record->calculation_status);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $record->timeEntries()->pluck('time_entries.id')->all());
        $this->assertSame((float) $record->hours_approved, (float) $record->timeEntries()->sum('time_entries.hours_approved'));
    }

    public function test_payroll_service_excludes_time_entries_outside_person_employment_window(): void
    {
        [$person, $project, $assignment] = $this->hourlyPersonWithAssignment([
            'start_date' => '2026-08-05',
            'end_date' => '2026-08-15',
        ]);
        $valid = $this->approvedTimeEntry($person, $project, $assignment, '2026-08-10', 4);
        $this->approvedTimeEntry($person, $project, $assignment, '2026-08-20', 6);

        $payroll = app(PayrollService::class)->calculate($person, '2026-08-01', [
            'project_id' => $project->id,
            'hours_approved' => 100,
            'hourly_value' => 100000,
        ]);

        $this->assertSame(4.0, $payroll['hours_approved']);
        $this->assertSame(50000.0, (float) $payroll['hourly_value']);
        $this->assertSame(200000.0, $payroll['gross_amount']);
        $this->assertEquals([$valid->id], app(PayrollService::class)->hourlyPayrollTimeEntries($person, '2026-08-01', $project->id)->pluck('id')->all());
    }

    public function test_time_entry_period_rejects_dates_outside_person_employment_and_allows_boundaries(): void
    {
        [$person, $project] = $this->hourlyPersonWithAssignment([
            'start_date' => '2026-08-05',
            'end_date' => '2026-08-15',
        ], '2026-08-01', '2026-08-31');

        $beforeStart = $this->actingAs($this->admin)->from(route('operational.create', 'time-entries'))
            ->post(route('operational.store', 'time-entries'), $this->timeEntryPayload($person, $project, '2026-08-04', '2026-08-04', 1));
        $beforeStart->assertRedirect(route('operational.create', 'time-entries'));
        $beforeStart->assertSessionHasErrors('period_rows');

        $onStart = $this->actingAs($this->admin)
            ->post(route('operational.store', 'time-entries'), $this->timeEntryPayload($person, $project, '2026-08-05', '2026-08-05', 1));
        $onStart->assertRedirect(route('operational.index', 'time-entries'));

        $onEnd = $this->actingAs($this->admin)
            ->post(route('operational.store', 'time-entries'), $this->timeEntryPayload($person, $project, '2026-08-15', '2026-08-15', 1));
        $onEnd->assertRedirect(route('operational.index', 'time-entries'));

        $afterEnd = $this->actingAs($this->admin)->from(route('operational.create', 'time-entries'))
            ->post(route('operational.store', 'time-entries'), $this->timeEntryPayload($person, $project, '2026-08-16', '2026-08-16', 1));
        $afterEnd->assertRedirect(route('operational.create', 'time-entries'));
        $afterEnd->assertSessionHasErrors('period_rows');

        $this->assertSame(2, TimeEntry::query()->where('person_id', $person->id)->count());
    }

    public function test_mid_month_employment_only_remunerates_valid_approved_hours(): void
    {
        [$person, $project, $assignment] = $this->hourlyPersonWithAssignment([
            'end_date' => '2026-08-15',
        ]);
        $valid = $this->approvedTimeEntry($person, $project, $assignment, '2026-08-15', 2);
        $this->approvedTimeEntry($person, $project, $assignment, '2026-08-16', 9);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');
        $record = PayrollRecord::query()->where('person_id', $person->id)->firstOrFail();

        $this->assertSame(1, $summary['generated']);
        $this->assertSame(2.0, (float) $record->hours_approved);
        $this->assertSame(100000.0, (float) $record->gross_amount);
        $this->assertEquals([$valid->id], $record->timeEntries()->pluck('time_entries.id')->all());
    }

    public function test_payroll_record_person_period_uniqueness_is_enforced_in_http_batch_update_and_database(): void
    {
        [$person, $project] = $this->hourlyPersonWithAssignment();
        $existing = $this->payrollRecord($person, $project, '2026-08-01', 'REM-UNIQ-1');

        $duplicateCreate = $this->actingAs($this->admin)->from(route('operational.create', 'payroll-records'))
            ->post(route('operational.store', 'payroll-records'), [
                'person_id' => $person->id,
                'project_id' => $project->id,
                'period_date' => '2026-08-01',
                'amount_basis' => 'GROSS',
            ]);
        $duplicateCreate->assertRedirect(route('operational.create', 'payroll-records'));
        $duplicateCreate->assertSessionHasErrors([
            'period_date' => 'Ya existe una remuneración para esta persona y período.',
        ]);

        $ownUpdate = $this->actingAs($this->admin)->put(route('operational.update', ['payroll-records', $existing->id]), [
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'payment_date' => '',
            'amount_basis' => 'GROSS',
            'bonuses' => '0',
            'non_taxable_allowances' => '0',
            'advances' => '0',
            'other_deductions' => '0',
        ]);
        $ownUpdate->assertRedirect(route('operational.show', ['payroll-records', $existing->id]));

        $otherPerson = $this->hourlyPersonWithAssignment(['code' => 'PER-UNIQ-2'])[0];
        $otherRecord = $this->payrollRecord($otherPerson, $project, '2026-08-01', 'REM-UNIQ-2');
        $duplicateUpdate = $this->actingAs($this->admin)->from(route('operational.edit', ['payroll-records', $otherRecord->id]))
            ->put(route('operational.update', ['payroll-records', $otherRecord->id]), [
                'person_id' => $person->id,
                'project_id' => $project->id,
                'period_date' => '2026-08-01',
                'payment_date' => '',
                'amount_basis' => 'GROSS',
                'bonuses' => '0',
                'non_taxable_allowances' => '0',
                'advances' => '0',
                'other_deductions' => '0',
            ]);
        $duplicateUpdate->assertRedirect(route('operational.edit', ['payroll-records', $otherRecord->id]));
        $duplicateUpdate->assertSessionHasErrors('period_date');
        $this->assertSame($otherPerson->id, $otherRecord->fresh()->person_id);

        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01', true);
        $this->assertSame(0, $summary['generated']);
        $this->assertSame(2, PayrollRecord::query()->count());

        $otherCompany = Company::query()->create(['code' => 'CMP-PAY-SRC-2', 'name' => 'Otra Empresa', 'status' => 'active']);
        $otherCompanyPerson = Person::query()->create([
            'company_id' => $otherCompany->id,
            'code' => 'PER-UNIQ-OTHER',
            'name' => 'Persona Otra Empresa',
            'modality' => 'Honorarios por hora',
            'hourly_value' => 50000,
            'status' => 'active',
        ]);
        PayrollRecord::query()->create([
            'company_id' => $otherCompany->id,
            'code' => 'REM-UNIQ-OTHER',
            'person_id' => $otherCompanyPerson->id,
            'period_date' => '2026-08-01',
            'gross_amount' => 0,
            'net_pay' => 0,
            'employer_cost' => 0,
            'status' => 'Borrador',
        ]);

        $this->expectException(QueryException::class);
        PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-UNIQ-DB',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'gross_amount' => 0,
            'net_pay' => 0,
            'employer_cost' => 0,
            'status' => 'Borrador',
        ]);
    }

    private function hourlyPersonWithAssignment(array $personOverrides = [], string $assignmentStart = '2026-08-01', ?string $assignmentEnd = '2026-08-31'): array
    {
        $client = Client::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'CLI-PAY-SRC'],
            ['legal_name' => 'Cliente Payroll Source', 'client_status_id' => $this->statusId('client', 'active')]
        );
        $project = Project::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'PRY-PAY-SRC'],
            [
                'client_id' => $client->id,
                'name' => 'Proyecto Payroll Source',
                'project_status_id' => $this->statusId('project', 'active'),
                'billing_status_id' => $this->statusId('billing', 'pending'),
            ]
        );
        $person = Person::query()->create(array_merge([
            'company_id' => $this->company->id,
            'code' => 'PER-PAY-SRC-'.uniqid(),
            'name' => 'Persona Horaria Source',
            'modality' => 'Honorarios por hora',
            'hourly_value' => 50000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'status' => 'active',
            'start_date' => '2026-01-01',
        ], $personOverrides));
        $assignment = ProjectAssignment::query()->create([
            'company_id' => $this->company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-PAY-SRC-'.uniqid(),
            'start_date' => $assignmentStart,
            'end_date' => $assignmentEnd,
            'hourly_value' => 50000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'assignment_status_id' => $this->statusId('assignment', 'active'),
        ]);

        return [$person, $project, $assignment];
    }

    private function approvedTimeEntry(Person $person, Project $project, ProjectAssignment $assignment, string $date, float $hours): TimeEntry
    {
        return TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HOR-PAY-SRC-'.uniqid(),
            'person_id' => $person->id,
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'activity_id' => $this->activity->id,
            'activity' => $this->activity->name,
            'entry_date' => $date,
            'hours_worked' => $hours,
            'hours_approved' => $hours,
            'hourly_value' => 50000,
            'calculated_amount' => round($hours * 50000, 2),
            'approval_status_id' => $this->approvedStatusId,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);
    }

    private function timeEntryPayload(Person $person, Project $project, string $startDate, string $endDate, float $hours): array
    {
        return [
            'entry_mode' => 'period',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'activity_id' => $this->activity->id,
            'period_start_date' => $startDate,
            'period_end_date' => $endDate,
            'period_total_hours' => $hours,
            'approval_status_id' => $this->approvedStatusId,
            'payment_status' => 'pending',
        ];
    }

    private function payrollRecord(Person $person, Project $project, string $period, string $code): PayrollRecord
    {
        return PayrollRecord::query()->create([
            'company_id' => $person->company_id,
            'code' => $code,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => $period,
            'hours_approved' => 0,
            'hourly_value' => 50000,
            'gross_amount' => 0,
            'net_pay' => 0,
            'employer_cost' => 0,
            'status' => 'Borrador',
            'calculation_status' => 'REQUIERE_REVISION',
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
