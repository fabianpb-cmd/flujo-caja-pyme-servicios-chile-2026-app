<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\EmploymentMode;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\LegalParameter;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Person;
use App\Models\Position;
use App\Models\Project;
use App\Models\ProjectManager;
use App\Models\RecordStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogMaintainersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_edit_and_toggle_catalogs(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $form = $this->actingAs($admin)->get(route('operational.create', 'project-managers'));
        $form->assertOk();
        $form->assertDontSee('name="code"', false);
        $form->assertSee('Se generará automáticamente', false);

        $create = $this->actingAs($admin)->post(route('operational.store', 'project-managers'), [
            'name' => 'Responsable QA',
            'description' => 'Catálogo inicial',
            'active' => 1,
            'sort_order' => 10,
        ]);

        $create->assertRedirect(route('operational.index', 'project-managers'));
        $manager = ProjectManager::query()->where('company_id', $company->id)->where('name', 'Responsable QA')->firstOrFail();
        $this->assertMatchesRegularExpression('/^RES-\\d{6,}$/', $manager->code);

        $update = $this->actingAs($admin)->put(route('operational.update', ['project-managers', $manager->id]), [
            'code' => 'RESP-HACK',
            'name' => 'Responsable QA Editado',
            'description' => 'Actualizado',
            'active' => 1,
            'sort_order' => 20,
        ]);

        $update->assertRedirect(route('operational.show', ['project-managers', $manager->id]));
        $this->assertSame($manager->code, $manager->refresh()->code);
        $this->assertDatabaseHas('project_managers', [
            'id' => $manager->id,
            'name' => 'Responsable QA Editado',
            'sort_order' => 20,
        ]);

        $toggle = $this->actingAs($admin)->patch(route('operational.toggle-active', ['project-managers', $manager->id]));
        $toggle->assertRedirect(route('operational.index', 'project-managers'));
        $this->assertDatabaseHas('project_managers', ['id' => $manager->id, 'active' => 0]);
    }

    public function test_admin_can_create_and_update_second_wave_catalogs(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $form = $this->actingAs($admin)->get(route('operational.create', 'payment-terms'));
        $form->assertOk();
        $form->assertDontSee('name="code"', false);
        $form->assertSee('Se generará automáticamente', false);

        $create = $this->actingAs($admin)->post(route('operational.store', 'payment-terms'), [
            'name' => '90 días',
            'days' => 90,
            'payment_method_id' => $this->paymentMethodId($company->id, 'TRANSFERENCIA'),
            'active' => 1,
            'sort_order' => 90,
        ]);

        $create->assertRedirect(route('operational.index', 'payment-terms'));
        $term = PaymentTerm::query()->where('company_id', $company->id)->where('name', '90 días')->firstOrFail();
        $this->assertMatchesRegularExpression('/^PZO-\\d{6,}$/', $term->code);

        $update = $this->actingAs($admin)->put(route('operational.update', ['payment-terms', $term->id]), [
            'code' => 'PZO-HACK',
            'name' => '90 días corridos',
            'days' => 90,
            'payment_method_id' => $this->paymentMethodId($company->id, 'TRANSFERENCIA'),
            'active' => 1,
            'sort_order' => 95,
        ]);

        $update->assertRedirect(route('operational.show', ['payment-terms', $term->id]));
        $this->assertSame($term->code, $term->refresh()->code);
        $this->assertDatabaseHas('payment_terms', ['id' => $term->id, 'name' => '90 días corridos', 'days' => 90]);
    }

    public function test_catalogs_in_use_cannot_be_deleted_physically(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $position = Position::query()->create([
            'company_id' => $company->id,
            'code' => 'DEV',
            'name' => 'Desarrollador',
            'active' => true,
        ]);

        Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-CAT',
            'name' => 'Persona Catálogo',
            'role' => 'Desarrollador',
            'modality' => 'Dependiente mensual',
            'status' => 'active',
            'position_id' => $position->id,
            'employment_mode_id' => $this->employmentModeId($company->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $response = $this->actingAs($admin)->delete(route('operational.destroy', ['positions', $position->id]));

        $response->assertRedirect(route('operational.show', ['positions', $position->id]));
        $this->assertDatabaseHas('positions', ['id' => $position->id]);
    }

    public function test_catalog_backfill_maps_legacy_text_to_foreign_keys(): void
    {
        $company = Company::query()->create(['code' => 'CMP-MAP', 'name' => 'Empresa Map', 'status' => 'active']);

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-MAP',
            'legal_name' => 'Cliente Map',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-MAP',
            'name' => 'Proyecto Map',
            'manager' => 'Ana Responsable',
            'contract_type' => 'Por hora',
            'project_status' => 'active',
            'billing_status' => 'pending',
        ]);

        Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-MAP',
            'name' => 'Persona Map',
            'role' => 'Consultor',
            'modality' => 'Pago por hora',
            'contract_type' => 'Honorarios',
            'status' => 'active',
        ]);

        \App\Models\ExpenseDocument::query()->create([
            'company_id' => $company->id,
            'code' => 'EGR-MAP',
            'category' => 'Operación',
            'subcategory' => 'Servicios cloud',
            'document_type' => 'Factura',
            'issue_date' => '2026-08-01',
            'net_amount' => 1000,
            'gross_amount' => 1000,
            'payment_status' => 'Pendiente',
        ]);

        \App\Models\LegalObligation::query()->create([
            'company_id' => $company->id,
            'code' => 'OBL-MAP',
            'obligation_type' => 'RETENCIÓN_HONORARIOS / F29',
            'period_date' => '2026-08-01',
            'estimated_amount' => 1000,
            'pending_amount' => 1000,
            'status' => 'Pendiente',
        ]);

        $report = app(CatalogService::class)->backfillCompany($company->id);

        $project = Project::query()->where('code', 'PRY-MAP')->firstOrFail();
        $person = Person::query()->where('code', 'PER-MAP')->firstOrFail();
        $expense = \App\Models\ExpenseDocument::query()->where('code', 'EGR-MAP')->firstOrFail();
        $obligation = \App\Models\LegalObligation::query()->where('code', 'OBL-MAP')->firstOrFail();

        $this->assertNotNull($project->manager_id);
        $this->assertNotNull($project->contract_type_id);
        $this->assertNotNull($project->project_status_id);
        $this->assertNotNull($person->position_id);
        $this->assertNotNull($person->employment_mode_id);
        $this->assertNotNull($expense->expense_category_id);
        $this->assertNotNull($expense->expense_subcategory_id);
        $this->assertNotNull($expense->document_type_id);
        $this->assertNotNull($obligation->obligation_type_id);
        $this->assertGreaterThan(0, $report['mapped']['obligation_types']);
    }

    public function test_second_wave_backfill_maps_operational_and_commercial_legacy_fields(): void
    {
        $company = Company::query()->create(['code' => 'CMP-W2', 'name' => 'Empresa W2', 'status' => 'active']);
        app(CatalogService::class)->seedDefaultsForCompany($company->id);

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-W2',
            'legal_name' => 'Cliente W2',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'code' => 'PRY-W2',
            'name' => 'Proyecto W2',
            'payment_form' => 'Transferencia',
            'project_status' => 'active',
            'billing_status' => 'pending',
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-W2',
            'name' => 'Persona W2',
            'modality' => 'Pago por hora',
            'status' => 'active',
        ]);

        $timeEntry = TimeEntry::query()->create([
            'company_id' => $company->id,
            'code' => 'HOR-W2',
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'entry_date' => '2026-08-01',
            'activity' => 'Implementación',
            'hours_worked' => 1,
            'hours_approved' => 1,
            'hourly_value' => 1000,
            'calculated_amount' => 1000,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        $expense = \App\Models\ExpenseDocument::query()->create([
            'company_id' => $company->id,
            'code' => 'EGR-W2',
            'issue_date' => '2026-08-01',
            'net_amount' => 1000,
            'gross_amount' => 1000,
            'expense_type' => 'Operacional',
            'payment_status' => 'Pendiente',
        ]);

        $account = CashAccount::query()->create([
            'company_id' => $company->id,
            'code' => 'CTA-W2',
            'name' => 'Cuenta W2',
            'account_type' => 'Corriente',
            'currency' => 'CLP',
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        $movement = \App\Models\CashMovement::query()->create([
            'company_id' => $company->id,
            'code' => 'MOV-W2',
            'movement_type' => 'Ingreso',
            'movement_date' => '2026-08-02',
            'income' => 1000,
            'expense' => 0,
            'status' => 'posted',
        ]);

        app(CatalogService::class)->backfillCompany($company->id);

        $this->assertNotNull($client->refresh()->payment_term_id);
        $this->assertNotNull($project->refresh()->payment_term_id);
        $this->assertNotNull($timeEntry->refresh()->activity_id);
        $this->assertNotNull($timeEntry->refresh()->approval_status_id);
        $this->assertNotNull($expense->refresh()->expense_type_id);
        $this->assertNotNull($account->refresh()->bank_account_type_id);
        $this->assertNotNull($account->refresh()->currency_id);
        $this->assertNotNull($movement->refresh()->movement_type_id);
    }

    public function test_expense_subcategory_validation_and_filter_markup_are_present(): void
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
        $form->assertSee('data-parent-field="expense_category_id"', false);
        $form->assertSee('data-parent-id="'.$categoryA->id.'"', false);

        $invalid = $this->actingAs($admin)->post(route('operational.store', 'expense-documents'), [
            'code' => 'EGR-VAL',
            'expense_category_id' => $categoryB->id,
            'expense_subcategory_id' => $subcategory->id,
            'document_type_id' => $this->documentTypeId($company->id, 'expense', 'FACTURA_COMPRA'),
            'issue_date' => '2026-08-01',
            'net_amount' => 1000,
        ]);

        $invalid->assertSessionHasErrors('expense_subcategory_id');
    }

    public function test_create_form_hides_inactive_options_but_edit_keeps_historical_inactive_value_visible(): void
    {
        [$company, $admin] = $this->companyWithAdmin();

        $inactiveType = ClientType::query()->create([
            'company_id' => $company->id,
            'code' => 'LEGACY',
            'name' => 'Legacy inactivo',
            'active' => false,
        ]);

        $client = Client::query()->create([
            'company_id' => $company->id,
            'code' => 'CLI-INACT',
            'legal_name' => 'Cliente Inactivo',
            'payment_term_days' => 30,
            'status' => 'active',
            'client_type_id' => $inactiveType->id,
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $create = $this->actingAs($admin)->get(route('operational.create', 'clients'));
        $create->assertOk();
        $create->assertDontSee('Legacy inactivo');

        $edit = $this->actingAs($admin)->get(route('operational.edit', ['clients', $client->id]));
        $edit->assertOk();
        $edit->assertSee('Legacy inactivo');
    }

    public function test_payroll_service_prefers_employment_mode_fk_over_legacy_text(): void
    {
        [$company] = $this->companyWithAdmin();

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'PER-FK',
            'name' => 'Persona FK',
            'modality' => 'Dependiente mensual',
            'status' => 'active',
            'monthly_value' => 900000,
            'hourly_value' => 25000,
            'employment_mode_id' => $this->employmentModeId($company->id, 'PAGO_POR_HORA'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $result = app(PayrollService::class)->calculate($person->load('employmentMode'), '2026-08-01', [
            'hours_approved' => 10,
            'hourly_value' => 25000,
        ]);

        $this->assertSame(250000.0, $result['base_salary']);
        $this->assertSame(0.0, $result['vacation_provision']);
    }

    public function test_non_admin_cannot_access_catalog_maintainers(): void
    {
        [$company] = $this->companyWithAdmin();

        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Operador',
            'email' => 'operador@test.local',
            'password' => 'password',
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($user)->get(route('operational.index', 'positions'))->assertForbidden();
        $this->actingAs($user)->get(route('operational.index', 'payment-terms'))->assertForbidden();
    }

    public function test_admin_smoke_get_all_catalog_maintainers(): void
    {
        [, $admin] = $this->companyWithAdmin();

        foreach ([
            'project-managers',
            'cost-centers',
            'positions',
            'employment-modes',
            'contract-types',
            'afps',
            'health-systems',
            'banks',
            'payment-methods',
            'expense-categories',
            'expense-subcategories',
            'document-types',
            'obligation-types',
            'record-statuses',
            'client-types',
            'project-types',
            'payment-terms',
            'currencies',
            'tax-regimes',
            'activities',
            'approval-statuses',
            'expense-types',
            'cash-movement-types',
            'bank-account-types',
            'legal-organizations',
            'occupational-insurance-entities',
        ] as $resource) {
            $this->actingAs($admin)
                ->get(route('operational.index', $resource))
                ->assertOk();
        }
    }

    public function test_sidebar_sales_and_payables_links_are_distinct_and_route_to_real_pages(): void
    {
        [, $admin] = $this->companyWithAdmin();

        $facturasUrl = route('sales-documents.index');
        $cxcUrl = route('receivables.index');
        $gastosUrl = route('expense-documents.index');
        $cxpUrl = route('payables.index');

        $this->assertNotSame($facturasUrl, $cxcUrl);
        $this->assertNotSame($gastosUrl, $cxpUrl);

        $this->actingAs($admin)->get($facturasUrl)->assertOk()->assertSee('Facturas/Ingresos', false);
        $this->actingAs($admin)->get($cxcUrl)->assertOk()->assertSee('Cuentas por cobrar', false);
        $this->actingAs($admin)->get($gastosUrl)->assertOk()->assertSee('Gastos/Egresos', false);
        $this->actingAs($admin)->get($cxpUrl)->assertOk()->assertSee('Cuentas por pagar', false);

        $sidebarHtml = $this->actingAs($admin)->get($facturasUrl);
        $sidebarHtml->assertOk();
        $sidebarHtml->assertSee('href="'.$facturasUrl.'"', false);
        $sidebarHtml->assertSee('href="'.$cxcUrl.'"', false);
        $sidebarHtml->assertSee('href="'.$gastosUrl.'"', false);
        $sidebarHtml->assertSee('href="'.$cxpUrl.'"', false);
    }

    public function test_sidebar_marks_only_one_leaf_item_as_active(): void
    {
        [, $admin] = $this->companyWithAdmin();

        $cases = [
            route('operational.index', 'clients') => route('operational.index', 'clients'),
            route('operational.index', 'projects') => route('operational.index', 'projects'),
            route('operational.index', 'people') => route('operational.index', 'people'),
            route('operational.index', 'time-entries') => route('operational.index', 'time-entries'),
            route('sales-documents.index') => route('sales-documents.index'),
            route('receivables.index') => route('receivables.index'),
            route('expense-documents.index') => route('expense-documents.index'),
            route('payables.index') => route('payables.index'),
            route('operational.index', 'cash-accounts') => route('operational.index', 'cash-accounts'),
            route('operational.index', 'cash-movements') => route('operational.index', 'cash-movements'),
            route('management.obligations') => route('management.obligations'),
        ];

        foreach ($cases as $pageUrl => $activeHref) {
            $response = $this->actingAs($admin)->get($pageUrl);
            $response->assertOk();
            $content = $response->getContent();

            preg_match(
                '/<aside class="app-sidebar d-none d-md-flex">(.*?)<\\/aside>/s',
                $content,
                $desktopSidebar
            );

            $this->assertNotEmpty($desktopSidebar, 'Desktop sidebar not found for '.$pageUrl);

            preg_match_all(
                '/class="[^"]*\\bis-active\\b[^"]*"[^>]*href="[^"]*"/',
                $desktopSidebar[1],
                $activeMatches
            );

            preg_match_all(
                '/class="[^"]*\\bis-active\\b[^"]*"[^>]*href="'.preg_quote($activeHref, '/').'"/',
                $desktopSidebar[1],
                $currentMatches
            );

            $this->assertSame(1, count($activeMatches[0]), 'Expected exactly one active item for '.$pageUrl);
            $this->assertSame(1, count($currentMatches[0]), 'Expected active href mismatch for '.$pageUrl);
        }
    }

    private function companyWithAdmin(): array
    {
        $company = Company::query()->create(['code' => 'CMP-CAT', 'name' => 'Empresa Catálogo', 'status' => 'active']);
        app(CatalogService::class)->seedDefaultsForCompany($company->id);

        foreach ([
            ['IVA', 0.19],
            ['RETENCION_HONORARIOS', 0.1525],
            ['PROVISION_VACACIONES', 0.0833],
            ['PPM_RATE', 0.01],
            ['COTIZACION_EMPLEADOR', 0.01],
            ['SIS_RATE', 0.0154],
            ['AFC_RATE', 0.006],
            ['IMPUESTO_SEGUNDA_CATEGORIA_RATE', 0.0],
        ] as [$code, $value]) {
            LegalParameter::query()->updateOrCreate([
                'company_id' => $company->id,
                'parameter_code' => $code,
                'valid_from' => '2026-01-01',
            ], [
                'parameter_name' => $code,
                'valid_to' => '2027-12-31',
                'value' => $value,
                'unit' => '%',
            ]);
        }

        $admin = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Admin Catálogo',
            'email' => uniqid('catalog').'@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);

        return [$company, $admin];
    }

    private function employmentModeId(int $companyId, string $code): int
    {
        return EmploymentMode::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->valueOrFail('id');
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

    private function paymentMethodId(int $companyId, string $code): int
    {
        return PaymentMethod::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->valueOrFail('id');
    }
}
