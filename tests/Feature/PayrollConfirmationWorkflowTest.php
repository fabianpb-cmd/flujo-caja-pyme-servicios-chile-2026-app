<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashMovementType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\LegalParameter;
use App\Models\MonthlyClosure;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\RecordStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\CatalogService;
use App\Services\PayrollBatchService;
use App\Services\PayrollService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollConfirmationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private Project $project;
    private Person $person;
    private CashAccount $cashAccount;
    private int $movementTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['code' => 'CMP-PAY-CONF', 'name' => 'Empresa Confirmacion', 'status' => 'active']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'active' => true]);
        app(CatalogService::class)->seedDefaultsForCompany($this->company->id);

        LegalParameter::query()->create([
            'company_id' => $this->company->id,
            'parameter_code' => 'RETENCION_HONORARIOS',
            'parameter_name' => 'Retencion honorarios',
            'valid_from' => '2026-01-01',
            'value' => 0.1525,
            'unit' => '%',
            'active' => true,
        ]);

        $client = Client::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CLI-PAY-CONF',
            'legal_name' => 'Cliente Confirmacion',
            'client_status_id' => $this->statusId('client', 'active'),
        ]);

        $this->project = Project::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $client->id,
            'code' => 'PRY-PAY-CONF',
            'name' => 'Proyecto Confirmacion',
            'project_status_id' => $this->statusId('project', 'active'),
            'billing_status_id' => $this->statusId('billing', 'pending'),
        ]);

        $this->person = Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'PER-PAY-CONF',
            'name' => 'Persona Confirmacion',
            'modality' => 'Honorarios mensual',
            'monthly_value' => 100000,
            'status' => 'active',
            'worker_status_id' => $this->statusId('worker', 'active'),
            'start_date' => '2026-01-01',
        ]);

        ProjectAssignment::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $client->id,
            'project_id' => $this->project->id,
            'person_id' => $this->person->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'assignment_status_id' => $this->statusId('assignment', 'active'),
        ]);

        $this->cashAccount = CashAccount::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CTA-PAY-CONF',
            'name' => 'Banco Confirmacion',
            'currency_id' => Currency::query()->where('company_id', $this->company->id)->where('code', 'CLP')->value('id'),
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        $this->movementTypeId = CashMovementType::query()->where('company_id', $this->company->id)->valueOrFail('id');
    }

    public function test_batch_generates_ok_payroll_as_draft_and_draft_cannot_be_paid_until_confirmed(): void
    {
        $summary = app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');
        $record = PayrollRecord::query()->where('person_id', $this->person->id)->firstOrFail();

        $this->assertSame(1, $summary['generated']);
        $this->assertSame('OK', $record->calculation_status);
        $this->assertSame('Borrador', $record->status);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Seleccione una remuneracion vigente con saldo pendiente.');
        app(CashMovementService::class)->create($this->cashPayload($record, 100), $this->admin);
    }

    public function test_draft_ok_can_be_confirmed_changes_status_and_audits_action(): void
    {
        $record = $this->payrollRecord(status: 'Borrador', calculationStatus: 'OK', netPay: 84750);

        $confirmed = app(PayrollService::class)->confirm($record, $this->admin);

        $this->assertSame('Confirmado', $confirmed->status);
        $this->assertSame('OK', $confirmed->calculation_status);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'action' => 'payroll_record.confirmed',
            'auditable_type' => PayrollRecord::class,
            'auditable_id' => $record->id,
        ]);
        $this->assertNotNull(AuditLog::query()->where('action', 'payroll_record.confirmed')->first()?->created_at);
    }

    public function test_requires_review_closed_period_other_company_and_inconsistent_hourly_trace_cannot_be_confirmed(): void
    {
        $review = $this->payrollRecord(status: 'Requiere revisión', calculationStatus: 'REQUIERE_REVISION', netPay: 100);
        $this->assertConfirmFails($review, 'Solo una remuneración en Borrador puede confirmarse.');

        $draftReview = $this->payrollRecord(status: 'Borrador', calculationStatus: 'REQUIERE_REVISION', netPay: 100, period: '2026-10-01');
        $this->assertConfirmFails($draftReview, 'Solo una remuneración con cálculo OK puede confirmarse.');

        $closed = $this->payrollRecord(status: 'Borrador', calculationStatus: 'OK', netPay: 100, period: '2026-09-01');
        $this->closePeriod('2026-09-01');
        $this->assertConfirmFails($closed, 'El período 2026-09 esta cerrado.');

        $otherCompany = Company::query()->create(['code' => 'CMP-PAY-CONF-OTHER', 'name' => 'Otra Empresa', 'status' => 'active']);
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id, 'role' => 'admin', 'active' => true]);
        $this->assertConfirmFails($this->payrollRecord(status: 'Borrador', calculationStatus: 'OK', netPay: 100, period: '2026-11-01'), 'La remuneración no pertenece a la empresa del usuario.', $otherUser);

        $hourly = $this->hourlyPayrollWithoutTrace();
        $this->assertConfirmFails($hourly, 'La trazabilidad de horas de la remuneración no coincide con las horas aprobadas del período.');
    }

    public function test_confirmed_payroll_is_selectable_payable_partial_then_paid_and_paid_is_excluded(): void
    {
        $record = app(PayrollService::class)->confirm($this->payrollRecord(status: 'Borrador', calculationStatus: 'OK', netPay: 1000), $this->admin);

        $form = $this->actingAs($this->admin)->get(route('operational.create', 'cash-movements'));
        $form->assertOk();
        $form->assertSee('value="'.$record->code.'"', false);
        $form->assertSee('data-source-document-type="payroll_record"', false);

        app(CashMovementService::class)->create($this->cashPayload($record, 400, 'MOV-PAY-CONF-1'), $this->admin);
        $this->assertSame('Parcial', $record->fresh()->status);
        $this->assertSame(600.0, app(PayrollService::class)->balance($record->fresh()));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El pago excede el saldo pendiente de la remuneracion.');
        app(CashMovementService::class)->create($this->cashPayload($record->fresh(), 601, 'MOV-PAY-CONF-OVER'), $this->admin);
    }

    public function test_second_payment_marks_paid_balance_zero_and_paid_disappears_from_selector(): void
    {
        $record = app(PayrollService::class)->confirm($this->payrollRecord(status: 'Borrador', calculationStatus: 'OK', netPay: 1000), $this->admin);

        app(CashMovementService::class)->create($this->cashPayload($record, 400, 'MOV-PAY-CONF-2A'), $this->admin);
        app(CashMovementService::class)->create($this->cashPayload($record->fresh(), 600, 'MOV-PAY-CONF-2B'), $this->admin);

        $this->assertSame('Pagado', $record->fresh()->status);
        $this->assertSame(0.0, app(PayrollService::class)->balance($record->fresh()));

        $form = $this->actingAs($this->admin)->get(route('operational.create', 'cash-movements'));
        $form->assertOk();
        $form->assertDontSee('value="'.$record->code.'"', false);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Seleccione una remuneracion vigente con saldo pendiente.');
        app(CashMovementService::class)->create($this->cashPayload($record->fresh(), 1, 'MOV-PAY-CONF-PAID'), $this->admin);
    }

    public function test_confirmed_cannot_return_to_draft_through_generic_crud_and_requires_review_stays_excluded_from_payments(): void
    {
        $confirmed = app(PayrollService::class)->confirm($this->payrollRecord(status: 'Borrador', calculationStatus: 'OK', netPay: 1000), $this->admin);
        $review = $this->payrollRecord(status: 'Requiere revisión', calculationStatus: 'REQUIERE_REVISION', netPay: 1000, period: '2026-09-01');

        $response = $this->actingAs($this->admin)->put(route('operational.update', ['payroll-records', $confirmed->id]), [
            'person_id' => $this->person->id,
            'project_id' => $this->project->id,
            'period_date' => '2026-08-01',
            'payment_date' => '',
            'amount_basis' => 'GROSS',
            'monthly_value' => 100000,
            'bonuses' => 0,
            'non_taxable_allowances' => 0,
            'advances' => 0,
            'other_deductions' => 0,
            'status' => 'Borrador',
        ]);

        $response->assertRedirect(route('operational.show', ['payroll-records', $confirmed->id]));
        $this->assertSame('Confirmado', $confirmed->fresh()->status);

        $form = $this->actingAs($this->admin)->get(route('operational.create', 'cash-movements'));
        $form->assertSee('value="'.$confirmed->code.'"', false);
        $form->assertDontSee('value="'.$review->code.'"', false);
    }

    public function test_original_qa_personal_05_flow_is_fixed_by_explicit_confirmation(): void
    {
        app(PayrollBatchService::class)->generate($this->company->id, '2026-08-01');
        $record = PayrollRecord::query()->where('person_id', $this->person->id)->firstOrFail();

        $this->assertSame('Borrador', $record->status);

        try {
            app(CashMovementService::class)->create($this->cashPayload($record, (float) $record->net_pay, 'MOV-PAY-QA-BEFORE'), $this->admin);
            $this->fail('Draft payroll should not be payable before confirmation.');
        } catch (DomainException $exception) {
            $this->assertSame('Seleccione una remuneracion vigente con saldo pendiente.', $exception->getMessage());
        }

        $confirm = $this->actingAs($this->admin)->post(route('operational.confirm', ['payroll-records', $record->id]));
        $confirm->assertRedirect(route('operational.show', ['payroll-records', $record->id]));
        $this->assertSame('Confirmado', $record->fresh()->status);

        app(CashMovementService::class)->create($this->cashPayload($record->fresh(), (float) $record->fresh()->net_pay, 'MOV-PAY-QA-AFTER'), $this->admin);

        $this->assertSame('Pagado', $record->fresh()->status);
        $this->assertSame(1, CashMovement::query()->where('source_document_code', $record->code)->count());
    }

    private function payrollRecord(string $status, string $calculationStatus, float $netPay, string $period = '2026-08-01'): PayrollRecord
    {
        return PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-PAY-CONF-'.uniqid(),
            'person_id' => $this->person->id,
            'project_id' => $this->project->id,
            'period_date' => $period,
            'payment_date' => $period,
            'monthly_value' => 100000,
            'gross_amount' => 100000,
            'net_pay' => $netPay,
            'employer_cost' => 100000,
            'calculation_status' => $calculationStatus,
            'status' => $status,
        ]);
    }

    private function hourlyPayrollWithoutTrace(): PayrollRecord
    {
        $person = Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'PER-PAY-HOUR-'.uniqid(),
            'name' => 'Persona Horaria',
            'modality' => 'Honorarios por hora',
            'hourly_value' => 1000,
            'status' => 'active',
            'worker_status_id' => $this->statusId('worker', 'active'),
            'start_date' => '2026-01-01',
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $this->company->id,
            'client_id' => $this->project->client_id,
            'project_id' => $this->project->id,
            'person_id' => $person->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'assignment_status_id' => $this->statusId('assignment', 'active'),
        ]);

        TimeEntry::query()->create([
            'company_id' => $this->company->id,
            'code' => 'HOR-PAY-CONF-'.uniqid(),
            'person_id' => $person->id,
            'client_id' => $this->project->client_id,
            'project_id' => $this->project->id,
            'assignment_id' => $assignment->id,
            'entry_date' => '2026-08-10',
            'activity' => 'Trabajo',
            'hours_worked' => 1,
            'hours_approved' => 1,
            'hourly_value' => 1000,
            'calculated_amount' => 1000,
            'approval_status' => 'approved',
            'payment_status' => 'pending',
        ]);

        return PayrollRecord::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REM-PAY-HOUR-'.uniqid(),
            'person_id' => $person->id,
            'project_id' => $this->project->id,
            'period_date' => '2026-08-01',
            'payment_date' => '2026-08-31',
            'hours_approved' => 1,
            'hourly_value' => 1000,
            'gross_amount' => 1000,
            'net_pay' => 847.50,
            'employer_cost' => 1000,
            'calculation_status' => 'OK',
            'status' => 'Borrador',
        ]);
    }

    private function cashPayload(PayrollRecord $record, float $amount, string $code = 'MOV-PAY-CONF'): array
    {
        return [
            'company_id' => $this->company->id,
            'code' => $code.'-'.uniqid(),
            'movement_type_id' => $this->movementTypeId,
            'movement_type' => 'Egreso',
            'source_document_type' => 'payroll_record',
            'source_document_code' => $record->code,
            'counterparty_name' => 'Persona Confirmacion',
            'project_id' => $this->project->id,
            'movement_date' => '2026-08-25',
            'income' => 0,
            'expense' => $amount,
            'cash_account_id' => $this->cashAccount->id,
            'status' => 'posted',
        ];
    }

    private function assertConfirmFails(PayrollRecord $record, string $message, ?User $user = null): void
    {
        try {
            app(PayrollService::class)->confirm($record, $user ?? $this->admin);
            $this->fail('Confirmation should have failed.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function statusId(string $domain, string $code): int
    {
        return RecordStatus::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'domain' => $domain, 'code' => $code],
            ['name' => strtoupper($code), 'active' => true],
        )->id;
    }

    private function closePeriod(string $date): void
    {
        MonthlyClosure::query()->create([
            'company_id' => $this->company->id,
            'period_date' => $date,
            'opening_balance' => 0,
            'closing_balance' => 0,
            'cash_in' => 0,
            'cash_out' => 0,
            'accounts_receivable' => 0,
            'accounts_payable' => 0,
            'status' => 'closed',
        ]);
    }
}
