<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UatClearDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_rejects_production_environment(): void
    {
        config(['app.env' => 'production']);

        $response = $this->artisan('uat:clear-data', ['--force' => true]);

        $response->assertExitCode(1);
        $response->expectsOutputToContain('deshabilitado en producción');
    }

    public function test_command_clears_payroll_time_entry_trace_and_preserves_master_data(): void
    {
        $company = Company::query()->create([
            'code' => 'CMP-UAT-CLEAR',
            'name' => 'Empresa UAT Clear',
            'status' => 'active',
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-UAT-CLEAR',
            'legal_name' => 'Cliente UAT Clear',
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-UAT-CLEAR',
            'name' => 'Proyecto UAT Clear',
        ]);
        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-UAT-CLEAR',
            'name' => 'Persona UAT Clear',
            'modality' => 'Dependiente mensual',
        ]);
        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-UAT-CLEAR',
        ]);
        $entry = TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HOR-UAT-CLEAR',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-31',
            'activity' => 'Prueba de limpieza',
            'hours_worked' => 1,
            'hours_approved' => 1,
        ]);
        $payroll = PayrollRecord::query()->create([
            'company_id' => $company->id,
            'code' => 'REM-UAT-CLEAR',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
        ]);

        DB::table('payroll_record_time_entries')->insert([
            'payroll_record_id' => $payroll->id,
            'time_entry_id' => $entry->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $periodBatchColumn = collect(Schema::getColumns('time_entries'))->firstWhere('name', 'period_batch_id');
        $this->assertIsArray($periodBatchColumn);
        $this->assertFalse((bool) $periodBatchColumn['nullable']);
        $this->assertNotNull($entry->period_batch_id);
        $this->assertSame(1, DB::table('payroll_record_time_entries')->count());

        $this->artisan('uat:clear-data', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('payroll_record_time_entries')->count());
        $this->assertSame(0, PayrollRecord::query()->count());
        $this->assertSame(0, TimeEntry::query()->count());
        $this->assertTrue(Company::query()->whereKey($company->id)->exists());
        $this->assertTrue(User::query()->whereKey($user->id)->exists());
        $this->assertTrue(Client::query()->whereKey($client->id)->exists());
        $this->assertTrue(Project::query()->whereKey($project->id)->exists());
        $this->assertTrue(Person::query()->whereKey($person->id)->exists());
        $this->assertTrue(ProjectAssignment::query()->whereKey($assignment->id)->exists());
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }
}
