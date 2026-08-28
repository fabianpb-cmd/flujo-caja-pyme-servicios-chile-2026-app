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
use App\Models\SalesDocumentTimeEntry;
use App\Models\TimeEntry;
use App\Models\UfValue;
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
        $response->assertSeeText('Valor HH de costeo del proyecto es específico de la asignación cuando se informa. Si se deja vacío y la Persona tiene un Valor HH base de costeo, el sistema usa esa referencia.');
        $response->assertSeeText('Usa Monto pactado de remuneración por proyecto/hito cuando existe un monto fijo para pagar la participación o un hito acordado.');
        $response->assertSeeText('Horas mensuales representan el compromiso mensual de costeo y planificación de esta asignación.');
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
        $this->assertDoesNotMatchRegularExpression(
            '/<div class="page-breadcrumb">[^<]*;[^<]*<\/div>\s*<div>\s*<h1 class="page-title">Nueva asignación<\/h1>/s',
            $response->getContent(),
        );
    }

    public function test_assignments_show_effective_project_rate_when_specific_hourly_value_is_empty(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $uf = $this->currency($company->id, 'UF', 'Unidad de Fomento');
        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-ASS-RATE',
            'legal_name' => 'Cliente Tarifa Proyecto',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);
        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-ASS-RATE',
            'first_names' => 'Jaime',
            'paternal_surname' => 'Tarifa',
            'name' => 'Jaime Tarifa',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.50,
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $uf->id,
            'code' => 'PRY-ASS-RATE',
            'name' => 'Alerta Matrículas',
            'sale_net' => 160,
            'contracted_hourly_rate' => 0.50,
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);
        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-ASS-RATE',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => null,
            'project_value' => null,
        ]);

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $assignment->id]));

        $edit->assertOk();
        $edit->assertSee('Valor HH contractual referencia: UF 0,50 / HH', false);
        $edit->assertSee('Referencia Persona: UF 0,50 / HH', false);
        $edit->assertSee('Efectivo: UF 0,50 / HH · Persona', false);
        $this->assertMatchesRegularExpression('/name="hourly_value"[^>]*value=""/', $edit->getContent());
        $this->assertMatchesRegularExpression('/name="project_value"[^>]*value=""/', $edit->getContent());
        $this->assertDoesNotMatchRegularExpression(
            '/<div class="page-breadcrumb">[^<]*;[^<]*<\/div>\s*<div>\s*<h1 class="page-title">Editar asignación<\/h1>/s',
            $edit->getContent(),
        );

        $show = $this->actingAs($admin)->get(route('operational.show', ['assignments', $assignment->id]));

        $show->assertOk();
        $show->assertSee('UF 0,50 / HH');
        $show->assertSee('Origen: Persona · Jaime Tarifa');
        $show->assertSee('No informado');
        $this->assertDoesNotMatchRegularExpression('/<dt class="col-sm-4">Valor HH<\/dt>\s*<dd[^>]*>\s*—/u', $show->getContent());
    }

    public function test_assignments_specific_hourly_value_prevails_over_project_reference(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $uf = $this->currency($company->id, 'UF', 'Unidad de Fomento');
        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-ASS-OWN',
            'legal_name' => 'Cliente Tarifa Específica',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);
        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-ASS-OWN',
            'first_names' => 'Paula',
            'paternal_surname' => 'Específica',
            'name' => 'Paula Específica',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $uf->id,
            'code' => 'PRY-ASS-OWN',
            'name' => 'Proyecto Tarifa Específica',
            'sale_net' => 160,
            'contracted_hourly_rate' => 0.50,
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);
        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-ASS-OWN',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.60,
        ]);

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $assignment->id]));

        $edit->assertOk();
        $edit->assertSee('Valor HH contractual referencia: UF 0,50 / HH', false);
        $edit->assertSee('Referencia Persona:', false);
        $edit->assertSee('Efectivo: UF 0,60 / HH · Asignación', false);

        $show = $this->actingAs($admin)->get(route('operational.show', ['assignments', $assignment->id]));

        $show->assertOk();
        $show->assertSee('UF 0,60 / HH');
        $show->assertSee('Origen: Asignación');
    }

    public function test_assignments_do_not_inherit_project_value_from_sale_net(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $uf = $this->currency($company->id, 'UF', 'Unidad de Fomento');
        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-ASS-NET',
            'legal_name' => 'Cliente Venta Neta',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);
        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-ASS-NET',
            'first_names' => 'Monto',
            'paternal_surname' => 'Neto',
            'name' => 'Monto Neto',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $uf->id,
            'code' => 'PRY-ASS-NET',
            'name' => 'Proyecto Venta Neta',
            'sale_net' => 160,
            'contracted_hourly_rate' => 0.50,
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $response = $this->actingAs($admin)->post(route('operational.store', 'assignments'), [
            'code' => 'ASI-ASS-NET',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => '',
            'project_value' => '',
            'monthly_hours' => 10,
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
        ]);

        $response->assertRedirect(route('operational.index', 'assignments'));
        $assignment = ProjectAssignment::query()->where('code', 'ASI-ASS-NET')->firstOrFail();
        $this->assertNull($assignment->project_value);

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $assignment->id]));

        $edit->assertOk();
        $edit->assertSee('Venta neta proyecto: UF 160,00');
        $edit->assertSee('Efectivo: No informado', false);
        $edit->assertDontSee('Efectivo: UF 160,00 · Proyecto', false);
        $this->assertMatchesRegularExpression('/name="project_value"[^>]*value=""/', $edit->getContent());
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
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $clp->id,
            'hourly_value' => 35000,
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
        $create->assertSee('Valor HH de costeo del proyecto', false);
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

        $uf = Currency::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'UF'],
            ['name' => 'Unidad de Fomento', 'symbol' => 'UF', 'minor_units' => 2, 'active' => true, 'sort_order' => 2]
        );

        $personProjectUf = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-HH-03',
            'first_names' => 'Tarifa',
            'paternal_surname' => 'Project UF',
            'name' => 'Tarifa Project UF',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.50,
        ]);

        $projectUf = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $uf->id,
            'code' => 'PRY-HH-UF',
            'name' => 'Proyecto Horas UF',
            'contracted_hourly_rate' => 0.50,
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $personProjectUf->id,
            'client_id' => $client->id,
            'project_id' => $projectUf->id,
            'code' => 'ASI-HH-03',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => null,
            'start_date' => '2026-08-01',
        ]);

        $projectRateUf = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'code' => 'HOR-003',
            'person_id' => $personProjectUf->id,
            'project_id' => $projectUf->id,
            'client_id' => $client->id,
            'entry_date' => '10/08/2026',
            'activity_id' => $activity->id,
            'hours_worked' => 2,
            'hours_approved' => 2,
            'hourly_value' => 1,
            'approval_status_id' => $approvalStatus->id,
            'payment_status' => 'pending',
        ]);

        $projectRateUf->assertRedirect(route('operational.index', 'time-entries'));
        $projectUfEntry = TimeEntry::query()->where('code', 'HOR-003')->firstOrFail();
        $this->assertSame($client->id, $projectUfEntry->client_id);
        $this->assertSame(0.5, (float) $projectUfEntry->hourly_value);
        $this->assertSame(1.0, (float) $projectUfEntry->calculated_amount);
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
        $edit->assertSee('Valor HH de costeo del proyecto:');
        $edit->assertSee('UF');
        $edit->assertSee('/ HH');
        $this->assertDoesNotMatchRegularExpression('/;\s*<\/div>\s*<div>\s*<h1 class="page-title">Editar registro de horas/s', $edit->getContent());
    }

    public function test_time_entries_daily_edit_update_and_delete_are_blocked_when_prefactured(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-DAY-LOCK',
            'legal_name' => 'Cliente Día Bloqueado',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-TIME-DAY-LOCK',
            'name' => 'Proyecto Día Bloqueado',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $activity = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-TIME-DAY-LOCK'],
            ['name' => 'Actividad Día Bloqueada', 'active' => true, 'sort_order' => 1]
        );
        $approvedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );
        $rejectedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'rejected'],
            ['name' => 'Rechazado', 'active' => true, 'sort_order' => 2]
        );

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-DAY-LOCK',
            'first_names' => 'Dario',
            'paternal_surname' => 'Bloqueado',
            'name' => 'Dario Bloqueado',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.8,
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-DAY-LOCK',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.8,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $entry = TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HOR-TIME-DAY-LOCK',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-12',
            'activity_id' => $activity->id,
            'activity' => 'Actividad Día Bloqueada',
            'hours_worked' => 8,
            'hours_approved' => 8,
            'approval_status_id' => $approvedStatus->id,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
            'hourly_value' => 0.8,
        ]);

        $document = SalesDocument::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ING-TIME-DAY-LOCK',
            'document_type_id' => $this->documentTypeId($company->id, 'sales', 'FACTURA'),
            'document_type' => 'Factura',
            'issue_date' => '2026-08-13',
            'net_amount' => 1000,
            'vat_amount' => 190,
            'gross_amount' => 1190,
            'status' => 'Pendiente',
        ]);

        SalesDocumentTimeEntry::query()->create([
            'company_id' => $company->id,
            'sales_document_id' => $document->id,
            'time_entry_id' => $entry->id,
            'project_assignment_id' => $assignment->id,
            'hours_approved' => 8,
            'hourly_rate_amount' => 0.8,
            'rate_unit_type' => 'UF',
            'currency_id' => null,
            'subtotal_original' => 6.4,
            'conversion_rate' => 39000,
            'conversion_date' => '2026-08-13',
            'subtotal_clp' => 249600,
        ]);

        $show = $this->actingAs($admin)->get(route('operational.show', ['time-entries', $entry->id]));
        $show->assertOk();
        $show->assertSee('No se puede modificar el registro porque está siendo utilizado por: 1 líneas de prefacturación. Desactívelo o reasigne las dependencias antes de continuar.', false);
        $show->assertDontSee('<a class="btn btn-primary" href="'.route('operational.edit', ['time-entries', $entry->id]).'">Editar</a>', false);

        $index = $this->actingAs($admin)->get(route('operational.index', 'time-entries'));
        $index->assertOk();
        $index->assertDontSee('<a class="btn btn-sm btn-outline-primary" href="'.route('operational.edit', ['time-entries', $entry->id]).'">Editar</a>', false);

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['time-entries', $entry->id]));
        $edit->assertRedirect(route('operational.show', ['time-entries', $entry->id]));
        $edit->assertSessionHasErrors('dependencies');

        $update = $this->actingAs($admin)
            ->from(route('operational.show', ['time-entries', $entry->id]))
            ->put(route('operational.update', ['time-entries', $entry->id]), [
                'code' => 'HOR-TIME-DAY-LOCK',
                'person_id' => $person->id,
                'project_id' => $project->id,
                'client_id' => '',
                'entry_date' => '12/08/2026',
                'activity_id' => $activity->id,
                'hours_worked' => 6,
                'hours_approved' => 0,
                'approval_status_id' => $rejectedStatus->id,
                'payment_status' => 'pending',
                'cost_center_id' => '',
            ]);

        $update->assertRedirect(route('operational.show', ['time-entries', $entry->id]));
        $update->assertSessionHasErrors('dependencies');

        $delete = $this->actingAs($admin)
            ->from(route('operational.show', ['time-entries', $entry->id]))
            ->delete(route('operational.destroy', ['time-entries', $entry->id]));

        $delete->assertRedirect(route('operational.show', ['time-entries', $entry->id]));
        $delete->assertSessionHasErrors('dependencies');

        $this->assertDatabaseHas('time_entries', [
            'id' => $entry->id,
            'hours_worked' => 8,
            'hours_approved' => 8,
            'payment_status' => 'pending',
        ]);
    }

    public function test_time_entries_index_and_show_preserve_effective_rate_currency_and_unit(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $uf = Currency::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'UF'],
            ['name' => 'Unidad de Fomento', 'symbol' => 'UF', 'minor_units' => 2, 'active' => true, 'sort_order' => 1]
        );

        $clp = Currency::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'CLP'],
            ['name' => 'Peso Chileno', 'symbol' => '$', 'minor_units' => 0, 'active' => true, 'sort_order' => 2]
        );

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-RATE',
            'legal_name' => 'Cliente Tarifa',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $clp->id,
            'code' => 'PRY-TIME-RATE',
            'name' => 'Proyecto Tarifa',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $activity = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-TIME-RATE'],
            ['name' => 'Actividad Tarifa', 'active' => true, 'sort_order' => 1]
        );

        $approvedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );

        $personUf = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-UF',
            'first_names' => 'Pablo',
            'paternal_surname' => 'Toro',
            'name' => 'Pablo Toro',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => null,
            'hourly_value' => 1,
        ]);

        $personClp = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-CLP',
            'first_names' => 'Carla',
            'paternal_surname' => 'Peso',
            'name' => 'Carla Peso',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $clp->id,
            'hourly_value' => 20000,
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $personUf->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-RATE-UF',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => null,
            'hourly_value' => null,
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $personClp->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-RATE-CLP',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $clp->id,
            'hourly_value' => 20000,
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
        ]);

        $timeEntryUf = TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HOR-TIME-UF',
            'person_id' => $personUf->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => ProjectAssignment::query()->where('code', 'ASI-TIME-RATE-UF')->valueOrFail('id'),
            'entry_date' => '2026-08-10',
            'activity_id' => $activity->id,
            'activity' => 'Actividad Tarifa',
            'hours_worked' => 1,
            'hours_approved' => 1,
            'hourly_value' => 1,
            'approval_status_id' => $approvedStatus->id,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        $timeEntryClp = TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HOR-TIME-CLP',
            'person_id' => $personClp->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => ProjectAssignment::query()->where('code', 'ASI-TIME-RATE-CLP')->valueOrFail('id'),
            'entry_date' => '2026-08-11',
            'activity_id' => $activity->id,
            'activity' => 'Actividad Tarifa',
            'hours_worked' => 2,
            'hours_approved' => 2,
            'hourly_value' => 20000,
            'approval_status_id' => $approvedStatus->id,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        $index = $this->actingAs($admin)->get(route('operational.index', 'time-entries'));
        $index->assertOk();
        $index->assertSee('UF 1,00 / HH', false);
        $index->assertSee('CLP 20.000,00 / HH', false);
        $index->assertDontSee('/ HH / HH', false);
        $this->assertMatchesRegularExpression('/HOR-TIME-UF.*UF 1,00 \/ HH/s', $index->getContent());
        $this->assertMatchesRegularExpression('/HOR-TIME-CLP.*CLP 20\.000,00 \/ HH/s', $index->getContent());
        $this->assertDoesNotMatchRegularExpression('/HOR-TIME-UF.*\\$ 1(?![0-9])\\/ HH/s', $index->getContent());

        $showUf = $this->actingAs($admin)->get(route('operational.show', ['time-entries', $timeEntryUf->id]));
        $showUf->assertOk();
        $showUf->assertSee('UF 1,00 / HH', false);
        $this->assertMatchesRegularExpression('/UF 1,00 \/ HH/s', $showUf->getContent());

        $showClp = $this->actingAs($admin)->get(route('operational.show', ['time-entries', $timeEntryClp->id]));
        $showClp->assertOk();
        $showClp->assertSee('CLP 20.000,00 / HH', false);
        $showClp->assertDontSee('/ HH / HH', false);
    }

    public function test_time_entries_create_view_exposes_period_load_mode_without_replacing_daily_mode(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        [$client, , $project] = $this->clientProjectFixtures($company->id);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-PERIOD-UI',
            'first_names' => 'Patricia',
            'paternal_surname' => 'Periodo',
            'name' => 'Patricia Periodo',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.55,
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-PERIOD-UI',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-08-24',
            'end_date' => '2026-09-30',
        ]);

        $create = $this->actingAs($admin)->get(route('operational.create', 'time-entries'));

        $create->assertOk();
        $create->assertSeeInOrder(['Carga por período', 'Carga diaria']);
        $create->assertSee('MODO DE CARGA');
        $create->assertSee('DATOS DE LA CARGA');
        $create->assertSee('PERÍODO');
        $create->assertSee('data-time-entry-period-load-container', false);
        $create->assertSee('data-time-entry-daily-load-container', false);
        $this->assertMatchesRegularExpression('/<div[^>]*class="[^"]*d-none[^"]*"[^>]*data-time-entry-period-load-container/s', $create->getContent());
        $this->assertDoesNotMatchRegularExpression('/<div[^>]*class="[^"]*d-none[^"]*"[^>]*data-time-entry-daily-load-container/s', $create->getContent());
        $create->assertSee('data-time-entry-period-preview-url', false);
        $create->assertSee('period_start_date', false);
        $create->assertSee('period_end_date', false);
        $create->assertSee('Total período');
        $create->assertSee('AUTORIZACIÓN');
        $create->assertSee('RESUMEN');
        $create->assertSee('Las condiciones seleccionadas se aplicarán a todos los días incluidos en esta carga.');
        $create->assertSee('El pago común del lote se propagará a cada registro diario creado.');
        $create->assertSee('Las horas se distribuirán automáticamente entre los días aplicables del período. El sistema mantendrá registros diarios para validación y trazabilidad.');
        $create->assertSee('data-time-entry-period-summary-panel', false);
        $create->assertSee('<div class="fw-semibold" data-time-entry-period-summary-days>—</div>', false);
        $create->assertSee('<div class="fw-semibold" data-time-entry-period-total-hours-display>—</div>', false);
        $create->assertDontSee('Distribución');
        $create->assertDontSee('Horas iguales por día');
        $create->assertDontSee('Horas por día');
        $create->assertDontSee('Manual');
        $this->assertDoesNotMatchRegularExpression('/<div[^>]*data-time-entry-period-table-wrapper/s', $create->getContent());
        $this->assertDoesNotMatchRegularExpression('/<table[^>]*data-time-entry-period-table/s', $create->getContent());
        $create->assertDontSee('<th style="width: 56px;">Incluir</th>', false);
        $create->assertDontSee('<th>Fecha</th>', false);
        $create->assertDontSee('<th>Asignación</th>', false);
        $create->assertDontSee('<th style="width: 140px;">Horas</th>', false);
        $this->assertDoesNotMatchRegularExpression('/;\s*<\/div>\s*<div>\s*<h1 class="page-title">Registrar horas/s', $create->getContent());
    }

    public function test_time_entries_period_mode_hides_daily_form_block_completely(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        [$client, , $project] = $this->clientProjectFixtures($company->id);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-PERIOD-HIDE',
            'first_names' => 'Nora',
            'paternal_surname' => 'Oculta',
            'name' => 'Nora Oculta',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.55,
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-PERIOD-HIDE',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-08-24',
            'end_date' => '2026-09-30',
        ]);

        $create = $this->actingAs($admin)
            ->withSession(['_old_input' => ['entry_mode' => 'period']])
            ->get(route('operational.create', 'time-entries'));

        $create->assertOk();
        $create->assertSee('Carga por período');
        $create->assertSee('DATOS DE LA CARGA');
        $create->assertSee('PERÍODO');
        $create->assertSee('AUTORIZACIÓN');
        $create->assertSee('RESUMEN');
        $create->assertSee('Registrar horas');
        $create->assertSee('data-time-entry-period-panel', false);
        $create->assertSee('data-time-entry-period-load-container', false);
        $create->assertSee('data-time-entry-daily-load-container', false);
        $this->assertMatchesRegularExpression('/<div[^>]*class="[^"]*d-none[^"]*"[^>]*data-time-entry-daily-load-container/s', $create->getContent());
        $this->assertDoesNotMatchRegularExpression('/<div[^>]*class="[^"]*d-none[^"]*"[^>]*data-time-entry-period-load-container/s', $create->getContent());
        $content = $create->getContent();
        $this->assertSame(1, preg_match_all('/<select[^>]*id="person_id"[^>]*data-time-entry-person-select="true"/s', $content));
        $this->assertSame(1, preg_match_all('/<select[^>]*id="project_id"[^>]*data-time-entry-project-select="true"/s', $content));
        $this->assertSame(1, preg_match_all('/<select[^>]*id="activity_id"/s', $content));
        $this->assertSame(1, preg_match_all('/<select[^>]*id="cost_center_id"/s', $content));
        $this->assertSame(1, preg_match_all('/<select[^>]*id="approval_status_id"/s', $content));
        $this->assertSame(1, preg_match_all('/<select[^>]*id="payment_status"/s', $content));
        $this->assertSame(0, preg_match_all('/<input[^>]*id="entry_date"/s', $content));
        $this->assertSame(0, preg_match_all('/<input[^>]*id="hours_worked"/s', $content));
        $this->assertSame(0, preg_match_all('/<input[^>]*id="hours_approved"/s', $content));
        $this->assertSame(0, preg_match_all('/<input[^>]*id="client_id_display"/s', $content));
        $this->assertSame(0, preg_match_all('/<input[^>]*id="hourly_value_display"/s', $content));
        $create->assertDontSee('data-time-entry-daily-context', false);
        $create->assertDontSee('data-time-entry-assignment-context', false);
        $this->assertDoesNotMatchRegularExpression('/;\s*<\/div>\s*<div>\s*<h1 class="page-title">Registrar horas/s', $create->getContent());
    }

    public function test_time_entries_period_preview_marks_variable_rates_without_faking_a_single_summary_value(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-VAR-RATE',
            'legal_name' => 'Cliente Variable',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-TIME-VAR-RATE',
            'name' => 'Proyecto Variable',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-VAR-RATE',
            'first_names' => 'Valeria',
            'paternal_surname' => 'Variable',
            'name' => 'Valeria Variable',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.8,
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-VAR-RATE-A',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.8,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-03',
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-VAR-RATE-B',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 1.0,
            'start_date' => '2026-08-04',
            'end_date' => '2026-08-05',
        ]);

        $response = $this->actingAs($admin)->postJson(route('operational.time-entry-period-preview', 'time-entries'), [
            'entry_mode' => 'period',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_start_date' => '01/08/2026',
            'period_end_date' => '05/08/2026',
            'period_distribution_mode' => 'manual',
            'period_total_hours' => 10,
            'payment_status' => 'pending',
        ]);

        $response->assertOk();
        $response->assertJsonPath('can_save', true);
        $response->assertJsonPath('summary.client_label', 'Cliente Variable');
        $response->assertJsonPath('summary.shared_rate_display', null);
        $response->assertJsonPath('summary.multiple_rates', true);
    }

    public function test_time_entries_period_preview_keeps_common_validation_compact_before_daily_resolution(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        [$client, , $project] = $this->clientProjectFixtures($company->id);

        $response = $this->actingAs($admin)->postJson(route('operational.time-entry-period-preview', 'time-entries'), [
            'entry_mode' => 'period',
            'person_id' => null,
            'project_id' => null,
            'period_start_date' => '01/08/2020',
            'period_end_date' => '20/08/2026',
            'period_distribution_mode' => 'manual',
            'period_total_hours' => 10,
            'payment_status' => 'pending',
        ]);

        $response->assertOk();
        $response->assertJsonPath('rows', []);
        $response->assertJsonPath('total_hours', 0);
        $response->assertJsonPath('can_save', false);
        $response->assertJsonPath('summary.pending', true);
        $response->assertJsonPath('field_errors.period_rows.0', 'Seleccione Persona y Proyecto para preparar la carga.');
        $response->assertJsonMissingPath('field_errors.period_rows.1');
        $response->assertJsonPath('field_errors.period_end_date.0', 'El período no puede superar 31 días. Divida la carga en períodos más pequeños.');
    }

    public function test_time_entries_period_preview_rejects_ranges_over_thirty_one_days_without_daily_iteration(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        [$client, , $project] = $this->clientProjectFixtures($company->id);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-PERIOD-RANGE',
            'first_names' => 'Rango',
            'paternal_surname' => 'Largo',
            'name' => 'Rango Largo',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.55,
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-PERIOD-RANGE',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2020-08-01',
            'end_date' => '2026-08-20',
        ]);

        $response = $this->actingAs($admin)->postJson(route('operational.time-entry-period-preview', 'time-entries'), [
            'entry_mode' => 'period',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_start_date' => '01/08/2020',
            'period_end_date' => '20/08/2026',
            'period_distribution_mode' => 'equal',
            'period_total_hours' => 10,
            'payment_status' => 'pending',
        ]);

        $response->assertOk();
        $response->assertJsonPath('rows', []);
        $response->assertJsonPath('total_hours', 0);
        $response->assertJsonPath('can_save', false);
        $response->assertJsonPath('field_errors.period_end_date.0', 'El período no puede superar 31 días. Divida la carga en períodos más pequeños.');
        $response->assertJsonMissingPath('field_errors.period_rows.0');
    }

    public function test_time_entries_period_load_creates_daily_entries_from_total_hours_only(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-PERIOD',
            'legal_name' => 'Cliente Período',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-TIME-PERIOD',
            'name' => 'Proyecto Período',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $activity = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-TIME-PERIOD'],
            ['name' => 'Actividad Período', 'active' => true, 'sort_order' => 1]
        );

        $approvedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );

        $personSplit = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-SPLIT',
            'first_names' => 'Sandra',
            'paternal_surname' => 'Tramo',
            'name' => 'Sandra Tramo',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.5,
        ]);

        $assignmentA = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $personSplit->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-A',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.5,
            'start_date' => '2026-08-24',
            'end_date' => '2026-08-26',
        ]);

        $assignmentB = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $personSplit->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-B',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.75,
            'start_date' => '2026-08-27',
            'end_date' => '2026-08-28',
        ]);

        $total = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'entry_mode' => 'period',
            'person_id' => $personSplit->id,
            'project_id' => $project->id,
            'activity_id' => $activity->id,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
            'period_start_date' => '24/08/2026',
            'period_end_date' => '28/08/2026',
            'period_distribution_mode' => 'total',
            'period_total_hours' => 40,
        ]);

        $total->assertRedirect(route('operational.index', 'time-entries'));
        $total->assertSessionHas('status', 'Se registraron 5 días y 40 h.');

        $splitEntries = TimeEntry::query()
            ->where('company_id', $company->id)
            ->where('person_id', $personSplit->id)
            ->orderBy('entry_date')
            ->get();

        $this->assertCount(5, $splitEntries);
        $this->assertNotNull($splitEntries->first()->period_batch_id);
        $this->assertCount(1, $splitEntries->pluck('period_batch_id')->unique());
        $this->assertSame(40.0, round((float) $splitEntries->sum('hours_worked'), 2));
        $this->assertSame(40.0, round((float) $splitEntries->sum('hours_approved'), 2));
        $this->assertSame([$assignmentA->id, $assignmentA->id, $assignmentA->id, $assignmentB->id, $assignmentB->id], $splitEntries->pluck('assignment_id')->all());
        $this->assertSame([8.0, 8.0, 8.0, 8.0, 8.0], $splitEntries->pluck('hours_worked')->map(fn ($value) => round((float) $value, 2))->all());

        $personManipulated = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-MANIP',
            'first_names' => 'Tomás',
            'paternal_surname' => 'Manipulado',
            'name' => 'Tomás Manipulado',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.65,
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $personManipulated->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-MANIP',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.65,
            'start_date' => '2026-08-31',
            'end_date' => '2026-09-04',
        ]);

        $manipulated = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'entry_mode' => 'period',
            'person_id' => $personManipulated->id,
            'project_id' => $project->id,
            'activity_id' => $activity->id,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
            'period_start_date' => '31/08/2026',
            'period_end_date' => '04/09/2026',
            'period_distribution_mode' => 'manual',
            'period_total_hours' => 40,
            'period_hours_per_day' => 2,
            'period_rows_payload' => json_encode([
                ['entry_date' => '2026-08-31', 'included' => true, 'hours_worked' => 1],
                ['entry_date' => '2026-09-01', 'included' => true, 'hours_worked' => 1],
                ['entry_date' => '2026-09-02', 'included' => true, 'hours_worked' => 1],
                ['entry_date' => '2026-09-03', 'included' => true, 'hours_worked' => 1],
                ['entry_date' => '2026-09-04', 'included' => true, 'hours_worked' => 36],
            ], JSON_THROW_ON_ERROR),
        ]);

        $manipulated->assertRedirect(route('operational.index', 'time-entries'));

        $manipulatedEntries = TimeEntry::query()
            ->where('company_id', $company->id)
            ->where('person_id', $personManipulated->id)
            ->orderBy('entry_date')
            ->get();

        $this->assertCount(5, $manipulatedEntries);
        $this->assertNotNull($manipulatedEntries->first()->period_batch_id);
        $this->assertCount(1, $manipulatedEntries->pluck('period_batch_id')->unique());
        $this->assertNotSame($splitEntries->first()->period_batch_id, $manipulatedEntries->first()->period_batch_id);
        $this->assertSame(40.0, round((float) $manipulatedEntries->sum('hours_worked'), 2));
        $this->assertSame([8.0, 8.0, 8.0, 8.0, 8.0], $manipulatedEntries->pluck('hours_worked')->map(fn ($value) => round((float) $value, 2))->all());
    }

    public function test_time_entries_index_groups_period_loads_into_blocks_and_hides_individual_edit_for_batch_rows(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $clp = Currency::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'CLP'],
            ['name' => 'Peso Chileno', 'symbol' => '$', 'minor_units' => 0, 'active' => true, 'is_base_currency' => true]
        );

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-BLOCK',
            'legal_name' => 'Cliente Bloque',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $clp->id,
            'code' => 'PRY-TIME-BLOCK',
            'name' => 'Kardex',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $activity = Activity::query()->create([
            'company_id' => $company->id,
            'code' => 'ACT-TIME-BLOCK-UI',
            'name' => 'Implementación Bloque UI',
            'active' => true,
            'sort_order' => 1,
        ]);

        $approvedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-BLOCK',
            'first_names' => 'Pablo',
            'paternal_surname' => 'Toro',
            'name' => 'Pablo Toro',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => null,
            'hourly_value' => 0.8,
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-BLOCK',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => null,
            'hourly_value' => 0.8,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $daily = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'code' => 'HOR-TIME-DAILY',
            'entry_mode' => 'daily',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'entry_date' => '02/08/2026',
            'activity_id' => $activity->id,
            'hours_worked' => 2,
            'hours_approved' => 2,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
        ]);

        $daily->assertRedirect(route('operational.index', 'time-entries'));
        $dailyEntry = TimeEntry::query()->where('code', 'HOR-TIME-DAILY')->firstOrFail();
        $this->assertNull($dailyEntry->period_batch_id);

        $periodOne = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'entry_mode' => 'period',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'activity_id' => $activity->id,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
            'period_start_date' => '03/08/2026',
            'period_end_date' => '10/08/2026',
            'period_distribution_mode' => 'total',
            'period_total_hours' => 10,
            'period_rows_payload' => '',
        ]);

        $periodOne->assertRedirect(route('operational.index', 'time-entries'));

        $periodTwo = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'entry_mode' => 'period',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'activity_id' => $activity->id,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
            'period_start_date' => '12/08/2026',
            'period_end_date' => '13/08/2026',
            'period_distribution_mode' => 'total',
            'period_total_hours' => 4,
        ]);

        $periodTwo->assertRedirect(route('operational.index', 'time-entries'));

        $entries = TimeEntry::query()
            ->where('company_id', $company->id)
            ->where('person_id', $person->id)
            ->orderBy('entry_date')
            ->get();

        $this->assertCount(9, $entries);
        $batchIds = $entries->pluck('period_batch_id')->filter()->unique()->values();
        $this->assertCount(2, $batchIds);

        $periodOneEntries = $entries->where('period_batch_id', $batchIds->first());
        $periodTwoEntries = $entries->where('period_batch_id', $batchIds->last());
        $this->assertCount(6, $periodOneEntries);
        $this->assertCount(2, $periodTwoEntries);
        $this->assertSame(10.0, round((float) $periodOneEntries->sum('hours_worked'), 2));
        $this->assertSame(4.0, round((float) $periodTwoEntries->sum('hours_worked'), 2));

        $index = $this->actingAs($admin)->get(route('operational.index', 'time-entries'));
        $index->assertOk();
        $content = $index->getContent();
        $this->assertSame(3, preg_match_all('/>Ver<\/a>/', $content));
        $this->assertSame(3, preg_match_all('/>Editar<\/a>/', $content));
        $index->assertSee('02/08/2026', false);
        $this->assertStringContainsString('03/08/2026', $content);
        $this->assertStringContainsString('10/08/2026', $content);
        $this->assertStringContainsString('12/08/2026', $content);
        $this->assertStringContainsString('13/08/2026', $content);
        $index->assertSee('10 h', false);
        $index->assertSee('4 h', false);
        $index->assertSee('UF 0,80 / HH', false);

        $show = $this->actingAs($admin)->get(route('operational.show', ['time-entries', $periodOneEntries->first()->id]));
        $show->assertOk();
        $show->assertSee('Carga por período', false);
        $show->assertSee('Pablo Toro', false);
        $show->assertSee('Kardex', false);
        $this->assertStringContainsString('03/08/2026', $show->getContent());
        $this->assertStringContainsString('10/08/2026', $show->getContent());
        $show->assertSee('6', false);
        $show->assertSee('10 h', false);
        $show->assertSee('UF 0,80 / HH', false);
        $show->assertSee('Eliminar bloque', false);
        $show->assertSee('Editar', false);
        $show->assertDontSee('1,67 h', false);

        $delete = $this->actingAs($admin)->delete(route('operational.destroy', ['time-entries', $periodOneEntries->first()->id]));
        $delete->assertRedirect(route('operational.index', 'time-entries'));
        $this->assertSame(0, TimeEntry::query()->where('period_batch_id', $batchIds->first())->count());
        $this->assertSame(3, TimeEntry::query()->where('company_id', $company->id)->where('person_id', $person->id)->count());

        $indexAfterDelete = $this->actingAs($admin)->get(route('operational.index', 'time-entries'));
        $indexAfterDelete->assertOk();
        $afterContent = $indexAfterDelete->getContent();
        $this->assertSame(2, preg_match_all('/>Ver<\/a>/', $afterContent));
        $this->assertSame(2, preg_match_all('/>Editar<\/a>/', $afterContent));
        $indexAfterDelete->assertDontSee('03/08/2026 - 10/08/2026', false);
        $indexAfterDelete->assertSee('12/08/2026 - 13/08/2026', false);
    }

    public function test_time_entries_batch_code_display_uses_real_sequences_only(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $clp = Currency::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'CLP'],
            ['name' => 'Peso Chileno', 'symbol' => '$', 'minor_units' => 0, 'active' => true, 'is_base_currency' => true]
        );

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-BLOCK-CODE',
            'legal_name' => 'Cliente Códigos',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $clp->id,
            'code' => 'PRY-TIME-BLOCK-CODE',
            'name' => 'Proyecto Códigos',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $activity = Activity::query()->create([
            'company_id' => $company->id,
            'code' => 'ACT-TIME-BLOCK-CODE',
            'name' => 'Actividad Códigos',
            'active' => true,
            'sort_order' => 1,
        ]);

        $approvedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-BLOCK-CODE',
            'first_names' => 'Pablo',
            'paternal_surname' => 'Toro',
            'name' => 'Pablo Toro',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => null,
            'hourly_value' => 0.8,
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-BLOCK-CODE',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => null,
            'hourly_value' => 0.8,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $assignmentId = ProjectAssignment::query()->where('code', 'ASI-TIME-BLOCK-CODE')->valueOrFail('id');

        foreach ([
            ['code' => 'HOR-000010', 'entry_date' => '2026-08-03', 'period_batch_id' => 'batch-non-consecutive'],
            ['code' => 'HOR-000020', 'entry_date' => '2026-08-04', 'period_batch_id' => 'batch-non-consecutive'],
            ['code' => 'HOR-000021', 'entry_date' => '2026-08-05', 'period_batch_id' => 'batch-non-consecutive'],
            ['code' => 'HOR-000011', 'entry_date' => '2026-08-06', 'period_batch_id' => null],
            ['code' => 'HOR-000030', 'entry_date' => '2026-08-10', 'period_batch_id' => 'batch-consecutive'],
            ['code' => 'HOR-000031', 'entry_date' => '2026-08-11', 'period_batch_id' => 'batch-consecutive'],
            ['code' => 'HOR-000032', 'entry_date' => '2026-08-12', 'period_batch_id' => 'batch-consecutive'],
            ['code' => 'HOR-000007', 'entry_date' => '2026-08-13', 'period_batch_id' => null],
        ] as $entry) {
            TimeEntry::query()->create([
                'company_id' => $company->id,
                'code' => $entry['code'],
                'period_batch_id' => $entry['period_batch_id'],
                'person_id' => $person->id,
                'client_id' => $client->id,
                'project_id' => $project->id,
                'assignment_id' => $assignmentId,
                'entry_date' => $entry['entry_date'],
                'activity_id' => $activity->id,
                'activity' => 'Actividad Códigos',
                'hours_worked' => 1,
                'hours_approved' => 1,
                'hourly_value' => 0.8,
                'approval_status_id' => $approvedStatus->id,
                'approval_status' => 'approved',
                'payment_status' => 'pending',
            ]);
        }

        $index = $this->actingAs($admin)->get(route('operational.index', 'time-entries'));
        $index->assertOk();
        $index->assertSee('HOR-000030–HOR-000032', false);
        $index->assertSee('HOR-000010 + 2 registros', false);
        $index->assertSee('HOR-000007', false);
        $index->assertSee('HOR-000011', false);
        $index->assertDontSee('HOR-000010–HOR-000021', false);

        $nonConsecutiveEntry = TimeEntry::query()->where('period_batch_id', 'batch-non-consecutive')->orderBy('entry_date')->firstOrFail();
        $showNonConsecutive = $this->actingAs($admin)->get(route('operational.show', ['time-entries', $nonConsecutiveEntry->id]));
        $showNonConsecutive->assertOk();
        $showNonConsecutive->assertSee('HOR-000010 + 2 registros', false);
        $showNonConsecutive->assertDontSee('HOR-000010–HOR-000021', false);

        $consecutiveEntry = TimeEntry::query()->where('period_batch_id', 'batch-consecutive')->orderBy('entry_date')->firstOrFail();
        $showConsecutive = $this->actingAs($admin)->get(route('operational.show', ['time-entries', $consecutiveEntry->id]));
        $showConsecutive->assertOk();
        $showConsecutive->assertSee('HOR-000030–HOR-000032', false);
    }

    public function test_time_entries_period_blocks_can_be_edited_as_batches(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-EDIT',
            'legal_name' => 'Cliente Edicion Bloque',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-TIME-EDIT',
            'name' => 'Proyecto Edicion Bloque',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $activityA = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-TIME-EDIT-A'],
            ['name' => 'Actividad Inicial', 'active' => true, 'sort_order' => 1]
        );
        $activityB = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-TIME-EDIT-B'],
            ['name' => 'Actividad Editada', 'active' => true, 'sort_order' => 2]
        );

        $approvedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-EDIT',
            'first_names' => 'Paula',
            'paternal_surname' => 'Edita',
            'name' => 'Paula Edita',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.8,
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-EDIT',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.8,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $create = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'entry_mode' => 'period',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'activity_id' => $activityA->id,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
            'period_start_date' => '03/08/2026',
            'period_end_date' => '10/08/2026',
            'period_distribution_mode' => 'total',
            'period_total_hours' => 10,
            'period_rows_payload' => '',
        ]);

        $create->assertRedirect(route('operational.index', 'time-entries'));

        $originalEntries = TimeEntry::query()
            ->where('company_id', $company->id)
            ->where('person_id', $person->id)
            ->whereNotNull('period_batch_id')
            ->orderBy('entry_date')
            ->get();
        $batchId = (string) $originalEntries->first()->period_batch_id;

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['time-entries', $originalEntries->first()->id]));
        $edit->assertOk();
        $edit->assertSee('Editar carga por período', false);
        $edit->assertSee('Operación / Horas / Editar carga por período', false);
        $edit->assertSee('Guardar bloque', false);
        $edit->assertSee('data-time-entry-period-preview-url', false);
        $edit->assertSee('03/08/2026', false);
        $edit->assertSee('10/08/2026', false);
        $edit->assertDontSee('<label for="period_distribution_mode"', false);
        $edit->assertDontSee('<label for="period_hours_per_day"', false);
        $edit->assertDontSee('<th style="width: 56px;">Incluir</th>', false);
        $this->assertDoesNotMatchRegularExpression('/;\s*<\/div>\s*<div>\s*<h1 class="page-title">Editar carga por período/s', $edit->getContent());

        $update = $this->actingAs($admin)->put(route('operational.update', ['time-entries', $originalEntries->first()->id]), [
            'entry_mode' => 'period',
            'period_batch_id' => $batchId,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'activity_id' => $activityB->id,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
            'period_start_date' => '04/08/2026',
            'period_end_date' => '12/08/2026',
            'period_distribution_mode' => 'total',
            'period_total_hours' => 12,
            'period_rows_payload' => '',
        ]);

        $updatedEntries = TimeEntry::query()
            ->where('company_id', $company->id)
            ->where('period_batch_id', $batchId)
            ->orderBy('entry_date')
            ->get();

        $update->assertRedirect(route('operational.show', ['time-entries', $updatedEntries->first()->id]));
        $this->assertCount(7, $updatedEntries);
        $this->assertCount(1, $updatedEntries->pluck('period_batch_id')->unique());
        $this->assertSame(12.0, round((float) $updatedEntries->sum('hours_worked'), 2));
        $this->assertSame([$activityB->id], $updatedEntries->pluck('activity_id')->unique()->all());
        $this->assertSame('2026-08-04', $updatedEntries->first()->entry_date?->toDateString());
        $this->assertSame('2026-08-12', $updatedEntries->last()->entry_date?->toDateString());
        $this->assertSame(0, TimeEntry::query()->where('period_batch_id', $batchId)->whereDate('entry_date', '2026-08-03')->count());

        $index = $this->actingAs($admin)->get(route('operational.index', 'time-entries'));
        $index->assertOk();
        $content = $index->getContent();
        $this->assertSame(1, preg_match_all('/>Ver<\/a>/', $content));
        $this->assertSame(1, preg_match_all('/>Editar<\/a>/', $content));
        $index->assertSee('04/08/2026', false);
        $index->assertSee('12/08/2026', false);
        $index->assertSee('12 h', false);
        $index->assertSee('UF 0,80 / HH', false);
        $index->assertDontSee('03/08/2026 - 10/08/2026', false);

        $show = $this->actingAs($admin)->get(route('operational.show', ['time-entries', $updatedEntries->first()->id]));
        $show->assertOk();
        $show->assertSee('Carga por período', false);
        $show->assertSee('Actividad Editada', false);
        $show->assertSee('04/08/2026', false);
        $show->assertSee('12/08/2026', false);
        $show->assertSee('12 h', false);
        $show->assertSee('UF 0,80 / HH', false);
        $show->assertSee('Editar', false);
        $show->assertDontSee('1,67 h', false);
    }

    public function test_time_entries_period_batch_edit_renders_initial_summary_and_save_without_changes_is_idempotent(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-IDEMP',
            'legal_name' => 'Clinica Los Andes',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-TIME-IDEMP',
            'name' => 'Kardex',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $activity = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-TIME-IDEMP'],
            ['name' => 'Soporte Idempotente', 'active' => true, 'sort_order' => 1]
        );
        $approvedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-IDEMP',
            'first_names' => 'Pablo',
            'paternal_surname' => 'Toro',
            'name' => 'Pablo Toro',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.8,
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-IDEMP',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.8,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $create = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'entry_mode' => 'period',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'activity_id' => $activity->id,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
            'period_start_date' => '03/08/2026',
            'period_end_date' => '10/08/2026',
            'period_distribution_mode' => 'total',
            'period_total_hours' => 10,
            'period_rows_payload' => '',
        ]);

        $create->assertRedirect(route('operational.index', 'time-entries'));

        $entries = TimeEntry::query()
            ->where('company_id', $company->id)
            ->whereNotNull('period_batch_id')
            ->orderBy('entry_date')
            ->get();

        $batchId = (string) $entries->first()->period_batch_id;
        $originalIds = $entries->pluck('id')->all();
        $originalSignature = $entries->map(fn (TimeEntry $entry): array => [
            'id' => $entry->id,
            'date' => optional($entry->entry_date)->toDateString(),
            'hours' => round((float) $entry->hours_worked, 2),
            'assignment_id' => $entry->assignment_id,
        ])->all();

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['time-entries', $entries->first()->id]));
        $edit->assertOk();
        $edit->assertSee('Pablo Toro', false);
        $edit->assertSee('Kardex', false);
        $edit->assertSee('Soporte Idempotente', false);
        $edit->assertSee('03/08/2026', false);
        $edit->assertSee('10/08/2026', false);
        $edit->assertSee('10', false);
        $edit->assertSee('Cliente: Clinica Los Andes', false);
        $edit->assertSee('Asignación: ASI-TIME-IDEMP · Kardex', false);
        $edit->assertSee('Valor HH de costeo del proyecto: UF 0,80 / HH', false);
        $edit->assertSee('6 días hábiles', false);
        $edit->assertSee('10 h', false);
        $edit->assertSee('Operación / Horas / Editar carga por período', false);
        $edit->assertDontSee('<label for="period_distribution_mode"', false);
        $edit->assertDontSee('<label for="period_hours_per_day"', false);
        $edit->assertDontSee('<th style="width: 56px;">Incluir</th>', false);
        $this->assertDoesNotMatchRegularExpression('/;\s*<\/div>\s*<div>\s*<h1 class="page-title">Editar carga por período/s', $edit->getContent());
        $this->assertSame($assignment->id, $entries->first()->assignment_id);

        $save = $this->actingAs($admin)->put(route('operational.update', ['time-entries', $entries->first()->id]), [
            'entry_mode' => 'period',
            'period_batch_id' => $batchId,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'activity_id' => $activity->id,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
            'period_start_date' => '03/08/2026',
            'period_end_date' => '10/08/2026',
            'period_distribution_mode' => 'total',
            'period_total_hours' => 10,
            'period_rows_payload' => '',
        ]);

        $after = TimeEntry::query()
            ->where('company_id', $company->id)
            ->where('period_batch_id', $batchId)
            ->orderBy('entry_date')
            ->get();

        $save->assertRedirect(route('operational.show', ['time-entries', $after->first()->id]));
        $this->assertSame($originalIds, $after->pluck('id')->all());
        $this->assertCount(1, $after->pluck('period_batch_id')->unique());
        $this->assertSame($batchId, (string) $after->first()->period_batch_id);
        $this->assertSame(6, $after->count());
        $this->assertSame(10.0, round((float) $after->sum('hours_worked'), 2));
        $this->assertSame($originalSignature, $after->map(fn (TimeEntry $entry): array => [
            'id' => $entry->id,
            'date' => optional($entry->entry_date)->toDateString(),
            'hours' => round((float) $entry->hours_worked, 2),
            'assignment_id' => $entry->assignment_id,
        ])->all());
    }

    public function test_time_entries_period_batch_edit_recalculates_person_project_assignment_and_preserves_uf(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $clientA = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-EDIT-A',
            'legal_name' => 'Cliente Inicial',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);
        $clientB = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-EDIT-B',
            'legal_name' => 'Cliente Destino',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $projectA = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $clientA->id,
            'code' => 'PRY-TIME-EDIT-A',
            'name' => 'Proyecto Inicial',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);
        $projectB = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $clientB->id,
            'code' => 'PRY-TIME-EDIT-B',
            'name' => 'Proyecto Destino',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $activity = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-TIME-EDIT-UF'],
            ['name' => 'Actividad UF', 'active' => true, 'sort_order' => 1]
        );
        $approvedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );

        $personA = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-EDIT-A',
            'first_names' => 'Ana',
            'paternal_surname' => 'Inicial',
            'name' => 'Ana Inicial',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.8,
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $personA->id,
            'client_id' => $clientA->id,
            'project_id' => $projectA->id,
            'code' => 'ASI-TIME-EDIT-A',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.8,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $personB = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-EDIT-B',
            'first_names' => 'Bruno',
            'paternal_surname' => 'Destino',
            'name' => 'Bruno Destino',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 1,
        ]);

        $assignmentB = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $personB->id,
            'client_id' => $clientB->id,
            'project_id' => $projectB->id,
            'code' => 'ASI-TIME-EDIT-B',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => null,
            'hourly_value' => null,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $create = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'entry_mode' => 'period',
            'person_id' => $personA->id,
            'project_id' => $projectA->id,
            'activity_id' => $activity->id,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
            'period_start_date' => '03/08/2026',
            'period_end_date' => '10/08/2026',
            'period_distribution_mode' => 'total',
            'period_total_hours' => 10,
            'period_rows_payload' => '',
        ]);

        $create->assertRedirect(route('operational.index', 'time-entries'));

        $originalEntries = TimeEntry::query()
            ->where('company_id', $company->id)
            ->where('person_id', $personA->id)
            ->whereNotNull('period_batch_id')
            ->orderBy('entry_date')
            ->get();
        $batchId = (string) $originalEntries->first()->period_batch_id;

        $update = $this->actingAs($admin)->put(route('operational.update', ['time-entries', $originalEntries->first()->id]), [
            'entry_mode' => 'period',
            'period_batch_id' => $batchId,
            'person_id' => $personB->id,
            'project_id' => $projectB->id,
            'activity_id' => $activity->id,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
            'period_start_date' => '03/08/2026',
            'period_end_date' => '10/08/2026',
            'period_distribution_mode' => 'total',
            'period_total_hours' => 10,
            'period_rows_payload' => '',
        ]);

        $updatedEntries = TimeEntry::query()
            ->where('company_id', $company->id)
            ->where('period_batch_id', $batchId)
            ->orderBy('entry_date')
            ->get();

        $update->assertRedirect(route('operational.show', ['time-entries', $updatedEntries->first()->id]));
        $this->assertCount(6, $updatedEntries);
        $this->assertSame([$personB->id], $updatedEntries->pluck('person_id')->unique()->all());
        $this->assertSame([$projectB->id], $updatedEntries->pluck('project_id')->unique()->all());
        $this->assertSame([$clientB->id], $updatedEntries->pluck('client_id')->unique()->all());
        $this->assertSame([$assignmentB->id], $updatedEntries->pluck('assignment_id')->unique()->all());
        $this->assertSame([1.0], $updatedEntries->pluck('hourly_value')->map(fn ($value) => round((float) $value, 2))->unique()->all());

        $index = $this->actingAs($admin)->get(route('operational.index', 'time-entries'));
        $index->assertOk();
        $index->assertSee('Bruno Destino', false);
        $index->assertSee('Proyecto Destino', false);
        $index->assertSee('UF 1,00 / HH', false);
        $index->assertDontSee('$ 1 / HH', false);

        $show = $this->actingAs($admin)->get(route('operational.show', ['time-entries', $updatedEntries->first()->id]));
        $show->assertOk();
        $show->assertSee('Bruno Destino', false);
        $show->assertSee('Proyecto Destino', false);
        $show->assertSee('Cliente Destino', false);
        $show->assertSee('UF 1,00 / HH', false);
    }

    public function test_time_entries_period_batch_edit_is_blocked_when_any_row_has_dependencies(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-LOCK',
            'legal_name' => 'Cliente Bloqueado',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-TIME-LOCK',
            'name' => 'Proyecto Bloqueado',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $activity = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-TIME-LOCK'],
            ['name' => 'Actividad Bloqueada', 'active' => true, 'sort_order' => 1]
        );
        $approvedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-LOCK',
            'first_names' => 'Laura',
            'paternal_surname' => 'Protegida',
            'name' => 'Laura Protegida',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.7,
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-LOCK',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.7,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $create = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'entry_mode' => 'period',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'activity_id' => $activity->id,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
            'period_start_date' => '03/08/2026',
            'period_end_date' => '04/08/2026',
            'period_distribution_mode' => 'total',
            'period_total_hours' => 4,
        ]);

        $create->assertRedirect(route('operational.index', 'time-entries'));

        $entries = TimeEntry::query()
            ->where('company_id', $company->id)
            ->whereNotNull('period_batch_id')
            ->orderBy('entry_date')
            ->get();

        $document = SalesDocument::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ING-TIME-LOCK',
            'document_type_id' => $this->documentTypeId($company->id, 'sales', 'FACTURA'),
            'document_type' => 'Factura',
            'issue_date' => '2026-08-05',
            'net_amount' => 1000,
            'vat_amount' => 190,
            'gross_amount' => 1190,
            'status' => 'Pendiente',
        ]);

        SalesDocumentTimeEntry::query()->create([
            'company_id' => $company->id,
            'sales_document_id' => $document->id,
            'time_entry_id' => $entries->first()->id,
            'project_assignment_id' => $assignment->id,
            'hours_approved' => 2,
            'hourly_rate_amount' => 0.7,
            'rate_unit_type' => 'UF',
            'currency_id' => null,
            'subtotal_original' => 1.4,
            'conversion_rate' => 39000,
            'conversion_date' => '2026-08-05',
            'subtotal_clp' => 54600,
        ]);

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['time-entries', $entries->first()->id]));
        $edit->assertRedirect(route('operational.show', ['time-entries', $entries->first()->id]));
        $edit->assertSessionHasErrors('dependencies');

        $update = $this->actingAs($admin)
            ->from(route('operational.show', ['time-entries', $entries->first()->id]))
            ->put(route('operational.update', ['time-entries', $entries->first()->id]), [
                'entry_mode' => 'period',
                'period_batch_id' => $entries->first()->period_batch_id,
                'person_id' => $person->id,
                'project_id' => $project->id,
                'activity_id' => $activity->id,
                'approval_status_id' => $approvedStatus->id,
                'payment_status' => 'pending',
                'period_start_date' => '03/08/2026',
                'period_end_date' => '05/08/2026',
                'period_distribution_mode' => 'total',
                'period_total_hours' => 6,
                'period_rows_payload' => '',
            ]);

        $update->assertRedirect(route('operational.show', ['time-entries', $entries->first()->id]));
        $update->assertSessionHasErrors('dependencies');
        $this->assertCount(2, TimeEntry::query()->where('period_batch_id', $entries->first()->period_batch_id)->get());
        $this->assertSame(4.0, round((float) TimeEntry::query()->where('period_batch_id', $entries->first()->period_batch_id)->sum('hours_worked'), 2));
    }

    public function test_time_entries_period_batch_edit_rejects_daily_overflow_transactionally(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-OVERFLOW',
            'legal_name' => 'Cliente Overflow',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-TIME-OVERFLOW',
            'name' => 'Proyecto Overflow',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $activity = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-TIME-OVERFLOW'],
            ['name' => 'Actividad Overflow', 'active' => true, 'sort_order' => 1]
        );
        $approvedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-OVERFLOW',
            'first_names' => 'Mario',
            'paternal_surname' => 'Tope',
            'name' => 'Mario Tope',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.8,
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-OVERFLOW',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.8,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HOR-TIME-OVERFLOW-BASE',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-03',
            'activity_id' => $activity->id,
            'activity' => 'Actividad Overflow',
            'hours_worked' => 20,
            'hours_approved' => 20,
            'approval_status_id' => $approvedStatus->id,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
            'hourly_value' => 0.8,
            'calculated_amount' => 16,
        ]);

        $create = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'entry_mode' => 'period',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'activity_id' => $activity->id,
            'approval_status_id' => $approvedStatus->id,
            'payment_status' => 'pending',
            'period_start_date' => '04/08/2026',
            'period_end_date' => '05/08/2026',
            'period_distribution_mode' => 'total',
            'period_total_hours' => 4,
        ]);

        $create->assertRedirect(route('operational.index', 'time-entries'));

        $entries = TimeEntry::query()
            ->where('company_id', $company->id)
            ->whereNotNull('period_batch_id')
            ->orderBy('entry_date')
            ->get();
        $batchId = (string) $entries->first()->period_batch_id;

        $update = $this->actingAs($admin)
            ->from(route('operational.edit', ['time-entries', $entries->first()->id]))
            ->put(route('operational.update', ['time-entries', $entries->first()->id]), [
                'entry_mode' => 'period',
                'period_batch_id' => $batchId,
                'person_id' => $person->id,
                'project_id' => $project->id,
                'activity_id' => $activity->id,
                'approval_status_id' => $approvedStatus->id,
                'payment_status' => 'pending',
                'period_start_date' => '03/08/2026',
                'period_end_date' => '04/08/2026',
                'period_distribution_mode' => 'total',
                'period_total_hours' => 10,
                'period_rows_payload' => '',
            ]);

        $update->assertRedirect(route('operational.edit', ['time-entries', $entries->first()->id]));
        $update->assertSessionHasErrors('period_rows');

        $unchangedEntries = TimeEntry::query()
            ->where('company_id', $company->id)
            ->where('period_batch_id', $batchId)
            ->orderBy('entry_date')
            ->get();

        $this->assertCount(2, $unchangedEntries);
        $this->assertSame(4.0, round((float) $unchangedEntries->sum('hours_worked'), 2));
        $this->assertSame('2026-08-04', $unchangedEntries->first()->entry_date?->toDateString());
        $this->assertSame('2026-08-05', $unchangedEntries->last()->entry_date?->toDateString());
    }

    public function test_time_entries_period_load_blocks_invalid_rows_transactionally(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-TIME-PERIOD-ERR',
            'legal_name' => 'Cliente Período Error',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-TIME-PERIOD-ERR',
            'name' => 'Proyecto Período Error',
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $activity = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-TIME-PERIOD-ERR'],
            ['name' => 'Actividad Período Error', 'active' => true, 'sort_order' => 1]
        );

        $approvedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );
        $pendingStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'pending'],
            ['name' => 'Pendiente', 'active' => true, 'sort_order' => 2]
        );
        $rejectedStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'rejected'],
            ['name' => 'Rechazado', 'active' => true, 'sort_order' => 3]
        );

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-TIME-PERIOD-ERR',
            'first_names' => 'Elena',
            'paternal_surname' => 'Error',
            'name' => 'Elena Error',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_value' => 0.55,
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-PERIOD-ERR',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-08-24',
            'end_date' => '2026-08-31',
        ]);

        TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HOR-TIME-PERIOD-BASE',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => ProjectAssignment::query()->where('code', 'ASI-TIME-PERIOD-ERR')->valueOrFail('id'),
            'entry_date' => '2026-08-24',
            'activity_id' => $activity->id,
            'activity' => 'Actividad Período Error',
            'hours_worked' => 20,
            'hours_approved' => 0,
            'approval_status_id' => $pendingStatus->id,
            'approval_status' => 'pending',
            'payment_status' => 'pending',
        ]);

        $dailyOverflow = $this->actingAs($admin)
            ->from(route('operational.create', 'time-entries'))
            ->post(route('operational.store', 'time-entries'), [
                'entry_mode' => 'period',
                'person_id' => $person->id,
                'project_id' => $project->id,
                'activity_id' => $activity->id,
                'approval_status_id' => $approvedStatus->id,
                'payment_status' => 'pending',
                'period_start_date' => '24/08/2026',
                'period_end_date' => '25/08/2026',
                'period_distribution_mode' => 'total',
                'period_total_hours' => 16,
            ]);

        $dailyOverflow->assertRedirect(route('operational.create', 'time-entries'));
        $dailyOverflow->assertSessionHasErrors('period_rows');
        $this->assertSame(1, TimeEntry::query()->where('company_id', $company->id)->count());

        $outOfRange = $this->actingAs($admin)
            ->from(route('operational.create', 'time-entries'))
            ->post(route('operational.store', 'time-entries'), [
                'entry_mode' => 'period',
                'person_id' => $person->id,
                'project_id' => $project->id,
                'activity_id' => $activity->id,
                'approval_status_id' => $approvedStatus->id,
                'payment_status' => 'pending',
                'period_start_date' => '01/09/2026',
                'period_end_date' => '02/09/2026',
                'period_distribution_mode' => 'total',
                'period_total_hours' => 16,
            ]);

        $outOfRange->assertSessionHasErrors('period_rows');
        $this->assertSame(1, TimeEntry::query()->where('company_id', $company->id)->count());

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-TIME-PERIOD-AMB',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-08-25',
            'end_date' => '2026-08-26',
        ]);

        $ambiguous = $this->actingAs($admin)
            ->from(route('operational.create', 'time-entries'))
            ->post(route('operational.store', 'time-entries'), [
                'entry_mode' => 'period',
                'person_id' => $person->id,
                'project_id' => $project->id,
                'activity_id' => $activity->id,
                'approval_status_id' => $approvedStatus->id,
                'payment_status' => 'pending',
                'period_start_date' => '25/08/2026',
                'period_end_date' => '26/08/2026',
                'period_distribution_mode' => 'total',
                'period_total_hours' => 4,
            ]);

        $ambiguous->assertSessionHasErrors('period_rows');
        $this->assertSame(1, TimeEntry::query()->where('company_id', $company->id)->count());

        $invalidPayment = $this->actingAs($admin)
            ->from(route('operational.create', 'time-entries'))
            ->post(route('operational.store', 'time-entries'), [
                'entry_mode' => 'period',
                'person_id' => $person->id,
                'project_id' => $project->id,
                'activity_id' => $activity->id,
                'approval_status_id' => $rejectedStatus->id,
                'payment_status' => 'paid',
                'period_start_date' => '27/08/2026',
                'period_end_date' => '28/08/2026',
                'period_distribution_mode' => 'total',
                'period_total_hours' => 4,
            ]);

        $invalidPayment->assertSessionHasErrors('payment_status');
        $this->assertSame(1, TimeEntry::query()->where('company_id', $company->id)->count());
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
        $response->assertSee('payroll-base-grid', false);
        $response->assertSeeText('Referencia de la remuneración');
        $response->assertSeeText('Ajustes / overrides');
        $response->assertSeeText('Adicionales');
        $response->assertSeeText('Descuentos');
        $response->assertSeeText('Resultado');
        $response->assertSeeText('Control del cálculo');
        $response->assertSeeText('Horas aprobadas del período');
        $response->assertSeeText('Origen: módulo Horas.');
        $response->assertDontSeeText('Override horas aprobadas');
        $response->assertSeeText('Valor HH base de Persona');
        $response->assertSee('payroll-base-row-1', false);
        $response->assertSee('payroll-base-row-2', false);
        $this->assertRowClassHasColumns($response->getContent(), 'payroll-base-row-1', ['Código', 'Persona', 'Proyecto']);
        $this->assertRowClassHasColumns($response->getContent(), 'payroll-base-row-2', ['Período', 'Base pactada', 'Fecha pago']);
        $this->assertDoesNotMatchRegularExpression('/;\s*<\/div>\s*<div>\s*<h1 class="page-title">Nueva remuneración/s', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/name="hours_approved"/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/name="payment_date"[^>]*value="—"/', $response->getContent());
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
        $response->assertSee('payroll-base-grid', false);
        $response->assertSee('payroll-base-row-1', false);
        $response->assertSee('payroll-base-row-2', false);
        $this->assertRowClassHasColumns($response->getContent(), 'payroll-base-row-1', ['Código', 'Persona', 'Proyecto']);
        $this->assertRowClassHasColumns($response->getContent(), 'payroll-base-row-2', ['Período', 'Base pactada', 'Fecha pago']);
        $response->assertSeeText('Referencia de la remuneración');
        $response->assertSeeText('Horas aprobadas del período');
        $response->assertSeeText('Origen: módulo Horas.');
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
        $response->assertSee('Tarifa pactada', false);
        $response->assertSee('Tarifa convertida', false);
        $response->assertSee('Horas aprobadas del período', false);
        $response->assertSee('Origen: módulo Horas.', false);
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
            'status' => \App\Services\PayrollService::STATUS_PENDING_PAYMENT_DATE,
        ]);

        $response = $this->actingAs($admin)->get(route('operational.edit', ['payroll-records', $payroll->id]));

        $response->assertOk();
        $response->assertSee('Editar remuneración', false);
        $response->assertSee('Horas aprobadas del período', false);
        $response->assertSee('10 h', false);
        $response->assertSee('Origen: módulo Horas.', false);
        $this->assertDoesNotMatchRegularExpression('/name="hours_approved"/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/name="payment_date"[^>]*value="—"/', $response->getContent());
    }

    public function test_payroll_edit_reclassifies_historical_automatic_values_without_false_overrides(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        $uf = $this->currency($company->id, 'UF', 'Unidad de Fomento');
        UfValue::query()->create([
            'company_id' => $company->id,
            'value_date' => '2026-08-01',
            'value' => 40844.79,
        ]);

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-REM-HIST',
            'legal_name' => 'Cliente Histórico',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $uf->id,
            'code' => 'PRY-REM-HIST',
            'name' => 'Alertas de Matrículas',
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-REM-HIST',
            'first_names' => 'Jaime',
            'paternal_surname' => 'Soriano',
            'maternal_surname' => 'Prueba',
            'name' => 'Jaime Soriano',
            'modality' => 'Honorarios por proyecto',
            'employment_mode_id' => $this->employmentModeId($company->id, 'POR_PROYECTO'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-000010',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $uf->id,
            'hourly_value' => 0.50,
            'project_value' => 100.00,
        ]);

        TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HRS-REM-HIST',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-15',
            'activity' => 'Implementación',
            'hours_worked' => 10,
            'hours_approved' => 10,
            'hourly_value' => 20422,
            'calculated_amount' => 204220,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        $payroll = PayrollRecord::query()->create([
            'company_id' => $company->id,
            'code' => 'REM-000001',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'hours_approved' => 10,
            'hourly_value' => 20422,
            'project_value' => 4084479,
            'base_salary' => 4084479,
            'gross_amount' => 4084479,
            'taxable_amount' => 0,
            'taxable_gross' => 0,
            'employee_retention' => 622883,
            'retention_rate' => 0.1525,
            'employer_cost' => 4084479,
            'net_pay' => 3461596,
            'calculation_status' => 'OK',
            'legal_snapshot' => ['period' => '2026-08-01', 'honorarios_retention_rate' => 0.1525],
            'status' => 'Pendiente',
        ]);

        $response = $this->actingAs($admin)->get(route('operational.edit', ['payroll-records', $payroll->id]));

        $response->assertOk();
        $response->assertSee('Agosto 2026');
        $response->assertSee('Horas aprobadas del período');
        $response->assertSee('10 h');
        $response->assertSee('Origen: módulo Horas.');
        $response->assertSee('Valor proyecto / hito pactado');
        $response->assertSee('UF 100,00');
        $response->assertSee('Valor convertido: $ 4.084.479');
        $response->assertSee('Valor efectivo: $ 4.084.479');
        $response->assertSee('Valor HH base de Persona');
        $response->assertDontSee('/ HH / HH', false);
        $this->assertDoesNotMatchRegularExpression('/name="hours_approved"/', $response->getContent());
        $this->assertMatchesRegularExpression('/name="hourly_value"[^>]*value=""/', $response->getContent());
        $this->assertMatchesRegularExpression('/name="project_value"[^>]*value=""/', $response->getContent());
        $this->assertMatchesRegularExpression('/name="period_date"[^>]*value="2026-08-01"/', $response->getContent());
        $this->assertMatchesRegularExpression('/id="period_date"[^>]*value="Agosto 2026"/', $response->getContent());
    }

    public function test_payroll_project_mode_missing_project_value_shows_review_status_and_not_calculable_results(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        [$client, , $project] = $this->clientProjectFixtures($company->id);
        LegalParameter::query()->create([
            'company_id' => $company->id,
            'parameter_code' => 'RETENCION_HONORARIOS',
            'parameter_name' => 'Retención honorarios',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'value' => 0.1525,
            'unit' => '%',
            'active' => true,
        ]);
        UfValue::query()->create([
            'company_id' => $company->id,
            'value_date' => '2026-08-01',
            'value' => 40844.79,
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-REM-MISS-PROJ',
            'first_names' => 'Jaime',
            'paternal_surname' => 'Soriano',
            'maternal_surname' => 'Caso',
            'name' => 'Jaime Soriano',
            'modality' => 'Honorarios por proyecto',
            'hourly_value' => 1.00,
            'hourly_rate_unit_type' => 'UF',
            'employment_mode_id' => $this->employmentModeId($company->id, 'POR_PROYECTO'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-000018',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
            'project_value' => null,
        ]);

        TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HRS-REM-MISS-PROJ',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-15',
            'activity' => 'Implementación',
            'hours_worked' => 10,
            'hours_approved' => 10,
            'hourly_value' => 1,
            'calculated_amount' => 10,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        $payroll = PayrollRecord::query()->create([
            'company_id' => $company->id,
            'code' => 'REM-MISS-PROJ-01',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'hours_approved' => 10,
            'base_salary' => 0,
            'gross_amount' => 0,
            'taxable_gross' => 0,
            'employee_retention' => 0,
            'employer_cost' => 0,
            'net_pay' => 0,
            'calculation_status' => 'REQUIERE_REVISION',
            'calculation_notes' => 'Valor proyecto/hito no configurado para la asignación vigente.',
            'status' => 'Requiere revisión',
        ]);

        $response = $this->actingAs($admin)->get(route('operational.edit', ['payroll-records', $payroll->id]));

        $response->assertOk();
        $response->assertSee('Revisión requerida');
        $response->assertSee('Requiere revisión');
        $response->assertSee('Valor proyecto/hito no configurado para la asignación vigente.');
        $response->assertSeeText('No calculable');
        $response->assertSee('Horas aprobadas del período');
        $response->assertSee('10 h');
        $response->assertSee('Origen: módulo Horas.');
        $response->assertSee('Tarifa pactada');
        $response->assertSee('No aplica');
        $response->assertSee('La modalidad por proyecto/hito usa el valor proyecto/hito pactado.');
        $response->assertSee('Valor HH base de Persona');
        $response->assertSee('Referencia. No participa en el cálculo de esta remuneración.');
        $response->assertSee('Costo HH ref.');
        $this->assertDoesNotMatchRegularExpression('/Estado:\s*<\/span>\s*<span[^>]*>\s*REQUIERE_REVISION\s*<\/span>/s', $response->getContent());
        $response->assertDontSee('/ HH / HH', false);
        $this->assertDoesNotMatchRegularExpression('/Costo HH ref\\.\\s*<\\/div>\\s*<div class=\"fw-semibold\">\\$ 0/s', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/;\s*<\/div>\s*<div>\s*<h1 class="page-title">Editar remuneración/s', $response->getContent());
    }

    public function test_payroll_project_mode_missing_project_value_can_be_saved_with_numeric_zero_adjustments(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        [$client, , $project] = $this->clientProjectFixtures($company->id);
        UfValue::query()->create([
            'company_id' => $company->id,
            'value_date' => '2026-08-01',
            'value' => 40844.79,
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-REM-SAVE-ZERO',
            'first_names' => 'Jaime',
            'paternal_surname' => 'Soriano',
            'maternal_surname' => 'Caso',
            'name' => 'Jaime Soriano',
            'modality' => 'Honorarios por proyecto',
            'hourly_value' => 1.00,
            'hourly_rate_unit_type' => 'UF',
            'employment_mode_id' => $this->employmentModeId($company->id, 'POR_PROYECTO'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-000019',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
            'project_value' => null,
        ]);

        $payroll = PayrollRecord::query()->create([
            'company_id' => $company->id,
            'code' => 'REM-SAVE-ZERO-01',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'hours_approved' => 10,
            'base_salary' => 0,
            'gross_amount' => 0,
            'taxable_gross' => 0,
            'employee_retention' => 0,
            'employer_cost' => 0,
            'net_pay' => 0,
            'calculation_status' => 'REQUIERE_REVISION',
            'calculation_notes' => 'Valor proyecto/hito no configurado para la asignación vigente.',
            'status' => 'Requiere revisión',
            'bonuses' => 0,
            'non_taxable_allowances' => 0,
            'advances' => 0,
            'other_deductions' => 0,
            'project_value' => null,
        ]);

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['payroll-records', $payroll->id]));
        $edit->assertOk();
        $this->assertMatchesRegularExpression('/name="bonuses"[^>]*value="0(?:\.0+)?"/', $edit->getContent());
        $this->assertMatchesRegularExpression('/name="non_taxable_allowances"[^>]*value="0(?:\.0+)?"/', $edit->getContent());
        $this->assertMatchesRegularExpression('/name="advances"[^>]*value="0(?:\.0+)?"/', $edit->getContent());
        $this->assertMatchesRegularExpression('/name="other_deductions"[^>]*value="0(?:\.0+)?"/', $edit->getContent());

        $update = $this->actingAs($admin)->put(route('operational.update', ['payroll-records', $payroll->id]), [
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'payment_date' => '',
            'amount_basis' => 'GROSS',
            'hours_approved' => 10,
            'monthly_value' => '',
            'hourly_value' => '',
            'project_value' => '',
            'bonuses' => '10000',
            'non_taxable_allowances' => '20000',
            'advances' => '3000',
            'other_deductions' => '1500',
            'status' => 'Requiere revisión',
        ]);

        $update->assertRedirect(route('operational.index', 'payroll-records').'/'.$payroll->id);
    }

    public function test_payroll_project_mode_with_configured_project_value_saves_calculated_record_without_server_error(): void
    {
        $this->withoutExceptionHandling();

        [$company, $admin] = $this->companyWithAdmin();
        [$client, , $project] = $this->clientProjectFixtures($company->id);
        LegalParameter::query()->create([
            'company_id' => $company->id,
            'parameter_code' => 'RETENCION_HONORARIOS',
            'parameter_name' => 'Retención honorarios',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'value' => 0.1525,
            'unit' => '%',
            'active' => true,
        ]);
        UfValue::query()->create([
            'company_id' => $company->id,
            'value_date' => '2026-08-01',
            'value' => 40844.79,
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-REM-SAVE-OK',
            'first_names' => 'Jaime',
            'paternal_surname' => 'Soriano',
            'maternal_surname' => 'Caso',
            'name' => 'Jaime Soriano',
            'modality' => 'Honorarios por proyecto',
            'hourly_value' => 1.00,
            'hourly_rate_unit_type' => 'UF',
            'employment_mode_id' => $this->employmentModeId($company->id, 'POR_PROYECTO'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-REM-SAVE-OK',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
            'project_value' => 100.00,
            'hourly_rate_unit_type' => 'UF',
        ]);

        TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HRS-REM-SAVE-OK',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-15',
            'activity' => 'Implementación',
            'hours_worked' => 10,
            'hours_approved' => 10,
            'hourly_value' => 1,
            'calculated_amount' => 10,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        $payroll = PayrollRecord::query()->create([
            'company_id' => $company->id,
            'code' => 'REM-SAVE-OK-01',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'hours_approved' => 10,
            'base_salary' => 4084479,
            'gross_amount' => 4084479,
            'taxable_gross' => 0,
            'employee_retention' => 622883,
            'employer_cost' => 4084479,
            'net_pay' => 3461596,
            'calculation_status' => 'OK',
            'status' => 'Pendiente',
            'project_value' => null,
            'bonuses' => 0,
            'non_taxable_allowances' => 0,
            'advances' => 0,
            'other_deductions' => 0,
        ]);

        $response = $this->actingAs($admin)
            ->from(route('operational.edit', ['payroll-records', $payroll->id]))
            ->put(route('operational.update', ['payroll-records', $payroll->id]), [
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

        $response->assertRedirect(route('operational.show', ['payroll-records', $payroll->id]));
        $response->assertSessionHas('status', 'Registro actualizado.');

        $payroll->refresh();
        $this->assertSame(\App\Services\PayrollService::STATUS_PENDING_PAYMENT_DATE, $payroll->status);
        $this->assertSame('OK', $payroll->calculation_status);
        $this->assertEqualsWithDelta(4084479.0, (float) $payroll->base_salary, 0.01);
        $this->assertEqualsWithDelta(622883.05, (float) $payroll->employee_retention, 0.01);
        $this->assertEqualsWithDelta(3461595.95, (float) $payroll->net_pay, 0.01);
        $this->assertEqualsWithDelta(4084479.0, (float) $payroll->employer_cost, 0.01);

        $overrides = app(\App\Services\PayrollService::class)->manualOverrideInputs($payroll);
        $this->assertNull($overrides['hours_approved'] ?? null);
        $this->assertNull($overrides['monthly_value'] ?? null);
        $this->assertNull($overrides['hourly_value'] ?? null);
        $this->assertNull($overrides['project_value'] ?? null);

        $show = $this->actingAs($admin)->get(route('operational.show', ['payroll-records', $payroll->id]));
        $show->assertOk();
        $show->assertSee('Pendiente de fecha de pago');
        $show->assertDontSee(\App\Services\PayrollService::STATUS_PENDING_PAYMENT_DATE);
    }

    public function test_payroll_index_does_not_show_automatic_hours_as_override_when_no_manual_override_exists(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        [$client, , $project] = $this->clientProjectFixtures($company->id);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-REM-LIST-AUTO',
            'first_names' => 'Lucía',
            'paternal_surname' => 'Rivas',
            'maternal_surname' => 'Auto',
            'name' => 'Lucía Rivas',
            'modality' => 'Honorarios por proyecto',
            'employment_mode_id' => $this->employmentModeId($company->id, 'POR_PROYECTO'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'code' => 'ASI-LIST-AUTO',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'project_value' => 100,
        ]);

        TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HRS-LIST-AUTO',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-15',
            'activity' => 'Implementación',
            'hours_worked' => 10,
            'hours_approved' => 10,
            'hourly_value' => 1,
            'calculated_amount' => 10,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        PayrollRecord::query()->create([
            'company_id' => $company->id,
            'code' => 'REM-LIST-AUTO-01',
            'person_id' => $person->id,
            'project_id' => $project->id,
            'period_date' => '2026-08-01',
            'hours_approved' => 10,
            'project_value' => 100,
            'base_salary' => 100,
            'gross_amount' => 100,
            'taxable_gross' => 0,
            'employee_retention' => 15.25,
            'employer_cost' => 100,
            'net_pay' => 84.75,
            'calculation_status' => 'OK',
            'status' => 'Pendiente',
        ]);

        $response = $this->actingAs($admin)->get(route('operational.index', 'payroll-records'));

        $response->assertOk();
        $response->assertSee('Override horas aprobadas');
        $this->assertDoesNotMatchRegularExpression('/REM-LIST-AUTO-01(?s).*?<td class=\"text-end amount-cell\">10 h<\\/td>/', $response->getContent());
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

    public function test_assignments_render_project_commitment_preview_and_preview_endpoint(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        $clp = $this->currency($company->id, 'CLP', 'Peso chileno');

        LegalParameter::query()->create([
            'company_id' => $company->id,
            'parameter_code' => 'RETENCION_HONORARIOS',
            'parameter_name' => 'Retención honorarios',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'value' => 0.1525,
            'unit' => '%',
        ]);

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-COMMIT-UI',
            'legal_name' => 'Cliente Compromiso UI',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $clp->id,
            'code' => 'PRY-COMMIT-UI',
            'name' => 'Proyecto Compromiso UI',
            'sale_net' => 50000,
            'contracted_hourly_rate' => 1000,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-COMMIT-UI',
            'first_names' => 'Persona',
            'paternal_surname' => 'Compromiso',
            'name' => 'Persona Compromiso',
            'modality' => 'Pago por hora',
            'employment_mode_id' => $this->employmentModeId($company->id, 'PAGO_POR_HORA'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $clp->id,
            'hourly_value' => 1000,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'code' => 'ASI-COMMIT-UI',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $clp->id,
            'hourly_value' => null,
            'monthly_hours' => 40,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $assignment->id]));

        $edit->assertOk();
        $edit->assertSee('Compromiso del proyecto');
        $edit->assertSee('data-assignment-commitment-preview-url="'.route('operational.assignment-commitment-preview', 'assignments').'"', false);
        $edit->assertSee('Venta contractual: $ 50.000', false);
        $edit->assertSee('Costo estimado de esta asignación: $ 40.000', false);
        $edit->assertSee('Compromiso después de guardar: $ 40.000', false);
        $edit->assertSee('Margen proyectado después de guardar: $ 10.000', false);

        $preview = $this->actingAs($admin)->post(route('operational.assignment-commitment-preview', 'assignments'), [
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $clp->id,
            'hourly_value' => '',
            'project_value' => '',
            'monthly_hours' => 60,
            'start_date' => '01/08/2026',
            'end_date' => '31/08/2026',
            'exclude_assignment_id' => $assignment->id,
        ]);

        $preview->assertOk()
            ->assertJsonPath('sale_net_clp', 50000)
            ->assertJsonPath('current_personnel_committed_cost', 0)
            ->assertJsonPath('assignment_estimated_cost', 60000)
            ->assertJsonPath('after_save_personnel_committed_cost', 60000)
            ->assertJsonPath('projected_personnel_margin', -10000)
            ->assertJsonPath('negative_margin', true)
            ->assertJsonPath('negative_margin_amount', 10000);
    }

    public function test_assignments_render_project_commitment_preview_with_projected_uf_note_for_future_periods(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        $uf = $this->currency($company->id, 'UF', 'Unidad de Fomento');
        $clp = $this->currency($company->id, 'CLP', 'Peso chileno');

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-COMMIT-UF',
            'legal_name' => 'Cliente UF UI',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $uf->id,
            'code' => 'PRY-COMMIT-UF',
            'name' => 'Proyecto UF UI',
            'sale_net' => 50000,
            'contracted_hourly_rate' => 1000,
            'start_date' => '2027-10-01',
            'end_date' => '2027-10-31',
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-COMMIT-UF',
            'first_names' => 'Persona',
            'paternal_surname' => 'UF',
            'name' => 'Persona UF',
            'modality' => 'Pago por hora',
            'employment_mode_id' => $this->employmentModeId($company->id, 'PAGO_POR_HORA'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $uf->id,
            'hourly_value' => 0,
            'start_date' => '2027-10-01',
            'end_date' => '2027-10-31',
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'code' => 'ASI-COMMIT-UF',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $uf->id,
            'hourly_value' => 1,
            'monthly_hours' => 10,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]);

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $assignment->id]));

        $edit->assertOk();
        $edit->assertSee('Compromiso del proyecto', false);
        $edit->assertSee('data-assignment-commitment-preview-url', false);
    }

    public function test_assignments_and_project_show_display_contractual_sale_currency_and_clp_equivalent(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        $uf = $this->currency($company->id, 'UF', 'Unidad de Fomento');

        $this->ufValue($company->id, '2026-08-01', 40845.0);
        $this->ufValue($company->id, '2026-09-01', 40845.0);

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-NORM-UF',
            'legal_name' => 'Cliente Normalización UF',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $uf->id,
            'code' => 'PRY-NORM-UF',
            'name' => 'Proyecto Normalización UF',
            'sale_net' => 110,
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-01',
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-NORM-UF',
            'first_names' => 'Normalización',
            'paternal_surname' => 'UF',
            'name' => 'Normalización UF',
            'modality' => 'Pago por hora',
            'employment_mode_id' => $this->employmentModeId($company->id, 'PAGO_POR_HORA'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $uf->id,
            'hourly_value' => 0,
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'code' => 'ASI-NORM-UF',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'UF',
            'hourly_rate_currency_id' => $uf->id,
            'hourly_value' => 1,
            'monthly_hours' => 10,
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-01',
        ]);

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['assignments', $assignment->id]));
        $edit->assertOk();
        $edit->assertSee('Venta contractual: UF 110,00', false);
        $edit->assertSee('Equivalente para proyección: $ 4.492.950', false);

        $show = $this->actingAs($admin)->get(route('operational.show', ['projects', $project->id]));
        $show->assertOk();
        $show->assertSee('Venta contractual', false);
        $show->assertSee('UF 110,00', false);
        $show->assertSee('Equivalente para proyección: $ 4.492.950', false);
    }

    public function test_project_show_displays_personnel_commitment_summary(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        $clp = $this->currency($company->id, 'CLP', 'Peso chileno');

        LegalParameter::query()->create([
            'company_id' => $company->id,
            'parameter_code' => 'RETENCION_HONORARIOS',
            'parameter_name' => 'Retención honorarios',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'value' => 0.1525,
            'unit' => '%',
        ]);

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-COMMIT-SHOW',
            'legal_name' => 'Cliente Show',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'sales_currency_id' => $clp->id,
            'code' => 'PRY-COMMIT-SHOW',
            'name' => 'Proyecto Show',
            'sale_net' => 50000,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'project_status_id' => $this->statusId($company->id, 'project', 'EN_EJECUCION'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-COMMIT-SHOW',
            'name' => 'Persona Show',
            'modality' => 'Pago por hora',
            'employment_mode_id' => $this->employmentModeId($company->id, 'PAGO_POR_HORA'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'code' => 'ASI-COMMIT-SHOW',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'hourly_rate_unit_type' => 'CURRENCY',
            'hourly_rate_currency_id' => $clp->id,
            'hourly_value' => 1000,
            'monthly_hours' => 40,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $show = $this->actingAs($admin)->get(route('operational.show', ['projects', $project->id]));

        $show->assertOk();
        $show->assertSee('Compromiso de personal');
        $show->assertSee('Venta neta');
        $show->assertSee('$ 50.000', false);
        $show->assertSee('Personal comprometido');
        $show->assertSee('$ 40.000', false);
        $show->assertSee('Margen proyectado de personal');
        $show->assertSee('$ 10.000', false);
        $show->assertSee('80 %', false);
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

    private function ufValue(int $companyId, string $date, float $value): void
    {
        $existing = UfValue::query()
            ->where('company_id', $companyId)
            ->whereDate('value_date', $date)
            ->first();

        if ($existing) {
            $existing->update(['value' => $value]);

            return;
        }

        UfValue::query()->create([
            'company_id' => $companyId,
            'value_date' => $date,
            'value' => $value,
        ]);
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

        $candidateRows = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " row ")]');
        $matched = false;

        foreach ($candidateRows as $rowNode) {
            $directColumns = $xpath->query('./div[contains(@class, "col-")]', $rowNode);
            if ($directColumns->length < count($labels)) {
                continue;
            }

            $columnTexts = [];
            foreach ($directColumns as $column) {
                $columnTexts[] = trim(preg_replace('/\s+/u', ' ', $column->textContent));
            }

            $allPresent = collect($labels)->every(
                fn (string $label) => collect($columnTexts)->contains(fn (string $text) => str_contains($text, $label))
            );

            if ($allPresent) {
                $matched = true;
                break;
            }
        }

        $this->assertTrue($matched, 'No se encontró una row horizontal con las columnas esperadas');
    }

    private function assertRowClassHasColumns(string $html, string $rowClass, array $labels): void
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $rowNode = $xpath->query(sprintf(
            '//div[contains(concat(" ", normalize-space(@class), " "), %s)]',
            $this->xpathLiteral(' '.$rowClass.' ')
        ))->item(0);

        $this->assertNotNull($rowNode, "No se encontró la row {$rowClass}");

        $directColumns = $xpath->query('./div[contains(@class, "col-")]', $rowNode);
        $this->assertSame(count($labels), $directColumns->length, "La row {$rowClass} no contiene la cantidad esperada de columnas");

        $columnTexts = [];
        foreach ($directColumns as $column) {
            $columnTexts[] = trim(preg_replace('/\s+/u', ' ', $column->textContent));
        }

        foreach ($labels as $label) {
            $this->assertTrue(
                collect($columnTexts)->contains(fn (string $text) => str_contains($text, $label)),
                "La row {$rowClass} no contiene la columna {$label}"
            );
        }
    }

    private function ancestorByClass(\DOMXPath $xpath, \DOMNode $node, string $classFragment): ?\DOMNode
    {
        $expression = str_ends_with($classFragment, '-')
            ? sprintf('ancestor::div[contains(@class, %s)][1]', $this->xpathLiteral($classFragment))
            : sprintf(
                'ancestor::div[contains(concat(" ", normalize-space(@class), " "), %s)][1]',
                $this->xpathLiteral(' '.$classFragment.' ')
            );

        return $xpath->query($expression, $node)->item(0) ?: null;
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
