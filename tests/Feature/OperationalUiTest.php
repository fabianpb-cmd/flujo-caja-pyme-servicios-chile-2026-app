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
use App\Models\PayrollAdjustment;
use App\Models\PayrollRecord;
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

    public function test_assignments_show_updated_guidance_and_project_vigency_metadata(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $uf = Currency::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'UF'],
            [
                'name' => 'Unidad de Fomento',
                'symbol' => 'UF',
                'minor_units' => 2,
                'is_base_currency' => false,
                'active' => true,
                'sort_order' => 2,
            ]
        );

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-GUIDE',
            'legal_name' => 'Cliente Guía',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $uf->id,
            'code' => 'PRY-GUIDE',
            'name' => 'Proyecto Guía',
            'sale_net' => 160,
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $response = $this->actingAs($admin)->get(route('operational.create', 'assignments'));

        $response->assertOk();
        $response->assertSeeText('Nueva asignación');
        $response->assertSeeText('Operación / Asignaciones / Nueva asignación');
        $response->assertDontSeeText('Nuevo Asignaciones');
        $response->assertSeeText('Unidad del valor HH');
        $response->assertSeeText('Valor HH');
        $response->assertSeeText('Monto pactado de la asignación');
        $response->assertSeeText('Horas mensuales');
        $response->assertSeeText('Fecha inicio');
        $response->assertSeeText('Fecha término');
        $response->assertSeeText('Centro de costo');
        $response->assertSee('data-bs-toggle="tooltip"', false);
        $response->assertSeeText('Los cálculos financieros asociados se actualizan al guardar.');
        $response->assertSeeText('Usa Valor HH cuando el acuerdo considera una tarifa por cada hora de trabajo registrada.');
        $response->assertSeeText('Usa Monto pactado de la asignación cuando existe un monto fijo para la participación o para un hito acordado.');
        $response->assertSeeText('Un valor 0,00 significa que esa modalidad no se utilizará en la asignación.');
        $response->assertSeeText('Si completas Valor HH y Monto pactado de la asignación, el sistema mostrará una advertencia para que revises el acuerdo contractual.');
        $response->assertSeeText('Las fechas corresponden a la vigencia de la asignación y pueden diferir de las del proyecto, pero se advertirá si quedan fuera de su rango.');
        $response->assertSeeText('Referencia del proyecto');
        $response->assertSeeText('Venta neta proyecto: Seleccione un proyecto.');
        $response->assertSeeText('Vigencia proyecto: Seleccione un proyecto.');
        $response->assertSee('data-project-start-date="2026-08-01"', false);
        $response->assertSee('data-project-end-date="2026-09-30"', false);
        $response->assertSee('syncAssignmentContext();', false);
        $response->assertSee('assignmentStartInput?.addEventListener(\'input\', syncAssignmentContext);', false);
        $response->assertSee('assignmentEndInput?.addEventListener(\'input\', syncAssignmentContext);', false);
        $response->assertDontSee('data-assignments-warning-box', false);
    }

    public function test_assignments_bind_reactive_warnings_to_the_operational_form(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-WARN',
            'legal_name' => 'Cliente Warning',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-WARN',
            'first_names' => 'Warning',
            'paternal_surname' => 'Reactive',
            'name' => 'Warning Reactive',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-WARN',
            'name' => 'Proyecto Warning',
            'sale_net' => 160,
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-WARN',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.5,
            'project_value' => 190,
            'monthly_hours' => 160,
            'start_date' => '2026-08-01',
        ]);

        $create = $this->actingAs($admin)->get(route('operational.create', 'assignments'));
        $create->assertOk();
        $create->assertSee('data-operational-form="true"', false);
        $create->assertSee('document.querySelector(\'[data-operational-form="true"]\')', false);
        $create->assertSee('assignmentHourlyInput?.addEventListener(\'input\', syncAssignmentContext);', false);
        $create->assertSee('assignmentHourlyInput?.addEventListener(\'change\', syncAssignmentContext);', false);
        $create->assertSee('assignmentProjectInput?.addEventListener(\'input\', syncAssignmentContext);', false);
        $create->assertSee('assignmentProjectInput?.addEventListener(\'change\', syncAssignmentContext);', false);
        $create->assertSee('assignmentProjectSelect?.addEventListener(\'change\', syncAssignmentContext);', false);
        $create->assertSee('assignmentStartInput?.addEventListener(\'input\', syncAssignmentContext);', false);
        $create->assertSee('assignmentEndInput?.addEventListener(\'input\', syncAssignmentContext);', false);
        $create->assertSee('data-assignments-tariff-warning-box', false);
        $create->assertSee('data-assignments-vigency-warning-box', false);
        $create->assertDontSee('data-assignments-warning-box', false);

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $assignment->id]));
        $edit->assertOk();
        $edit->assertSee('Se ingresó una tarifa por hora y un monto fijo.', false);
        $edit->assertSee('El monto pactado de la asignación supera la venta neta del proyecto.', false);
        $edit->assertSee('data-assignments-tariff-warning-box', false);
        $edit->assertSee('data-assignments-vigency-warning-box', false);
        $edit->assertDontSee('data-assignments-warning-box', false);
    }

    public function test_assignments_validate_schema_limits_before_persisting_and_preserve_selected_project_context(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $uf = Currency::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'UF'],
            [
                'name' => 'Unidad de Fomento',
                'symbol' => 'UF',
                'minor_units' => 2,
                'is_base_currency' => false,
                'active' => true,
                'sort_order' => 2,
            ]
        );

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-LIMIT',
            'legal_name' => 'Cliente Límite',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-LIMIT',
            'first_names' => 'Jaime',
            'paternal_surname' => 'Soriano',
            'name' => 'Jaime Soriano',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $uf->id,
            'code' => 'PRY-LIMIT',
            'name' => 'Alertas de Matrículas',
            'sale_net' => 160,
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $response = $this->actingAs($admin)
            ->from(route('operational.create', 'assignments'))
            ->followingRedirects()
            ->post(route('operational.store', 'assignments'), [
                'code' => 'ASI-LIMIT-ERR',
                'person_id' => $person->id,
                'client_id' => $client->id,
                'project_id' => $project->id,
                'hourly_rate_unit_type' => 'UF',
                'hourly_value' => '8888888',
                'project_value' => '000000',
                'monthly_hours' => '3000000',
                'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            ]);

        $response->assertOk();
        $response->assertSee('Horas mensuales no puede superar 744.');
        $response->assertSee('value="000000"', false);
        $response->assertSee('Venta neta proyecto: UF 160,00');
        $response->assertSee('Vigencia proyecto: No informada');
        $response->assertSee('<div class="d-none" data-assignments-warning-double>', false);
        $response->assertSee('max="744"', false);

        $this->assertDatabaseMissing('project_assignments', [
            'company_id' => $company->id,
            'code' => 'ASI-LIMIT-ERR',
        ]);
    }

    public function test_assignments_show_project_vigency_warnings_in_edit_when_dates_fall_outside_range(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-VIG',
            'legal_name' => 'Cliente Vigencia',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-VIG',
            'first_names' => 'Vigencia',
            'paternal_surname' => 'Proyecto',
            'name' => 'Vigencia Proyecto',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-VIG',
            'name' => 'Proyecto Vigente',
            'sale_net' => 160,
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $within = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-VIG-OK',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-08-10',
            'end_date' => '2026-09-20',
        ]);

        $startOutside = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-VIG-START',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-07-20',
            'end_date' => '2026-09-20',
        ]);

        $endOutside = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-VIG-END',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-08-10',
            'end_date' => '2026-10-15',
        ]);

        $bothOutside = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-VIG-BOTH',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-07-20',
            'end_date' => '2026-10-15',
        ]);

        $withinEdit = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $within->id]));
        $withinEdit->assertOk();
        $withinEdit->assertSee('Vigencia proyecto: 01/08/2026 al 30/09/2026');
        $withinEdit->assertSee('<div class="d-none" data-assignments-warning-vigency>', false);

        $startEdit = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $startOutside->id]));
        $startEdit->assertOk();
        $startEdit->assertSee('La vigencia de la asignación inicia antes de la vigencia del proyecto seleccionado.');

        $endEdit = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $endOutside->id]));
        $endEdit->assertOk();
        $endEdit->assertSee('La vigencia de la asignación termina después de la vigencia del proyecto seleccionado.');

        $bothEdit = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $bothOutside->id]));
        $bothEdit->assertOk();
        $bothEdit->assertSee('La vigencia de la asignación inicia antes y termina después de la vigencia del proyecto seleccionado.');
    }

    public function test_assignments_reject_inverted_date_ranges_with_standard_validation(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-DATE',
            'legal_name' => 'Cliente Fechas',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-DATE',
            'first_names' => 'Fecha',
            'paternal_surname' => 'Invertida',
            'name' => 'Fecha Invertida',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-DATE',
            'name' => 'Proyecto Fecha',
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $response = $this->actingAs($admin)
            ->from(route('operational.create', 'assignments'))
            ->post(route('operational.store', 'assignments'), [
                'code' => 'ASI-DATE-ERR',
                'person_id' => $person->id,
                'client_id' => $client->id,
                'project_id' => $project->id,
                'start_date' => '20/09/2026',
                'end_date' => '10/08/2026',
                'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            ]);

        $response->assertSessionHasErrors('end_date');

        $this->assertDatabaseMissing('project_assignments', [
            'company_id' => $company->id,
            'code' => 'ASI-DATE-ERR',
        ]);
    }

    public function test_assignments_accept_physical_monthly_hours_limit(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-MAX',
            'legal_name' => 'Cliente Máximo',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-MAX',
            'first_names' => 'Máximo',
            'paternal_surname' => 'Seguro',
            'name' => 'Máximo Seguro',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-MAX',
            'name' => 'Proyecto Máximo',
            'sale_net' => 1000,
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $response = $this->actingAs($admin)->post(route('operational.store', 'assignments'), [
            'code' => 'ASI-LIMIT-OK',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => '9999999999999999.99',
            'project_value' => '0',
            'monthly_hours' => '744',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
        ]);

        $response->assertRedirect(route('operational.index', 'assignments'));

        $this->assertDatabaseHas('project_assignments', [
            'company_id' => $company->id,
            'code' => 'ASI-LIMIT-OK',
            'monthly_hours' => 744,
        ]);
    }

    public function test_assignments_reject_monthly_hours_above_physical_limit(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-HOURS',
            'legal_name' => 'Cliente Horas',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-HOURS',
            'first_names' => 'Horas',
            'paternal_surname' => 'Máximas',
            'name' => 'Horas Máximas',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-HOURS',
            'name' => 'Proyecto Horas',
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        foreach (['745', '10000'] as $hours) {
            $response = $this->actingAs($admin)
                ->from(route('operational.create', 'assignments'))
                ->post(route('operational.store', 'assignments'), [
                    'code' => 'ASI-HOURS-'.$hours,
                    'person_id' => $person->id,
                    'client_id' => $client->id,
                    'project_id' => $project->id,
                    'hourly_rate_unit_type' => 'UF',
                    'hourly_value' => '1.25',
                    'project_value' => '0',
                    'monthly_hours' => $hours,
                    'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
                ]);

            $response->assertSessionHasErrors([
                'monthly_hours' => 'Horas mensuales no puede superar 744.',
            ]);
        }
    }

    public function test_assignments_allow_monthly_hours_as_reference_without_hourly_value(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-REF-HOURS',
            'legal_name' => 'Cliente Referencia Horas',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-REF-HOURS',
            'first_names' => 'Referencia',
            'paternal_surname' => 'Horas',
            'name' => 'Referencia Horas',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-REF-HOURS',
            'name' => 'Proyecto Referencia Horas',
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $response = $this->actingAs($admin)->post(route('operational.store', 'assignments'), [
            'code' => 'ASI-REF-HOURS',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'project_value' => '0',
            'monthly_hours' => '160',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
        ]);

        $response->assertRedirect(route('operational.index', 'assignments'));

        $this->assertDatabaseHas('project_assignments', [
            'company_id' => $company->id,
            'code' => 'ASI-REF-HOURS',
            'monthly_hours' => 160,
        ]);
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

    public function test_assignments_reject_projects_from_another_client_on_backend(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        [$clientA, $clientB, $projectA, $projectB] = $this->clientProjectFixtures($company->id);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-CLIENT',
            'first_names' => 'Cliente',
            'paternal_surname' => 'Inconsistente',
            'name' => 'Cliente Inconsistente',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $response = $this->actingAs($admin)->post(route('operational.store', 'assignments'), [
            'code' => 'ASI-CLIENT-MISMATCH',
            'person_id' => $person->id,
            'client_id' => $clientA->id,
            'project_id' => $projectB->id,
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
        ]);

        $response->assertSessionHasErrors([
            'project_id' => 'El proyecto seleccionado no pertenece al cliente indicado.',
        ]);

        $this->assertDatabaseMissing('project_assignments', [
            'company_id' => $company->id,
            'code' => 'ASI-CLIENT-MISMATCH',
        ]);
    }

    public function test_assignments_show_project_reference_as_not_informed_when_dates_are_missing(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-NODATE',
            'legal_name' => 'Cliente Sin Fechas',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-NODATE',
            'first_names' => 'Sin',
            'paternal_surname' => 'Fecha',
            'name' => 'Sin Fecha',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-NODATE',
            'name' => 'Proyecto Sin Fechas',
            'sale_net' => 1,
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-NODATE',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
        ]);

        $response = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $assignment->id]));

        $response->assertOk();
        $response->assertSee('Referencia del proyecto');
        $response->assertSee('Vigencia proyecto: No informada');
    }

    public function test_assignments_require_both_dates_when_one_is_informed(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-PARTIAL-DATE',
            'legal_name' => 'Cliente Fecha Parcial',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-PARTIAL-DATE',
            'first_names' => 'Fecha',
            'paternal_surname' => 'Parcial',
            'name' => 'Fecha Parcial',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-PARTIAL-DATE',
            'name' => 'Proyecto Fecha Parcial',
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $onlyStart = $this->actingAs($admin)->post(route('operational.store', 'assignments'), [
            'code' => 'ASI-ONLY-START',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'start_date' => '10/08/2026',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
        ]);

        $onlyStart->assertSessionHasErrors([
            'end_date' => 'La fecha término es obligatoria cuando se informa la fecha inicio.',
        ]);

        $onlyEnd = $this->actingAs($admin)->post(route('operational.store', 'assignments'), [
            'code' => 'ASI-ONLY-END',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'end_date' => '10/08/2026',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
        ]);

        $onlyEnd->assertSessionHasErrors([
            'start_date' => 'La fecha inicio es obligatoria cuando se informa la fecha término.',
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

    public function test_time_entries_show_clarified_titles_help_and_assignment_context(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-UX',
            'legal_name' => 'Cliente Horas UX',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $uf = Currency::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'UF'],
            ['name' => 'Unidad de Fomento', 'symbol' => 'UF', 'minor_units' => 2, 'active' => true, 'sort_order' => 1]
        );

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-UX',
            'first_names' => 'Paula',
            'paternal_surname' => 'Horas',
            'name' => 'Paula Horas',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $uf->id,
            'code' => 'PRY-TIME-UX',
            'name' => 'Proyecto Horas UX',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-UX',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.50,
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
        ]);

        $create = $this->actingAs($admin)->get(route('operational.create', 'time-entries'));

        $create->assertOk();
        $create->assertSee('Registrar horas');
        $create->assertSee('Operación / Horas / Registrar horas');
        $create->assertDontSee('Nuevo Horas');
        $this->assertDoesNotMatchRegularExpression('/;\s*<\/div>\s*<div>\s*<h1 class="page-title">Registrar horas/s', $create->getContent());
        $create->assertSee('¿Cómo registrar horas?');
        $create->assertSee('Seleccione primero la persona y la fecha. El sistema mostrará los proyectos con una asignación vigente para ese día y completará automáticamente el cliente, la tarifa y la referencia de la asignación.');
        $create->assertSee('Indique la actividad realizada y las horas efectivamente trabajadas ese día.');
        $create->assertSee('Referencia de la asignación');
        $create->assertSee('data-time-entry-assignment-project', false);
        $create->assertSee('data-time-entry-assignment-context', false);
        $create->assertSee('data-time-entry-context-warning-box', false);
        $create->assertSee('data-time-entry-approved-warning-box', false);
        $create->assertSee('data-time-entry-date-validation-box', false);
    }

    public function test_time_entries_validate_assignment_date_hours_and_client_integrity(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $clientA = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-A',
            'legal_name' => 'Cliente A Horas',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $clientB = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-B',
            'legal_name' => 'Cliente B Horas',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-VAL',
            'first_names' => 'María',
            'paternal_surname' => 'Valida',
            'name' => 'María Valida',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $clientA->id,
            'code' => 'PRY-TIME-VAL',
            'name' => 'Proyecto Validación Horas',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $clientA->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-VAL',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
        ]);

        $activity = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-TIME-VAL'],
            ['name' => 'Actividad Validación Horas', 'active' => true, 'sort_order' => 1]
        );

        $approvedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );

        $response = $this->actingAs($admin)->from(route('operational.create', 'time-entries'))->post(route('operational.store', 'time-entries'), [
            'code' => 'HOR-TIME-VAL',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'client_id' => $clientB->id,
            'entry_date' => '31/07/2026',
            'activity_id' => $activity->id,
            'hours_worked' => 25,
            'hours_approved' => 26,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
        ]);

        $response->assertRedirect(route('operational.create', 'time-entries'));
        $response->assertSessionHasErrors([
            'client_id' => 'El cliente del registro debe coincidir con el cliente del proyecto seleccionado.',
            'project_id' => 'La fecha registrada está fuera de la vigencia de la asignación (01/08/2026 al 30/09/2026).',
            'hours_worked' => 'Las horas trabajadas no pueden superar 24 en un mismo registro.',
            'hours_approved' => 'Las horas aprobadas no pueden superar las horas trabajadas.',
        ]);
        $this->assertDatabaseMissing('time_entries', [
            'code' => 'HOR-TIME-VAL',
        ]);
        $create = $this->actingAs($admin)->get(route('operational.create', 'time-entries'));
        $create->assertSee('Seleccione una fecha primero');
    }

    public function test_time_entries_validate_daily_total_and_status_consistency_and_autofill_cost_center(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-DAY',
            'legal_name' => 'Cliente Día Horas',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $costCenter = \App\Models\CostCenter::query()->create([
            'company_id' => $company->id,
            'code' => 'CC-TIME',
            'name' => 'Centro Tiempo',
            'active' => true,
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-DAY',
            'first_names' => 'Pedro',
            'paternal_surname' => 'Diario',
            'name' => 'Pedro Diario',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-TIME-DAY',
            'name' => 'Proyecto Día Horas',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-DAY',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.50,
            'cost_center_id' => $costCenter->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
        ]);

        $activity = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-TIME-DAY'],
            ['name' => 'Actividad Día Horas', 'active' => true, 'sort_order' => 1]
        );

        $approvedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );
        $rejectedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'rejected'],
            ['name' => 'Rechazado', 'active' => true, 'sort_order' => 2]
        );

        TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HOR-TIME-BASE',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-10',
            'activity_id' => $activity->id,
            'activity' => 'Actividad Día Horas',
            'hours_worked' => 10,
            'hours_approved' => 10,
            'approval_status_id' => $approvedStatus->id,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
            'cost_center_id' => $costCenter->id,
            'cost_center' => $costCenter->name,
        ]);

        $dailyLimit = $this->actingAs($admin)->from(route('operational.create', 'time-entries'))->post(route('operational.store', 'time-entries'), [
            'code' => 'HOR-TIME-LIMIT',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'entry_date' => '10/08/2026',
            'activity_id' => $activity->id,
            'hours_worked' => 15,
            'hours_approved' => 5,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
        ]);

        $dailyLimit->assertSessionHasErrors([
            'hours_worked' => 'La suma diaria de horas trabajadas para esta persona no puede superar 24.',
        ]);

        $rejected = $this->actingAs($admin)->from(route('operational.create', 'time-entries'))->post(route('operational.store', 'time-entries'), [
            'code' => 'HOR-TIME-REJECT',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'entry_date' => '11/08/2026',
            'activity_id' => $activity->id,
            'hours_worked' => 5,
            'hours_approved' => 2,
            'approval_status_id' => $rejectedStatus->id,
            'payment_status' => 'paid',
        ]);

        $rejected->assertSessionHasErrors([
            'hours_approved' => 'Cuando la aprobación es Rechazado, las horas aprobadas deben ser 0.',
            'payment_status' => 'Un registro solo puede marcarse como pagado cuando su aprobación está en estado Aprobado.',
        ]);

        $valid = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'code' => 'HOR-TIME-OK',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'client_id' => '',
            'entry_date' => '12/08/2026',
            'activity_id' => $activity->id,
            'hours_worked' => 8.5,
            'hours_approved' => 8.5,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
            'cost_center_id' => '',
        ]);

        $valid->assertRedirect(route('operational.index', 'time-entries'));
        $this->assertDatabaseHas('time_entries', [
            'code' => 'HOR-TIME-OK',
            'client_id' => $client->id,
            'assignment_id' => $assignment->id,
            'cost_center_id' => $costCenter->id,
            'hours_worked' => 8.5,
            'hours_approved' => 8.5,
        ]);

        $editEntry = TimeEntry::query()->where('code', 'HOR-TIME-OK')->firstOrFail();
        $edit = $this->actingAs($admin)->get(route('operational.edit', ['time-entries', $editEntry->id]));
        $edit->assertOk();
        $edit->assertSee('Editar registro de horas');
        $edit->assertSee('Operación / Horas / Editar registro de horas');
        $edit->assertSee('Asignación: ASI-TIME-DAY');
        $edit->assertSee('Vigencia: 01/08/2026 al 30/09/2026');
        $edit->assertSee('Cliente: Cliente Día Horas');
        $edit->assertSee('Centro de costo: Centro Tiempo');
        $edit->assertSee('Tarifa:');
        $edit->assertSee('UF');
        $edit->assertSee('/ HH');
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
            'Unidad del valor HH',
            'Valor HH',
        ]);

        $assignEdit = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $assignment->id]));
        $assignEdit->assertOk();
        $this->assertHorizontalRowLabels($assignEdit->getContent(), [
            'Persona',
            'Proyecto',
        ]);
        $this->assertHorizontalRowLabels($assignEdit->getContent(), [
            'Unidad del valor HH',
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
            'hourly_value' => 1300,
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'employment_contract_type_id' => \App\Models\ContractType::query()->where('company_id', $company->id)->where('domain', 'employment')->where('code', 'INDEFINIDO')->valueOrFail('id'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $response = $this->actingAs($admin)->get(route('operational.create', 'payroll-records'));

        $response->assertOk();
        $response->assertSee('Nueva remuneración');
        $response->assertSee('¿Cómo usar esta pantalla?', false);
        $response->assertSee('Ver detalle de conceptos', false);
        $response->assertSee('payroll-help-shell', false);
        $response->assertSee('id="payrollUsageHelp"', false);
        $response->assertSee('class="collapse mt-3"', false);
        $response->assertSee('Seleccione primero Persona, Proyecto y Período. El sistema completa la referencia automática del período.');
        $response->assertSee('Los campos marcados como override reemplazan un valor calculado solo para esta remuneración.');
        $response->assertSeeText('Datos base');
        $response->assertSeeText('Referencia de la remuneración');
        $response->assertSeeText('Ajustes / overrides');
        $response->assertSeeText('Adicionales');
        $response->assertSeeText('Descuentos');
        $response->assertSeeText('Resultado');
        $response->assertSeeText('Control del cálculo');
        $response->assertSeeText('Horas aprobadas sistema');
        $response->assertSeeText('Costo hora referencial persona');
        $this->assertDoesNotMatchRegularExpression('/;\s*<\/div>\s*<div>\s*<h1 class="page-title">Nueva remuneración/s', $response->getContent());
        $response->assertSee('data-bs-toggle="tooltip"', false);
        $response->assertSee('Base calculada', false);
        $response->assertSee('Líquido', false);
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
        $response->assertSee('Editar remuneración');
        $response->assertSee('Julio 2026');
        $response->assertSee('Pedro González Rojas');
        $response->assertSee('Honorarios mensual');
        $response->assertSee('$ 100.000');
        $response->assertSee('$ 15.250');
        $response->assertSee('$ 84.750');
        $response->assertSeeText('Referencia de la remuneración');
        $response->assertSeeText('Resultado');
        $response->assertSeeText('Control del cálculo');
        $this->assertDoesNotMatchRegularExpression('/;\s*<\/div>\s*<div>\s*<h1 class="page-title">Editar remuneración/s', $response->getContent());
    }

    public function test_payroll_show_breakdown_exposes_automatic_sources_and_assignment_context(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        [$client, , $project] = $this->clientProjectFixtures($company->id);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-REM-03',
            'first_names' => 'María',
            'paternal_surname' => 'Lagos',
            'maternal_surname' => 'Díaz',
            'name' => 'María Lagos Díaz',
            'modality' => 'Dependiente por hora',
            'hourly_value' => 1300,
            'additional_health_plan' => 12000,
            'employment_mode_id' => $this->employmentModeId($company->id, 'PAGO_POR_HORA'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-REM-03',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'hourly_value' => 1300,
            'project_value' => 50000,
        ]);

        $approvedId = ApprovalStatus::query()
            ->where('company_id', $company->id)
            ->where('code', 'approved')
            ->valueOrFail('id');

        $activity = Activity::query()->create([
            'company_id' => $company->id,
            'code' => 'ACT-REM-03-'.uniqid(),
            'name' => 'Análisis rem '.uniqid(),
            'active' => true,
        ]);

        TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HRS-REM-03',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-07-15',
            'activity' => 'Análisis rem',
            'activity_id' => $activity->id,
            'hours_worked' => 8,
            'hours_approved' => 8,
            'hourly_value' => 1300,
            'calculated_amount' => 10400,
            'approval_status_id' => $approvedId,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
            'cost_center_id' => null,
        ]);

        PayrollAdjustment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'period_date' => '2026-07-01',
            'type' => 'MONTHLY_VALUE',
            'amount' => 200000,
            'quantity' => null,
            'description' => 'Base mensual automática',
            'active' => true,
        ]);
        PayrollAdjustment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'period_date' => '2026-07-01',
            'type' => 'HEALTH_ADDITIONAL',
            'amount' => 12000,
            'quantity' => null,
            'description' => 'Salud adicional automática',
            'active' => true,
        ]);
        PayrollAdjustment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'period_date' => '2026-07-01',
            'type' => 'ADVANCE',
            'amount' => 10000,
            'quantity' => null,
            'description' => 'Anticipo automático',
            'active' => true,
        ]);
        PayrollAdjustment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'period_date' => '2026-07-01',
            'type' => 'OTHER_DEDUCTION',
            'amount' => 5000,
            'quantity' => null,
            'description' => 'Otro descuento automático',
            'active' => true,
        ]);

        $payroll = \App\Models\PayrollRecord::query()->create([
            'company_id' => $company->id,
            'code' => 'REM-UI-03',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-07-01',
            'payment_date' => '2026-07-20',
            'amount_basis' => 'GROSS',
            'hours_approved' => 8,
            'monthly_value' => 200000,
            'hourly_value' => 1300,
            'project_value' => 50000,
            'health_additional' => 12000,
            'bonuses' => 0,
            'non_taxable_allowances' => 0,
            'advances' => 10000,
            'other_deductions' => 5000,
            'base_salary' => 200000,
            'taxable_gross' => 200000,
            'employer_cost' => 220000,
            'net_pay' => 180000,
            'calculation_status' => 'OK',
            'legal_snapshot' => ['period' => '2026-07-01'],
            'status' => 'Pendiente',
        ]);

        $response = $this->actingAs($admin)->get(route('operational.edit', ['payroll-records', $payroll->id]));

        $response->assertOk();
        $response->assertSee('Fuentes aplicadas', false);
        $response->assertSee('Cliente', false);
        $response->assertSee('ASI-REM-03 · Proyecto A', false);
        $response->assertSee('Base mensual automática', false);
        $response->assertSee('Salud adicional automática', false);
        $response->assertSee('Anticipos automáticos', false);
        $response->assertSee('Otros descuentos automáticos', false);
        $response->assertSee('Tarifa automática', false);
        $response->assertSee('Horas aprobadas automáticas', false);
    }

    public function test_payroll_edit_reflects_automatic_hours_and_specific_missing_payment_date_status(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        [$client, , $project] = $this->clientProjectFixtures($company->id);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-REM-04',
            'first_names' => 'Laura',
            'paternal_surname' => 'Vega',
            'maternal_surname' => 'Rojas',
            'name' => 'Laura Vega Rojas',
            'modality' => 'Dependiente por hora',
            'hourly_value' => 1300,
            'additional_health_plan' => 12000,
            'employment_mode_id' => $this->employmentModeId($company->id, 'PAGO_POR_HORA'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-REM-04',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'hourly_value' => 1300,
            'project_value' => 50000,
        ]);

        $approvedId = ApprovalStatus::query()
            ->where('company_id', $company->id)
            ->where('code', 'approved')
            ->valueOrFail('id');

        $activity = Activity::query()->create([
            'company_id' => $company->id,
            'code' => 'ACT-REM-04-'.uniqid(),
            'name' => 'Trabajo rem '.uniqid(),
            'active' => true,
        ]);

        TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HRS-REM-04',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-07-15',
            'activity' => 'Trabajo rem',
            'activity_id' => $activity->id,
            'hours_worked' => 10,
            'hours_approved' => 10,
            'hourly_value' => 1300,
            'calculated_amount' => 13000,
            'approval_status_id' => $approvedId,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        $payroll = PayrollRecord::query()->create([
            'company_id' => $company->id,
            'code' => 'REM-UI-04',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-07-01',
            'payment_date' => null,
            'hours_approved' => 0,
            'hourly_value' => 1300,
            'project_value' => 50000,
            'base_salary' => 200000,
            'gross_amount' => 200000,
            'taxable_amount' => 200000,
            'taxable_gross' => 200000,
            'employee_retention' => 30000,
            'retention_rate' => 0.15,
            'employer_cost' => 220000,
            'net_pay' => 170000,
            'calculation_status' => 'OK',
            'legal_snapshot' => ['period' => '2026-07-01'],
            'status' => 'Pendiente de fecha de pago',
        ]);

        $response = $this->actingAs($admin)->get(route('operational.show', ['payroll-records', $payroll->id]));

        $response->assertOk();
        $response->assertSee('Pendiente de fecha de pago', false);
        $response->assertSee('Horas aprobadas sistema', false);
        $response->assertSee('10 h', false);
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
