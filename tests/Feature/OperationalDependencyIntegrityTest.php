<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\ExpenseDocument;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalDependencyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_in_use_by_a_project_cannot_be_deleted(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        $client = $this->client($company);
        $project = $this->project($company, $client);

        $response = $this->actingAs($admin)->delete(route('operational.destroy', ['clients', $client->id]));

        $response->assertRedirect(route('operational.show', ['clients', $client->id]));
        $response->assertSessionHasErrors('dependencies');
        $this->assertStringContainsString('proyectos', $this->dependencyError($response));
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_project_in_use_by_operational_and_financial_records_cannot_be_deleted(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        $client = $this->client($company);
        $project = $this->project($company, $client);
        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-DEPENDENCY',
            'name' => 'Persona Dependencia',
            'modality' => 'HONORARIOS',
        ]);
        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-DEPENDENCY',
        ]);
        TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HOR-DEPENDENCY',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-01',
            'activity' => 'Prueba',
        ]);

        $response = $this->actingAs($admin)->delete(route('operational.destroy', ['projects', $project->id]));

        $response->assertRedirect(route('operational.show', ['projects', $project->id]));
        $response->assertSessionHasErrors('dependencies');
        $this->assertStringContainsString('asignaciones', $this->dependencyError($response));
        $this->assertStringContainsString('registros de horas', $this->dependencyError($response));
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_assignment_and_person_in_use_cannot_be_deleted_but_unreferenced_client_can(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        $client = $this->client($company);
        $project = $this->project($company, $client);
        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-ASSIGNED',
            'name' => 'Persona Asignada',
            'modality' => 'HONORARIOS',
        ]);
        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-IN-USE',
        ]);
        TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HOR-ASSIGNMENT',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-01',
            'activity' => 'Prueba',
        ]);

        $assignmentResponse = $this->actingAs($admin)->delete(route('operational.destroy', ['assignments', $assignment->id]));
        $assignmentResponse->assertSessionHasErrors('dependencies');

        $personResponse = $this->actingAs($admin)->delete(route('operational.destroy', ['people', $person->id]));
        $personResponse->assertSessionHasErrors('dependencies');

        $unusedClient = $this->client($company, 'CLI-FREE');
        $deleteUnused = $this->actingAs($admin)->delete(route('operational.destroy', ['clients', $unusedClient->id]));
        $deleteUnused->assertRedirect(route('operational.index', 'clients'));
        $this->assertDatabaseMissing('clients', ['id' => $unusedClient->id]);
    }

    public function test_expense_document_with_payments_cannot_be_deleted(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        $client = $this->client($company);
        $project = $this->project($company, $client);

        $expense = ExpenseDocument::query()->create([
            'company_id' => $company->id,
            'code' => 'EGR-DEP-001',
            'vendor_name' => 'Proveedor Dependencias',
            'client_id' => $client->id,
            'project_id' => $project->id,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'net_amount' => 100000,
            'vat_amount' => 19000,
            'gross_amount' => 119000,
            'paid_amount' => 0,
            'payment_status' => 'Pendiente',
        ]);

        CashMovement::query()->create([
            'company_id' => $company->id,
            'code' => 'MOV-DEP-001',
            'movement_type' => 'expense',
            'source_document_type' => 'expense_document',
            'source_document_code' => $expense->code,
            'project_id' => $project->id,
            'movement_date' => '2026-08-10',
            'income' => 0,
            'expense' => 119000,
            'status' => 'posted',
        ]);

        $response = $this->actingAs($admin)->delete(route('operational.destroy', ['expense-documents', $expense->id]));

        $response->assertRedirect(route('operational.show', ['expense-documents', $expense->id]));
        $response->assertSessionHasErrors('dependencies');
        $this->assertStringContainsString('movimientos de caja', $this->dependencyError($response));
        $this->assertDatabaseHas('expense_documents', ['id' => $expense->id]);
    }

    /** @return array{Company, User} */
    private function companyWithAdmin(): array
    {
        $company = Company::query()->create(['code' => 'CMP-DEP', 'name' => 'Empresa Dependencias', 'status' => 'active']);
        $admin = User::factory()->create(['company_id' => $company->id, 'role' => 'admin', 'active' => true]);

        return [$company, $admin];
    }

    private function client(Company $company, string $code = 'CLI-DEP'): Client
    {
        return Client::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'legal_name' => 'Cliente Dependencias '.$code,
            'status' => 'active',
        ]);
    }

    private function project(Company $company, Client $client): Project
    {
        return Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-DEP-'.$client->id,
            'name' => 'Proyecto Dependencias',
            'project_status' => 'active',
            'billing_status' => 'pending',
        ]);
    }

    private function dependencyError($response): string
    {
        return (string) $response->getSession()->get('errors')->first('dependencies');
    }
}
