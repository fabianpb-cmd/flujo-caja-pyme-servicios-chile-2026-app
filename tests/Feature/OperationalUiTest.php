<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Activity;
use App\Models\ApprovalStatus;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\EmploymentMode;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\LegalParameter;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\RecordStatus;
use App\Models\SalesDocument;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_document_requires_project_belonging_to_selected_client(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        [$clientA, $clientB, $projectA, $projectB] = $this->clientProjectFixtures($company->id);

        $form = $this->actingAs($admin)->get(route('operational.create', 'sales-documents'));
        $form->assertOk();
        $form->assertSee('data-parent-field="client_id"', false);
        $form->assertSee('Seleccione un cliente primero');
        $form->assertSee('No hay proyectos para este cliente');
        $form->assertSee('data-parent-id="'.$projectA->id.'"', false);

        $valid = $this->actingAs($admin)->post(route('operational.store', 'sales-documents'), [
            'code' => 'ING-UI-001',
            'client_id' => $clientA->id,
            'project_id' => $projectA->id,
            'document_type_id' => $this->documentTypeId($company->id, 'sales', 'FACTURA'),
            'issue_date' => '2026-08-01',
            'net_amount' => 1390112,
        ]);

        $valid->assertRedirect(route('operational.index', 'sales-documents'));

        $invalid = $this->actingAs($admin)->post(route('operational.store', 'sales-documents'), [
            'code' => 'ING-UI-002',
            'client_id' => $clientA->id,
            'project_id' => $projectB->id,
            'document_type_id' => $this->documentTypeId($company->id, 'sales', 'FACTURA'),
            'issue_date' => '2026-08-01',
            'net_amount' => 1000,
        ]);

        $invalid
            ->assertSessionHasErrors('project_id')
            ->assertSessionHasErrors([
                'project_id' => 'El proyecto seleccionado no pertenece al cliente indicado.',
            ]);
    }

    public function test_clients_format_rut_in_create_and_show_views(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-RUT-01',
            'legal_name' => 'Cliente RUT',
            'tax_id' => '12345678-5',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $create = $this->actingAs($admin)->get(route('operational.create', 'clients'));
        $create->assertOk();
        $create->assertSee('data-bs-title="Puede ingresarlo con o sin puntos. El sistema valida automáticamente el dígito verificador."', false);
        $create->assertSee('data-bs-title="Se utiliza para calcular automáticamente vencimientos cuando corresponda."', false);
        $create->assertSee('placeholder="12.345.678-5"', false);

        $show = $this->actingAs($admin)->get(route('operational.show', ['clients', $client->id]));
        $show->assertOk();
        $show->assertSee('12.345.678-5');
    }

    public function test_inactive_related_records_are_hidden_in_create_and_preserved_in_edit_history(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        [$clientA, , $projectA] = $this->clientProjectFixtures($company->id);

        $inactiveClientStatus = RecordStatus::query()->create([
            'company_id' => $company->id,
            'domain' => 'client',
            'code' => 'inactive_test',
            'name' => 'Inactivo prueba',
            'active' => true,
        ]);

        $inactiveProjectStatus = RecordStatus::query()->create([
            'company_id' => $company->id,
            'domain' => 'project',
            'code' => 'inactive_test',
            'name' => 'Inactivo prueba',
            'active' => true,
        ]);

        $inactiveClient = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-INACTIVE',
            'legal_name' => 'Cliente Inactivo',
            'client_status_id' => $inactiveClientStatus->id,
        ]);

        $inactiveProject = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $inactiveClient->id,
            'code' => 'PRY-INACTIVE',
            'name' => 'Proyecto Histórico',
            'project_status_id' => $inactiveProjectStatus->id,
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $create = $this->actingAs($admin)->get(route('operational.create', 'sales-documents'));
        $create->assertOk();
        $create->assertDontSee('Cliente Inactivo');
        $create->assertDontSee('Proyecto Histórico');

        $document = SalesDocument::query()->create([
            'company_id' => $company->id,
            'client_id' => $inactiveClient->id,
            'project_id' => $inactiveProject->id,
            'code' => 'ING-HIST',
            'document_type_id' => $this->documentTypeId($company->id, 'sales', 'FACTURA'),
            'document_type' => 'Factura',
            'issue_date' => '2026-06-15',
            'net_amount' => 1000,
            'vat_amount' => 190,
            'gross_amount' => 1190,
            'status' => 'Pendiente',
        ]);

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['sales-documents', $document->id]));
        $edit->assertOk();
        $edit->assertSee('Cliente Inactivo (No vigente)');
        $edit->assertSee('Proyecto Histórico (No vigente)');
    }

    public function test_assignments_use_single_hourly_rate_selector_and_share_unit_with_project_value(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TAR',
            'legal_name' => 'Cliente Tarificación',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TAR',
            'first_names' => 'Tarifa',
            'paternal_surname' => 'Unit',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $response = $this->actingAs($admin)->get(route('operational.create', 'assignments'));

        $response->assertOk();
        $response->assertSee('data-rate-unit-selector="true"', false);
        $response->assertSee('data-rate-unit-prefix-for="hourly_value"', false);
        $response->assertSee('data-rate-unit-prefix-for="project_value"', false);
        $response->assertDontSee('Moneda valor HH');
    }

    public function test_assignments_accept_projects_in_execution_for_the_selected_client(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-EXEC',
            'legal_name' => 'Cliente Ejecución',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-EXEC',
            'first_names' => 'Proyecto',
            'paternal_surname' => 'Ejecución',
            'name' => 'Proyecto Ejecución',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-EXEC',
            'name' => 'Proyecto En Ejecución',
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $form = $this->actingAs($admin)->get(route('operational.create', 'assignments'));

        $form->assertOk();
        $form->assertSee('Proyecto En Ejecución');
        $form->assertSee('data-parent-id="'.$project->id.'"', false);

        $response = $this->actingAs($admin)->post(route('operational.store', 'assignments'), [
            'code' => 'ASI-EXEC',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
        ]);

        $response->assertRedirect(route('operational.index', 'assignments'));
        $this->assertDatabaseHas('project_assignments', [
            'company_id' => $company->id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'code' => 'ASI-EXEC',
        ]);
    }

    public function test_time_entries_use_assignment_or_project_rate_and_show_unit_readonly(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-HH',
            'legal_name' => 'Cliente Horas',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $clp = Currency::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'CLP'],
            ['name' => 'Peso chileno', 'symbol' => '$', 'minor_units' => 0, 'is_base_currency' => true, 'active' => true, 'sort_order' => 1]
        );

        $personAssignment = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-HH-01',
            'first_names' => 'Tarifa',
            'paternal_surname' => 'Override',
            'name' => 'Tarifa Override',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $personProject = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-HH-02',
            'first_names' => 'Tarifa',
            'paternal_surname' => 'Project',
            'name' => 'Tarifa Project',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $clp->id,
            'code' => 'PRY-HH-01',
            'name' => 'Proyecto Horas',
            'contracted_hourly_rate' => 35000,
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $personAssignment->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-HH-01',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 1.75,
            'start_date' => '2026-08-01',
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $personProject->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-HH-02',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_value' => null,
            'start_date' => '2026-08-01',
        ]);

        $activity = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-HH'],
            ['name' => 'Actividad Horas', 'active' => true, 'sort_order' => 1]
        );
        $approvalStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );

        $create = $this->actingAs($admin)->get(route('operational.create', 'time-entries'));
        $create->assertOk();
        $create->assertSee('Tarifa aplicable', false);
        $create->assertSee('data-time-entry-rate-prefix', false);
        $create->assertSee('data-time-entry-rate-display', false);

        $override = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'code' => 'HOR-001',
            'person_id' => $personAssignment->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'entry_date' => '10/08/2026',
            'activity_id' => $activity->id,
            'hours_worked' => 10,
            'hours_approved' => 10,
            'hourly_value' => 999999,
            'approval_status_id' => $approvalStatus->id,
            'payment_status' => 'pending',
        ]);

        $override->assertRedirect(route('operational.index', 'time-entries'));
        $overrideEntry = TimeEntry::query()->where('code', 'HOR-001')->firstOrFail();
        $this->assertSame($client->id, $overrideEntry->client_id);
        $this->assertSame(1.75, (float) $overrideEntry->hourly_value);
        $this->assertSame(17.5, (float) $overrideEntry->calculated_amount);

        $projectRate = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'code' => 'HOR-002',
            'person_id' => $personProject->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'entry_date' => '10/08/2026',
            'activity_id' => $activity->id,
            'hours_worked' => 2,
            'hours_approved' => 2,
            'hourly_value' => 1,
            'approval_status_id' => $approvalStatus->id,
            'payment_status' => 'pending',
        ]);

        $projectRate->assertRedirect(route('operational.index', 'time-entries'));
        $projectEntry = TimeEntry::query()->where('code', 'HOR-002')->firstOrFail();
        $this->assertSame($client->id, $projectEntry->client_id);
        $this->assertSame(35000.0, (float) $projectEntry->hourly_value);
        $this->assertSame(70000.0, (float) $projectEntry->calculated_amount);
    }

    public function test_people_and_assignments_rate_rows_keep_horizontal_dom_structure(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-LAYOUT',
            'legal_name' => 'Cliente Layout',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-LAYOUT',
            'name' => 'Proyecto Layout',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-LAYOUT',
            'first_names' => 'Layout',
            'paternal_surname' => 'Test',
            'name' => 'Layout Test',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 1.5,
            'monthly_hours' => 160,
            'start_date' => '2026-08-01',
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-LAYOUT',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 1.5,
            'monthly_hours' => 160,
            'start_date' => '2026-08-01',
        ]);

        $peopleCreate = $this->actingAs($admin)->get(route('operational.create', 'people'));
        $peopleCreate->assertOk();
        $this->assertHorizontalRowLabels($peopleCreate->getContent(), [
            'Unidad valor HH',
            'Valor HH',
            'Horas mensuales',
        ]);
        $this->assertHorizontalRowLabels($peopleCreate->getContent(), [
            'Estado',
            'Fecha inicio',
            'Fecha termino',
        ]);

        $peopleEdit = $this->actingAs($admin)->get(route('operational.edit', ['people', $person->id]));
        $peopleEdit->assertOk();
        $this->assertHorizontalRowLabels($peopleEdit->getContent(), [
            'Unidad valor HH',
            'Valor HH',
            'Horas mensuales',
        ]);
        $this->assertHorizontalRowLabels($peopleEdit->getContent(), [
            'Estado',
            'Fecha inicio',
            'Fecha termino',
        ]);

        $assignCreate = $this->actingAs($admin)->get(route('operational.create', 'assignments'));
        $assignCreate->assertOk();
        $this->assertHorizontalRowLabels($assignCreate->getContent(), [
            'Persona',
            'Proyecto',
        ]);
        $this->assertHorizontalRowLabels($assignCreate->getContent(), [
            'Unidad valor HH',
            'Valor HH',
        ]);

        $assignEdit = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $assignment->id]));
        $assignEdit->assertOk();
        $this->assertHorizontalRowLabels($assignEdit->getContent(), [
            'Persona',
            'Proyecto',
        ]);
        $this->assertHorizontalRowLabels($assignEdit->getContent(), [
            'Unidad valor HH',
            'Valor HH',
        ]);
    }

    public function test_expense_subcategory_dependency_is_enforced_in_form_and_backend(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $categoryA = ExpenseCategory::query()->create([
            'company_id' => $company->id,
            'code' => 'OPERACION',
            'name' => 'Operación',
            'active' => true,
        ]);

        $categoryB = ExpenseCategory::query()->create([
            'company_id' => $company->id,
            'code' => 'ADMIN',
            'name' => 'Administración',
            'active' => true,
        ]);

        $subcategory = ExpenseSubcategory::query()->create([
            'company_id' => $company->id,
            'expense_category_id' => $categoryA->id,
            'code' => 'CLOUD',
            'name' => 'Servicios cloud',
            'active' => true,
        ]);

        $form = $this->actingAs($admin)->get(route('operational.create', 'expense-documents'));
        $form->assertOk();
        $form->assertSee('Seleccione una categoría primero');
        $form->assertSee('No hay subcategorías para esta categoría');

        $invalid = $this->actingAs($admin)->post(route('operational.store', 'expense-documents'), [
            'code' => 'EGR-UI-001',
            'expense_category_id' => $categoryB->id,
            'expense_subcategory_id' => $subcategory->id,
            'document_type_id' => $this->documentTypeId($company->id, 'expense', 'FACTURA_COMPRA'),
            'issue_date' => '2026-08-01',
            'net_amount' => 1000,
        ]);

        $invalid
            ->assertSessionHasErrors('expense_subcategory_id')
            ->assertSessionHasErrors([
                'expense_subcategory_id' => 'La subcategoría seleccionada no pertenece a la categoría indicada.',
            ]);
    }

    public function test_sales_documents_recalculate_vat_and_total_and_format_date_in_edit_form(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        [$clientA, , $projectA] = $this->clientProjectFixtures($company->id);

        $response = $this->actingAs($admin)->post(route('operational.store', 'sales-documents'), [
            'code' => 'ING-CALC-001',
            'client_id' => $clientA->id,
            'project_id' => $projectA->id,
            'document_type_id' => $this->documentTypeId($company->id, 'sales', 'FACTURA'),
            'issue_date' => '09/08/2026',
            'net_amount' => 1000000,
            'vat_amount' => 1,
            'gross_amount' => 2,
        ]);

        $response->assertRedirect(route('operational.index', 'sales-documents'));

        $document = SalesDocument::query()->where('code', 'ING-CALC-001')->firstOrFail();
        $this->assertSame('2026-08-09', $document->issue_date->toDateString());
        $this->assertSame(0.19, (float) $document->vat_rate);
        $this->assertSame(190000.0, (float) $document->vat_amount);
        $this->assertSame(1190000.0, (float) $document->gross_amount);

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['sales-documents', $document->id]));
        $edit->assertOk();
        $edit->assertSee('value="09/08/2026"', false);
        $edit->assertSee('value="19 %"', false);
        $edit->assertSee('value="$ 1.190.000"', false);
    }

    public function test_payroll_project_options_and_backend_require_assignment_for_period(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        [$clientA, , $projectA, $projectB] = $this->clientProjectFixtures($company->id);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-ASSIGN-01',
            'first_names' => 'Claudia',
            'paternal_surname' => 'Ramírez',
            'name' => 'Claudia Ramírez',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $clientA->id,
            'project_id' => $projectA->id,
            'code' => 'ASI-001',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-30',
        ]);

        $form = $this->actingAs($admin)->get(route('operational.create', 'payroll-records'));
        $form->assertOk();
        $form->assertSee('Seleccione una persona primero');
        $form->assertSee('Seleccione el período primero');
        $form->assertSee('No existen proyectos asignados para esta persona en el período.');
        $form->assertSee('data-assignment-ranges=', false);

        $valid = $this->actingAs($admin)->post(route('operational.store', 'payroll-records'), [
            'person_id' => $person->id,
            'project_id' => $projectA->id,
            'period_date' => '2026-05-01',
        ]);
        $valid->assertRedirect(route('operational.index', 'payroll-records'));

        $invalid = $this->actingAs($admin)->post(route('operational.store', 'payroll-records'), [
            'person_id' => $person->id,
            'project_id' => $projectB->id,
            'period_date' => '2026-05-01',
        ]);
        $invalid->assertSessionHasErrors([
            'project_id' => 'La persona no se encuentra asignada al proyecto seleccionado para el período indicado.',
        ]);

        $outOfPeriod = $this->actingAs($admin)->post(route('operational.store', 'payroll-records'), [
            'person_id' => $person->id,
            'project_id' => $projectA->id,
            'period_date' => '2026-08-01',
        ]);
        $outOfPeriod->assertSessionHasErrors([
            'project_id' => 'La persona no se encuentra asignada al proyecto seleccionado para el período indicado.',
        ]);

        $payroll = \App\Models\PayrollRecord::query()->where('person_id', $person->id)->whereDate('period_date', '2026-05-01')->firstOrFail();
        $edit = $this->actingAs($admin)->get(route('operational.edit', ['payroll-records', $payroll->id]));
        $edit->assertOk();
        $edit->assertSee('Proyecto A');
    }

    public function test_clients_projects_people_and_sales_documents_support_safe_sorting_and_keep_query(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        Client::query()->create(['company_id' => $company->id, 'code' => 'CLI-03', 'legal_name' => 'Zulu SPA', 'client_status_id' => $this->statusId($company->id, 'client', 'active')]);
        Client::query()->create(['company_id' => $company->id, 'code' => 'CLI-01', 'legal_name' => 'Alpha SPA', 'client_status_id' => $this->statusId($company->id, 'client', 'active')]);
        Client::query()->create(['company_id' => $company->id, 'code' => 'CLI-02', 'legal_name' => 'Beta SPA', 'client_status_id' => $this->statusId($company->id, 'client', 'active')]);

        $client = Client::query()->where('legal_name', 'Alpha SPA')->firstOrFail();

        Project::query()->create(['company_id' => $company->id, 'client_id' => $client->id, 'code' => 'PRY-03', 'name' => 'Zulu Proyecto', 'project_status_id' => $this->statusId($company->id, 'project', 'active'), 'billing_status_id' => $this->statusId($company->id, 'billing', 'pending')]);
        Project::query()->create(['company_id' => $company->id, 'client_id' => $client->id, 'code' => 'PRY-01', 'name' => 'Alpha Proyecto', 'project_status_id' => $this->statusId($company->id, 'project', 'active'), 'billing_status_id' => $this->statusId($company->id, 'billing', 'pending')]);

        Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-02',
            'first_names' => 'Bruno',
            'paternal_surname' => 'Zulueta',
            'name' => 'Bruno Zulueta',
            'modality' => 'Dependiente mensual',
            'status' => 'active',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);
        Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-01',
            'first_names' => 'Ana',
            'paternal_surname' => 'Albornoz',
            'name' => 'Ana Albornoz',
            'modality' => 'Dependiente mensual',
            'status' => 'active',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        SalesDocument::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'ING-LOW',
            'document_type_id' => $this->documentTypeId($company->id, 'sales', 'FACTURA'),
            'document_type' => 'Factura',
            'issue_date' => '2026-08-01',
            'net_amount' => 1000,
            'vat_amount' => 190,
            'gross_amount' => 1190,
            'status' => 'Pendiente',
        ]);
        SalesDocument::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'ING-HIGH',
            'document_type_id' => $this->documentTypeId($company->id, 'sales', 'FACTURA'),
            'document_type' => 'Factura',
            'issue_date' => '2026-08-15',
            'net_amount' => 1390112,
            'vat_amount' => 264121,
            'gross_amount' => 1654233,
            'status' => 'Pendiente',
        ]);

        $clientsAsc = $this->actingAs($admin)->get(route('operational.index', ['resource' => 'clients', 'sort' => 'legal_name', 'direction' => 'asc', 'q' => 'SPA']));
        $clientsAsc->assertOk()->assertSeeInOrder(['Alpha SPA', 'Beta SPA', 'Zulu SPA']);
        $clientsAsc->assertSee('sort=legal_name', false);
        $clientsAsc->assertSee('direction=desc', false);
        $clientsAsc->assertSee('q=SPA', false);
        $clientsAsc->assertSee('class="table-actions"', false);

        $clientsDesc = $this->actingAs($admin)->get(route('operational.index', ['resource' => 'clients', 'sort' => 'legal_name', 'direction' => 'desc']));
        $clientsDesc->assertOk()->assertSeeInOrder(['Zulu SPA', 'Beta SPA', 'Alpha SPA']);

        $projectsAsc = $this->actingAs($admin)->get(route('operational.index', ['resource' => 'projects', 'sort' => 'name', 'direction' => 'asc']));
        $projectsAsc->assertOk()->assertSeeInOrder(['Alpha Proyecto', 'Zulu Proyecto']);

        $peopleAsc = $this->actingAs($admin)->get(route('operational.index', ['resource' => 'people', 'sort' => 'full_name', 'direction' => 'asc']));
        $peopleAsc->assertOk()->assertSeeInOrder(['Albornoz', 'Zulueta']);

        $salesAsc = $this->actingAs($admin)->get(route('sales-documents.index', ['sort' => 'net_amount', 'direction' => 'asc']));
        $salesAsc->assertOk()->assertSeeInOrder(['ING-LOW', 'ING-HIGH']);
        $salesAsc->assertSee('$ 1.390.112');

        $salesDesc = $this->actingAs($admin)->get(route('sales-documents.index', ['sort' => 'issue_date', 'direction' => 'desc']));
        $salesDesc->assertOk()->assertSeeInOrder(['ING-HIGH', 'ING-LOW']);
    }

    public function test_projects_use_sales_currency_and_validate_scale_by_currency(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-CUR',
            'legal_name' => 'Cliente Moneda',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $usd = $this->currency($company->id, 'USD', 'Dólar de prueba');

        $form = $this->actingAs($admin)->get(route('operational.create', 'projects'));
        $form->assertOk();
        $form->assertSee('¿Cómo completar el proyecto?', false);
        $form->assertSee('Moneda de venta', false);
        $form->assertSee('data-bs-title="Moneda utilizada para registrar las ventas del proyecto."', false);
        $form->assertSee('data-bs-title="Venta antes de IVA, expresada en la moneda definida para el proyecto."', false);

        $clp = $this->actingAs($admin)->post(route('operational.store', 'projects'), [
            'code' => 'PRY-CLP-01',
            'client_id' => $client->id,
            'sales_currency_id' => $this->currency($company->id, 'CLP', 'Peso chileno')->id,
            'name' => 'Proyecto CLP',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
            'sale_net' => 1250,
        ]);
        $clp->assertRedirect(route('operational.index', 'projects'));

        $validUsd = $this->actingAs($admin)->post(route('operational.store', 'projects'), [
            'code' => 'PRY-USD-01',
            'client_id' => $client->id,
            'sales_currency_id' => $usd->id,
            'name' => 'Proyecto USD',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
            'sale_net' => 1250.50,
        ]);
        $validUsd->assertRedirect(route('operational.index', 'projects'));

        $project = Project::query()->where('company_id', $company->id)->where('code', 'PRY-USD-01')->firstOrFail();
        $this->assertSame($usd->id, (int) $project->sales_currency_id);
        $this->assertSame('US$ 1.250,50', \App\Support\UiFormatter::formatMoney($project->sale_net, $project->salesCurrency));
        $this->assertSame('US$ 1.488,10', \App\Support\UiFormatter::formatMoney($project->sale_total, $project->salesCurrency));
    }

    public function test_project_edit_saves_chilean_dates_and_create_restores_old_input(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        [$clientA] = $this->clientProjectFixtures($company->id);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $clientA->id,
            'code' => 'PRY-DATES',
            'name' => 'Proyecto Fechas',
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-31',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['projects', $project->id]));
        $edit->assertOk();
        $edit->assertSee('10/08/2026');
        $edit->assertSee('31/08/2026');

        $save = $this->actingAs($admin)->put(route('operational.update', ['projects', $project->id]), [
            'code' => $project->code,
            'client_id' => $clientA->id,
            'sales_currency_id' => $project->sales_currency_id,
            'name' => $project->name,
            'project_type_id' => $project->project_type_id,
            'manager_id' => $project->manager_id,
            'start_date' => '10/08/2026',
            'end_date' => '31/08/2026',
            'contract_type_id' => $project->contract_type_id,
            'payment_term_id' => $project->payment_term_id,
            'sale_net' => $project->sale_net,
            'vat_rate' => $project->vat_rate,
            'sale_total' => $project->sale_total,
            'project_status_id' => $project->project_status_id,
            'billing_status_id' => $project->billing_status_id,
        ]);
        $save->assertRedirect(route('operational.show', ['projects', $project->id]));

        $project->refresh();
        $this->assertSame('2026-08-10', $project->start_date?->toDateString());
        $this->assertSame('2026-08-31', $project->end_date?->toDateString());

        $invalid = $this->actingAs($admin)->followingRedirects()->post(route('operational.store', 'projects'), [
            'code' => 'PRY-DATES-OLD',
            'client_id' => $clientA->id,
            'sales_currency_id' => $project->sales_currency_id,
            'name' => '',
            'project_type_id' => $project->project_type_id,
            'manager_id' => $project->manager_id,
            'start_date' => '10/08/2026',
            'end_date' => '31/08/2026',
            'contract_type_id' => $project->contract_type_id,
            'payment_term_id' => $project->payment_term_id,
            'sale_net' => $project->sale_net,
            'vat_rate' => $project->vat_rate,
            'sale_total' => $project->sale_total,
            'project_status_id' => $project->project_status_id,
            'billing_status_id' => $project->billing_status_id,
        ]);
        $invalid->assertOk();
        $invalid->assertSee('10/08/2026');
        $invalid->assertSee('31/08/2026');
    }

    public function test_operational_tables_render_actions_before_code_and_keep_them_sticky(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-99',
            'legal_name' => 'Sticky SPA',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $response = $this->actingAs($admin)->get(route('operational.index', ['resource' => 'clients']));
        $response->assertOk();
        $response->assertSee('table-actions-column', false);
        $response->assertSee('table-code-column', false);
        $this->assertMatchesRegularExpression('/<th[^>]*table-actions-head[^>]*>Acciones<\/th>.*<th[^>]*table-code-head/s', $response->getContent());
        $this->assertMatchesRegularExpression('/<td[^>]*table-actions-column[^>]*>.*?<td[^>]*table-code-column/s', $response->getContent());
    }

    public function test_payroll_form_displays_collapsed_help_and_field_tooltips(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-REM-01',
            'first_names' => 'Juan',
            'paternal_surname' => 'Pérez',
            'maternal_surname' => 'Soto',
            'name' => 'Juan Pérez Soto',
            'modality' => 'Dependiente mensual',
            'contract_type' => 'Indefinido',
            'monthly_value' => 1000000,
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'employment_contract_type_id' => \App\Models\ContractType::query()->where('company_id', $company->id)->where('domain', 'employment')->where('code', 'INDEFINIDO')->valueOrFail('id'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $response = $this->actingAs($admin)->get(route('operational.create', 'payroll-records'));

        $response->assertOk();
        $response->assertSee('¿Cómo usar esta pantalla?', false);
        $response->assertSee('Ver detalle de conceptos', false);
        $response->assertSee('payroll-help-shell', false);
        $response->assertSee('id="payrollUsageHelp"', false);
        $response->assertSee('class="collapse mt-3"', false);
        $response->assertSee('data-bs-toggle="tooltip"', false);
        $response->assertSee('Días remunerados', false);
        $response->assertSee('Provisión vacaciones', false);
        $response->assertSee('payrollPersonSummary', false);
        $response->assertSee('data-payroll-mode="DEPENDIENTE_MENSUAL"', false);
        $response->assertSee('data-payroll-contract-label="Indefinido"', false);
    }

    public function test_payroll_edit_form_shows_person_header_and_chilean_formatted_calculated_values(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-REM-02',
            'first_names' => 'Pedro',
            'paternal_surname' => 'González',
            'maternal_surname' => 'Rojas',
            'name' => 'Pedro González Rojas',
            'modality' => 'Honorarios mensual',
            'monthly_value' => 100000,
            'employment_mode_id' => $this->employmentModeId($company->id, 'HONORARIOS_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $payroll = \App\Models\PayrollRecord::query()->create([
            'company_id' => $company->id,
            'code' => 'REM-UI-01',
            'person_id' => $person->id,
            'period_date' => '2026-07-01',
            'base_salary' => 100000,
            'employee_retention' => 15250,
            'net_pay' => 84750,
            'calculation_status' => 'OK',
            'legal_snapshot' => ['honorarios_retention_rate' => 0.1525],
            'status' => 'Pendiente',
        ]);

        $response = $this->actingAs($admin)->get(route('operational.edit', ['payroll-records', $payroll->id]));

        $response->assertOk();
        $response->assertSee('Pedro González Rojas');
        $response->assertSee('Honorarios mensual');
        $response->assertSee('$ 100.000');
        $response->assertSee('$ 15.250');
        $response->assertSee('$ 84.750');
    }

    public function test_clients_and_projects_generate_immutable_codes_when_omitted(): void
    {
        [$company] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'legal_name' => 'Cliente Sin Código',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'name' => 'Proyecto Sin Código',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $this->assertMatchesRegularExpression('/^CLI-\\d{6,}$/', $client->code);
        $this->assertMatchesRegularExpression('/^PRY-\\d{6,}$/', $project->code);

        $clientCode = $client->code;
        $projectCode = $project->code;

        $client->forceFill(['code' => 'CLI-HACK'])->save();
        $project->forceFill(['code' => 'PRY-HACK'])->save();

        $this->assertSame($clientCode, $client->refresh()->code);
        $this->assertSame($projectCode, $project->refresh()->code);
    }

    private function companyWithAdmin(): array
    {
        $company = Company::query()->create([
            'code' => 'CMP-UI',
            'name' => 'Empresa UI',
            'status' => 'active',
        ]);

        $admin = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Admin UI',
            'email' => 'ui@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        app(CatalogService::class)->seedDefaultsForCompany($company->id);
        LegalParameter::query()->create([
            'company_id' => $company->id,
            'parameter_code' => 'IVA',
            'parameter_name' => 'IVA',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'value' => 0.19,
            'unit' => '%',
        ]);

        return [$company, $admin];
    }

    private function currency(int $companyId, string $code, string $name): Currency
    {
        return Currency::query()->updateOrCreate(
            ['company_id' => $companyId, 'code' => $code],
            [
                'name' => $name,
                'symbol' => match ($code) {
                    'CLP' => '$',
                    'USD' => 'US$',
                    'EUR' => '€',
                    'UF' => 'UF',
                    default => $code,
                },
                'minor_units' => $code === 'CLP' ? 0 : 2,
                'active' => true,
                'sort_order' => 999,
            ]
        );
    }

    private function clientProjectFixtures(int $companyId): array
    {
        $clientA = Client::query()->create([
            'company_id' => $companyId,
            'code' => 'CLI-A',
            'legal_name' => 'Cliente A',
            'client_status_id' => $this->statusId($companyId, 'client', 'active'),
        ]);

        $clientB = Client::query()->create([
            'company_id' => $companyId,
            'code' => 'CLI-B',
            'legal_name' => 'Cliente B',
            'client_status_id' => $this->statusId($companyId, 'client', 'active'),
        ]);

        $projectA = Project::query()->create([
            'company_id' => $companyId,
            'client_id' => $clientA->id,
            'code' => 'PRY-A',
            'name' => 'Proyecto A',
            'project_status_id' => $this->statusId($companyId, 'project', 'active'),
            'billing_status_id' => $this->statusId($companyId, 'billing', 'pending'),
        ]);

        $projectB = Project::query()->create([
            'company_id' => $companyId,
            'client_id' => $clientB->id,
            'code' => 'PRY-B',
            'name' => 'Proyecto B',
            'project_status_id' => $this->statusId($companyId, 'project', 'active'),
            'billing_status_id' => $this->statusId($companyId, 'billing', 'pending'),
        ]);

        return [$clientA, $clientB, $projectA, $projectB];
    }

    private function statusId(int $companyId, string $domain, string $code): int
    {
        return RecordStatus::query()
            ->where('company_id', $companyId)
            ->where('domain', $domain)
            ->where('code', $code)
            ->valueOrFail('id');
    }

    private function documentTypeId(int $companyId, string $domain, string $code): int
    {
        return DocumentType::query()
            ->where('company_id', $companyId)
            ->where('domain', $domain)
            ->where('code', $code)
            ->valueOrFail('id');
    }

    private function employmentModeId(int $companyId, string $code): int
    {
        return EmploymentMode::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->valueOrFail('id');
    }

    private function assertHorizontalRowLabels(string $html, array $labels): void
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $rowPath = null;

        foreach ($labels as $label) {
            $labelNode = $xpath->query(sprintf('//label[contains(normalize-space(string(.)), %s)]', $this->xpathLiteral($label)))->item(0);
            $this->assertNotNull($labelNode, "No se encontró el label {$label}");

            $colNode = $this->ancestorByClass($xpath, $labelNode, 'col-');
            $this->assertNotNull($colNode, "No se encontró la columna de {$label}");

            $currentRow = $this->ancestorByClass($xpath, $colNode, 'row');
            $this->assertNotNull($currentRow, "No se encontró la row de {$label}");

            $currentPath = $currentRow->getNodePath();
            $rowPath ??= $currentPath;
            $this->assertSame($rowPath, $currentPath, "{$label} no comparte la misma row");
        }

        $rowNode = $xpath->query($rowPath)->item(0);
        $this->assertNotNull($rowNode, 'No se pudo localizar la row validada');

        $directColumns = $xpath->query('./div[contains(@class, "col-")]', $rowNode);
        $renderedLabels = [];
        foreach ($directColumns as $column) {
            $columnLabels = $xpath->query('.//label', $column);
            if ($columnLabels->length > 0) {
                $renderedLabels[] = trim(preg_replace('/\s+/u', ' ', $columnLabels->item(0)->textContent));
            }
        }

        foreach ($labels as $label) {
            $this->assertTrue(
                collect($renderedLabels)->contains(fn (string $rendered) => str_contains($rendered, $label)),
                "La row no contiene el label {$label} como columna hija directa"
            );
        }
    }

    private function ancestorByClass(\DOMXPath $xpath, \DOMNode $node, string $classFragment): ?\DOMNode
    {
        return $xpath->query(sprintf('ancestor::div[contains(@class, %s)][1]', $this->xpathLiteral($classFragment)), $node)->item(0) ?: null;
    }

    private function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'{$value}'";
        }

        $parts = explode("'", $value);
        $quoted = array_map(static fn (string $part) => "'{$part}'", $parts);

        return 'concat('.implode(", \"'\", ", $quoted).')';
    }
}
