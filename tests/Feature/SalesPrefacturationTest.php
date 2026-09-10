<?php

namespace Tests\Feature;

use App\Models\ApprovalStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\ContractType;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\ExchangeRate;
use App\Models\LegalParameter;
use App\Models\Person;
use App\Models\PaymentTerm;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\SalesDocumentTimeEntry;
use App\Models\TimeEntry;
use App\Models\UfValue;
use App\Models\User;
use App\Services\SalesPrefacturationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesPrefacturationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private Client $client;
    private Project $project;
    private Person $person;
    private int $approvedId;
    private int $pendingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['code' => 'CMP-SALES-HH', 'name' => 'Empresa Ventas HH', 'status' => 'active']);
        $this->admin = User::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Admin Ventas',
            'email' => 'ventas-hh@test.local',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);
        $this->client = Client::query()->create(['company_id' => $this->company->id, 'code' => 'CLI-HH', 'legal_name' => 'Cliente HH']);
        DocumentType::query()->create(['company_id' => $this->company->id, 'domain' => 'sales', 'code' => 'FACTURA', 'name' => 'Factura', 'active' => true]);
        $hourlyContract = ContractType::query()->create(['company_id' => $this->company->id, 'domain' => 'commercial', 'code' => 'POR_HORA', 'name' => 'Por hora', 'active' => true]);
        $this->project = Project::query()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'code' => 'PRY-HH', 'name' => 'Proyecto HH', 'contract_type_id' => $hourlyContract->id, 'contracted_hourly_rate' => 35000]);
        $this->person = Person::query()->create(['company_id' => $this->company->id, 'code' => 'PER-HH', 'name' => 'Consultora HH', 'modality' => 'Honorarios mensual', 'status' => 'active']);
        $this->approvedId = ApprovalStatus::query()->create(['company_id' => $this->company->id, 'code' => 'approved', 'name' => 'Aprobado', 'active' => true])->id;
        $this->pendingId = ApprovalStatus::query()->create(['company_id' => $this->company->id, 'code' => 'pending', 'name' => 'Pendiente', 'active' => true])->id;

        LegalParameter::query()->create([
            'company_id' => $this->company->id,
            'parameter_code' => 'IVA',
            'parameter_name' => 'IVA',
            'valid_from' => '2026-01-01',
            'value' => 0.19,
            'unit' => '%',
            'active' => true,
        ]);
        UfValue::query()->create(['company_id' => $this->company->id, 'value_date' => '2026-08-31', 'value' => 40844.79, 'active' => true]);
    }

    public function test_only_approved_billable_hours_are_used(): void
    {
        $assignment = $this->assignment(['hourly_value' => 99999, 'hourly_rate_unit_type' => 'CURRENCY']);
        $this->entry($assignment, 10, $this->approvedId);
        $this->entry($assignment, 8, $this->pendingId);

        $calculation = app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-08-01', '2026-08-31');

        $this->assertSame(10.0, $calculation['hours_total']);
        $this->assertSame(350000.0, $calculation['net_amount']);
        $this->assertSame(66500.0, $calculation['vat_amount']);
        $this->assertSame(416500.0, $calculation['gross_amount']);
    }

    public function test_uf_rate_uses_historical_uf_and_rounds_final_clp(): void
    {
        $this->project->update(['sales_currency_id' => $this->currency('UF', 'Unidad de fomento')->id, 'contracted_hourly_rate' => 1.5]);
        $assignment = $this->assignment(['hourly_value' => 99, 'hourly_rate_unit_type' => 'UF']);
        $this->entry($assignment, 120, $this->approvedId);

        $calculation = app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-08-01', '2026-08-31');

        $this->assertSame(180.0, $calculation['lines'][0]['subtotal_original']);
        $this->assertSame(7352062.0, $calculation['net_amount']);
    }

    public function test_uf_hourly_billing_uses_issue_date_for_every_line(): void
    {
        $uf = $this->currency('UF', 'Unidad de fomento');
        $this->project->update(['sales_currency_id' => $uf->id, 'contracted_hourly_rate' => 1.2]);
        UfValue::query()->create(['company_id' => $this->company->id, 'value_date' => '2026-08-01', 'value' => 30000, 'active' => true]);
        UfValue::query()->create(['company_id' => $this->company->id, 'value_date' => '2026-08-02', 'value' => 35000, 'active' => true]);
        UfValue::query()->where('company_id', $this->company->id)->whereDate('value_date', '2026-08-31')->update(['value' => 40000, 'active' => true]);
        $assignment = $this->assignment(['hourly_value' => 99, 'hourly_rate_unit_type' => 'UF']);
        $this->entry($assignment, 2, $this->approvedId, '2026-08-01');
        $this->entry($assignment, 3, $this->approvedId, '2026-08-02');

        $calculation = app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-08-01', '2026-08-31');

        $this->assertSame(240000.0, $calculation['net_before_adjustment']);
        $this->assertSame(2, collect($calculation['lines'])->where('conversion_date', '2026-08-31')->count());
        $this->assertSame(40000.0, $calculation['lines'][0]['conversion_rate']);
        $this->assertSame(40000.0, $calculation['lines'][1]['conversion_rate']);
    }

    public function test_clp_rate_and_exempt_document_skip_vat(): void
    {
        $assignment = $this->assignment(['hourly_value' => 35000, 'hourly_rate_unit_type' => 'CURRENCY']);
        $this->entry($assignment, 10, $this->approvedId);

        $calculation = app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-08-01', '2026-08-31', false);

        $this->assertSame(350000.0, $calculation['net_amount']);
        $this->assertSame(0.0, $calculation['vat_rate']);
        $this->assertSame(350000.0, $calculation['gross_amount']);
    }

    public function test_foreign_currency_rate_uses_historical_exchange_rate(): void
    {
        $usd = $this->currency('USD', 'Dólar de prueba');
        ExchangeRate::query()->create(['company_id' => $this->company->id, 'currency_id' => $usd->id, 'rate_date' => '2026-08-31', 'value_clp' => 924.78, 'active' => true]);
        $assignment = $this->assignment(['hourly_value' => 45.5, 'hourly_rate_unit_type' => 'CURRENCY', 'hourly_rate_currency_id' => $usd->id]);
        $this->project->update(['sales_currency_id' => $usd->id]);
        $this->project->update(['contracted_hourly_rate' => 45.5]);
        $this->entry($assignment, 10, $this->approvedId);

        $calculation = app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-08-01', '2026-08-31');

        $this->assertSame(420775.0, $calculation['net_amount']);
        $this->assertSame('USD', $calculation['commercial_currency']['code']);
        $this->assertSame(455.0, $calculation['commercial_net_amount']);
        $this->assertSame('USD', $calculation['lines'][0]['currency_code']);
    }

    public function test_missing_uf_blocks_calculation_with_controlled_alert(): void
    {
        UfValue::query()->delete();
        $this->project->update(['sales_currency_id' => $this->currency('UF', 'Unidad de fomento')->id, 'contracted_hourly_rate' => 1.5]);
        $assignment = $this->assignment(['hourly_value' => 99, 'hourly_rate_unit_type' => 'UF']);
        $this->entry($assignment, 10, $this->approvedId);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Falta UF oficial');

        app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-08-01', '2026-08-31');
    }

    public function test_generated_draft_links_hours_and_prevents_duplicate_billing(): void
    {
        $assignment = $this->assignment(['hourly_value' => 35000, 'hourly_rate_unit_type' => 'CURRENCY']);
        $this->entry($assignment, 10, $this->approvedId);

        $document = app(SalesPrefacturationService::class)->generateDraft($this->company->id, [
            'project_id' => $this->project->id,
            'period' => '2026-08-01',
            'issue_date' => '2026-08-31',
            'taxable' => true,
        ]);

        $this->assertSame('Borrador', $document->status);
        $this->assertSame(1, SalesDocumentTimeEntry::query()->count());
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No existen HH aprobadas facturables');
        app(SalesPrefacturationService::class)->generateDraft($this->company->id, [
            'project_id' => $this->project->id,
            'period' => '2026-08-01',
            'issue_date' => '2026-08-31',
            'taxable' => true,
        ]);
    }

    public function test_hourly_billing_draft_can_be_confirmed_without_recalculating_or_reusing_hours(): void
    {
        $uf = $this->currency('UF', 'Unidad de fomento');
        $term = PaymentTerm::query()->create(['company_id' => $this->company->id, 'code' => 'NET30-HH-E2E', 'name' => '30 días', 'days' => 30, 'active' => true]);
        $this->project->update(['sales_currency_id' => $uf->id, 'contracted_hourly_rate' => 1.2, 'payment_term_id' => $term->id]);
        UfValue::query()->where('company_id', $this->company->id)->whereDate('value_date', '2026-08-31')->update(['value' => 40000, 'active' => true]);
        $this->entry($this->assignment(['hourly_value' => 99, 'hourly_rate_unit_type' => 'UF']), 2, $this->approvedId, '2026-08-01');
        $this->entry($this->assignment(['hourly_value' => 88, 'hourly_rate_unit_type' => 'UF']), 3, $this->approvedId, '2026-08-02');
        $this->entry($this->assignment(['hourly_value' => 77, 'hourly_rate_unit_type' => 'UF']), 4, $this->pendingId, '2026-08-03');

        $draft = app(SalesPrefacturationService::class)->generateDraft($this->company->id, [
            'project_id' => $this->project->id,
            'period' => '2026-08-01',
            'issue_date' => '2026-08-31',
            'taxable' => true,
        ]);
        $before = $draft->fresh();
        $snapshot = $before->billing_snapshot;

        $this->assertSame('Borrador', $before->status);
        $this->assertNull($before->document_number);
        $this->assertNotNull($before->document_type_id);
        $this->assertSame('TIME_ENTRIES', $before->billing_source);
        $this->assertSame(5.0, (float) data_get($snapshot, 'hours_total'));
        $this->assertSame(6.0, (float) $before->billing_snapshot['commercial_base_amount']);
        $this->assertSame(240000.0, (float) $before->net_amount);
        $this->assertSame(45600.0, (float) $before->vat_amount);
        $this->assertSame(285600.0, (float) $before->gross_amount);
        $this->assertSame('2026-08-31', $before->issue_date->toDateString());
        $this->assertSame('2026-09-30', $before->due_date->toDateString());
        $this->assertSame('2026-09-30', $before->projected_collection_date->toDateString());
        $this->assertSame(40000.0, (float) data_get($snapshot, 'issue_date_conversion_rate'));
        $this->assertSame(2, SalesDocumentTimeEntry::query()->where('sales_document_id', $before->id)->count());
        $this->assertSame(0, \App\Models\CashMovement::query()->count());

        $confirmed = app(\App\Services\SalesDocumentService::class)->confirm($before, $this->admin, 'QA-HH-001');
        $this->assertSame('Pendiente', $confirmed->status);
        $this->assertSame('QA-HH-001', $confirmed->document_number);
        $this->assertSame(240000.0, (float) $confirmed->net_amount);
        $this->assertSame(45600.0, (float) $confirmed->vat_amount);
        $this->assertSame(285600.0, (float) $confirmed->gross_amount);
        $this->assertSame('2026-08-31', $confirmed->issue_date->toDateString());
        $this->assertSame('2026-09-30', $confirmed->due_date->toDateString());
        $this->assertSame($snapshot, $confirmed->billing_snapshot);
        $this->assertSame(2, SalesDocumentTimeEntry::query()->where('sales_document_id', $confirmed->id)->count());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No existen HH aprobadas facturables');
        app(SalesPrefacturationService::class)->generateDraft($this->company->id, [
            'project_id' => $this->project->id,
            'period' => '2026-08-01',
            'issue_date' => '2026-08-31',
            'taxable' => true,
        ]);
    }

    public function test_rate_snapshot_is_preserved_after_assignment_changes(): void
    {
        $assignment = $this->assignment(['hourly_value' => 35000, 'hourly_rate_unit_type' => 'CURRENCY']);
        $this->entry($assignment, 10, $this->approvedId);

        $document = app(SalesPrefacturationService::class)->generateDraft($this->company->id, [
            'project_id' => $this->project->id,
            'period' => '2026-08-01',
            'issue_date' => '2026-08-31',
            'taxable' => true,
        ]);
        $assignment->update(['hourly_value' => 70000]);

        $line = $document->timeEntryLinks()->firstOrFail();
        $this->assertSame(35000.0, (float) $line->hourly_rate_amount);
        $this->assertSame(350000.0, (float) $line->subtotal_clp);
    }

    public function test_prefacturation_route_ignores_manipulated_totals_and_recalculates_backend(): void
    {
        $assignment = $this->assignment(['hourly_value' => 35000, 'hourly_rate_unit_type' => 'CURRENCY']);
        $this->entry($assignment, 10, $this->approvedId);

        $response = $this->actingAs($this->admin)->post(route('sales-prefacturation.generate-draft'), [
            'project_id' => $this->project->id,
            'period' => '08/2026',
            'issue_date' => '2026-08-31',
            'taxable' => 1,
            'net_amount' => 1,
            'gross_amount' => 2,
        ]);

        $response->assertRedirect();
        $document = \App\Models\SalesDocument::query()->firstOrFail();
        $this->assertSame(350000.0, (float) $document->net_amount);
        $this->assertSame(416500.0, (float) $document->gross_amount);
    }

    public function test_hh_requires_project_commercial_rate_and_rejects_closed_projects(): void
    {
        $this->project->update(['contracted_hourly_rate' => null]);
        $assignment = $this->assignment(['hourly_value' => 99999]);
        $this->entry($assignment, 10, $this->approvedId);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tarifa comercial HH del proyecto');
        app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-08-01', '2026-08-31');
    }

    public function test_closed_project_cannot_use_hh_prefacturation(): void
    {
        $contractType = ContractType::query()->create(['company_id' => $this->company->id, 'domain' => 'commercial', 'code' => 'PROYECTO_CERRADO', 'name' => 'Proyecto cerrado', 'active' => true]);
        $this->project->update(['contract_type_id' => $contractType->id, 'contracted_hourly_rate' => 35000]);
        $assignment = $this->assignment();
        $this->entry($assignment, 10, $this->approvedId);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Plan de facturación / hitos');
        app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-08-01', '2026-08-31');
    }

    private function assignment(array $overrides = []): ProjectAssignment
    {
        return ProjectAssignment::query()->create(array_merge([
            'company_id' => $this->company->id,
            'person_id' => $this->person->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'code' => 'ASI-HH-'.uniqid(),
            'hourly_value' => 35000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ], $overrides));
    }

    private function entry(ProjectAssignment $assignment, float $hours, int $approvalStatusId, string $date = '2026-08-30'): TimeEntry
    {
        return TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HOR-HH-'.uniqid(),
            'person_id' => $this->person->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => $date,
            'activity' => 'Consultoría',
            'hours_worked' => $hours,
            'hours_approved' => $hours,
            'hourly_value' => $assignment->hourly_value,
            'approval_status' => $approvalStatusId === $this->approvedId ? 'approved' : 'pending',
            'approval_status_id' => $approvalStatusId,
            'payment_status' => 'pending',
        ]);
    }

    private function currency(string $code, string $name): Currency
    {
        return Currency::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'code' => $code],
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
}
