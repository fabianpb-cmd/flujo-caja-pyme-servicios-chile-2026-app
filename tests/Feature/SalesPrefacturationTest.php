<?php

namespace Tests\Feature;

use App\Models\ApprovalStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\LegalParameter;
use App\Models\Person;
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
        $this->project = Project::query()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id, 'code' => 'PRY-HH', 'name' => 'Proyecto HH']);
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
        UfValue::query()->create(['company_id' => $this->company->id, 'value_date' => '2026-08-09', 'value' => 40844.79, 'active' => true]);
    }

    public function test_only_approved_billable_hours_are_used(): void
    {
        $assignment = $this->assignment(['hourly_value' => 35000, 'hourly_rate_unit_type' => 'CURRENCY']);
        $this->entry($assignment, 10, $this->approvedId);
        $this->entry($assignment, 8, $this->pendingId);

        $calculation = app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-08-01', '2026-08-09');

        $this->assertSame(10.0, $calculation['hours_total']);
        $this->assertSame(350000.0, $calculation['net_amount']);
        $this->assertSame(66500.0, $calculation['vat_amount']);
        $this->assertSame(416500.0, $calculation['gross_amount']);
    }

    public function test_uf_rate_uses_historical_uf_and_rounds_final_clp(): void
    {
        $assignment = $this->assignment(['hourly_value' => 1.5, 'hourly_rate_unit_type' => 'UF']);
        $this->entry($assignment, 120, $this->approvedId);

        $calculation = app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-08-01', '2026-08-09');

        $this->assertSame(180.0, $calculation['lines'][0]['subtotal_original']);
        $this->assertSame(7352062.0, $calculation['net_amount']);
    }

    public function test_clp_rate_and_exempt_document_skip_vat(): void
    {
        $assignment = $this->assignment(['hourly_value' => 35000, 'hourly_rate_unit_type' => 'CURRENCY']);
        $this->entry($assignment, 10, $this->approvedId);

        $calculation = app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-08-01', '2026-08-09', false);

        $this->assertSame(350000.0, $calculation['net_amount']);
        $this->assertSame(0.0, $calculation['vat_rate']);
        $this->assertSame(350000.0, $calculation['gross_amount']);
    }

    public function test_foreign_currency_rate_uses_historical_exchange_rate(): void
    {
        $usd = $this->currency('USD', 'Dólar de prueba');
        ExchangeRate::query()->create(['company_id' => $this->company->id, 'currency_id' => $usd->id, 'rate_date' => '2026-08-09', 'value_clp' => 924.78, 'active' => true]);
        $assignment = $this->assignment(['hourly_value' => 45.5, 'hourly_rate_unit_type' => 'CURRENCY', 'hourly_rate_currency_id' => $usd->id]);
        $this->project->update(['sales_currency_id' => $usd->id]);
        $this->entry($assignment, 10, $this->approvedId);

        $calculation = app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-08-01', '2026-08-09');

        $this->assertSame(420775.0, $calculation['net_amount']);
        $this->assertSame('USD', $calculation['commercial_currency']['code']);
        $this->assertSame(455.0, $calculation['commercial_net_amount']);
        $this->assertSame('USD', $calculation['lines'][0]['currency_code']);
    }

    public function test_missing_uf_blocks_calculation_with_controlled_alert(): void
    {
        UfValue::query()->delete();
        $assignment = $this->assignment(['hourly_value' => 1.5, 'hourly_rate_unit_type' => 'UF']);
        $this->entry($assignment, 10, $this->approvedId);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Falta UF oficial');

        app(SalesPrefacturationService::class)->calculate($this->company->id, $this->project->id, '2026-08-01', '2026-08-09');
    }

    public function test_generated_draft_links_hours_and_prevents_duplicate_billing(): void
    {
        $assignment = $this->assignment(['hourly_value' => 35000, 'hourly_rate_unit_type' => 'CURRENCY']);
        $this->entry($assignment, 10, $this->approvedId);

        $document = app(SalesPrefacturationService::class)->generateDraft($this->company->id, [
            'project_id' => $this->project->id,
            'period' => '2026-08-01',
            'issue_date' => '2026-08-09',
            'taxable' => true,
        ]);

        $this->assertSame('Borrador', $document->status);
        $this->assertSame(1, SalesDocumentTimeEntry::query()->count());
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No existen HH aprobadas facturables');
        app(SalesPrefacturationService::class)->generateDraft($this->company->id, [
            'project_id' => $this->project->id,
            'period' => '2026-08-01',
            'issue_date' => '2026-08-09',
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
            'issue_date' => '2026-08-09',
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
            'issue_date' => '2026-08-09',
            'taxable' => 1,
            'net_amount' => 1,
            'gross_amount' => 2,
        ]);

        $response->assertRedirect();
        $document = \App\Models\SalesDocument::query()->firstOrFail();
        $this->assertSame(350000.0, (float) $document->net_amount);
        $this->assertSame(416500.0, (float) $document->gross_amount);
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

    private function entry(ProjectAssignment $assignment, float $hours, int $approvalStatusId): TimeEntry
    {
        return TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HOR-HH-'.uniqid(),
            'person_id' => $this->person->id,
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-09',
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
