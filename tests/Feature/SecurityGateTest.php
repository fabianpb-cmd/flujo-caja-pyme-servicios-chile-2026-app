<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ApprovalStatus;
use App\Models\CashAccount;
use App\Models\CashMovementType;
use App\Models\Client;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\PaymentMethod;
use App\Models\PayrollRecord;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\RecordStatus;
use App\Models\User;
use App\Services\CatalogService;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

#[Group('security')]
class SecurityGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_routes_require_authentication(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('operational.index', 'clients'))->assertRedirect(route('login'));
        $this->post(route('operational.store', 'clients'), [])->assertRedirect(route('login'));
    }

    public function test_cross_company_idor_is_blocked_for_sensitive_resources(): void
    {
        $this->withoutExceptionHandling();

        [$companyA, $adminA] = $this->companyWithUser('A', 'admin');
        [$companyB] = $this->companyWithUser('B', 'admin');

        $client = Client::query()->create([
            'company_id' => $companyB->id,
            'legal_name' => 'Cliente B',
            'client_status_id' => $this->statusId($companyB->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $companyB->id,
            'client_id' => $client->id,
            'name' => 'Proyecto B',
            'project_status_id' => $this->statusId($companyB->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($companyB->id, 'billing', 'pending'),
        ]);

        $person = Person::query()->create([
            'company_id' => $companyB->id,
            'first_names' => 'Persona',
            'paternal_surname' => 'B',
            'modality' => 'Dependiente mensual',
            'employment_mode_id' => $this->employmentModeId($companyB->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($companyB->id, 'worker', 'active'),
        ]);

        $payroll = PayrollRecord::query()->create([
            'company_id' => $companyB->id,
            'person_id' => $person->id,
            'period_date' => '2026-08-01',
            'status' => 'Pendiente',
        ]);

        $requests = [
            fn () => $this->actingAs($adminA)->get(route('operational.show', ['resource' => 'clients', 'record' => $client->id])),
            fn () => $this->actingAs($adminA)->get(route('operational.edit', ['resource' => 'projects', 'record' => $project->id])),
            fn () => $this->actingAs($adminA)->put(route('operational.update', ['resource' => 'people', 'record' => $person->id]), [
                'first_names' => 'Hack',
                'paternal_surname' => 'Hack',
                'rut' => '12345678-5',
                'employment_mode_id' => $this->employmentModeId($companyA->id, 'DEPENDIENTE_MENSUAL'),
                'worker_status_id' => $this->statusId($companyA->id, 'worker', 'active'),
            ]),
            fn () => $this->actingAs($adminA)->get(route('operational.show', ['resource' => 'payroll-records', 'record' => $payroll->id])),
        ];

        foreach ($requests as $request) {
            try {
                $request();
                $this->fail('Cross-company request should be rejected.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
                $this->addToAssertionCount(1);
            } catch (\Throwable $exception) {
                $this->fail($exception::class.' '.$exception->getMessage());
            }
        }
    }

    public function test_company_id_is_derived_from_authenticated_user(): void
    {
        [$companyA, $adminA] = $this->companyWithUser('CIDA', 'admin');
        [$companyB] = $this->companyWithUser('CIDB', 'admin');

        $response = $this->actingAs($adminA)->post(route('operational.store', 'clients'), [
            'company_id' => $companyB->id,
            'legal_name' => 'Cliente Seguro',
            'client_status_id' => $this->statusId($companyA->id, 'client', 'active'),
        ]);

        $response->assertRedirect(route('operational.index', 'clients'));
        $this->assertDatabaseHas('clients', [
            'legal_name' => 'Cliente Seguro',
            'company_id' => $companyA->id,
        ]);
        $this->assertDatabaseMissing('clients', [
            'legal_name' => 'Cliente Seguro',
            'company_id' => $companyB->id,
        ]);
    }

    public function test_project_company_id_is_derived_from_authenticated_user(): void
    {
        $this->bindHttpRouteRequest();

        $this->expectException(MassAssignmentException::class);

        (new Project())->fill([
            'company_id' => 999,
            'client_id' => 1,
            'name' => 'Proyecto Seguro',
        ]);
    }

    public function test_person_company_id_is_derived_from_authenticated_user(): void
    {
        $this->withoutExceptionHandling();

        [$companyA, $adminA] = $this->companyWithUser('PERSA', 'admin');
        [$companyB] = $this->companyWithUser('PERSB', 'admin');

        $response = $this->actingAs($adminA)->post(route('operational.store', 'people'), [
            'company_id' => $companyB->id,
            'first_names' => 'Persona',
            'paternal_surname' => 'Segura',
            'rut' => '12.345.678-5',
            'employment_mode_id' => $this->employmentModeId($companyA->id, 'DEPENDIENTE_MENSUAL'),
            'worker_status_id' => $this->statusId($companyA->id, 'worker', 'active'),
        ]);

        $response->assertRedirect(route('operational.index', 'people'));
        $this->assertDatabaseHas('people', [
            'first_names' => 'Persona',
            'company_id' => $companyA->id,
        ]);
        $this->assertDatabaseMissing('people', [
            'first_names' => 'Persona',
            'company_id' => $companyB->id,
        ]);
    }

    public function test_immutable_code_cannot_be_overridden_via_request(): void
    {
        [$company, $admin] = $this->companyWithUser('CODE', 'admin');

        $client = Client::query()->create([
            'company_id' => $company->id,
            'legal_name' => 'Cliente Código',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $originalCode = $client->code;

        $response = $this->actingAs($admin)->put(route('operational.update', ['resource' => 'clients', 'record' => $client->id]), [
            'code' => 'MALICIOUS',
            'legal_name' => 'Cliente Código Editado',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $response->assertRedirect(route('operational.show', ['resource' => 'clients', 'record' => $client->id]));
        $this->assertSame($originalCode, $client->refresh()->code);
    }

    public function test_project_code_cannot_be_overridden_via_request(): void
    {
        $this->bindHttpRouteRequest();

        $this->expectException(MassAssignmentException::class);

        (new Project())->fill([
            'client_id' => 1,
            'code' => 'MALICIOUS',
            'name' => 'Proyecto Código Editado',
        ]);
    }

    public function test_calculated_time_entry_amounts_ignore_manipulated_request_values(): void
    {
        [$company, $admin] = $this->companyWithUser('TIME', 'admin');

        $client = Client::query()->create([
            'company_id' => $company->id,
            'legal_name' => 'Cliente Tiempo',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $project = Project::query()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'name' => 'Proyecto Tiempo',
            'contracted_hourly_rate' => 35000,
            'project_status_id' => $this->statusId($company->id, 'project', 'active'),
            'billing_status_id' => $this->statusId($company->id, 'billing', 'pending'),
        ]);

        $person = Person::query()->create([
            'company_id' => $company->id,
            'first_names' => 'Juana',
            'paternal_surname' => 'Tiempo',
            'modality' => 'Pago por hora',
            'employment_mode_id' => $this->employmentModeId($company->id, 'PAGO_POR_HORA'),
            'worker_status_id' => $this->statusId($company->id, 'worker', 'active'),
        ]);

        $assignment = ProjectAssignment::query()->create([
            'company_id' => $company->id,
            'person_id' => $person->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'hourly_value' => 40000,
            'hourly_rate_unit_type' => 'CURRENCY',
            'assignment_status_id' => $this->statusId($company->id, 'assignment', 'active'),
            'start_date' => '2026-08-01',
        ]);

        $activity = Activity::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ACT-SEC'],
            ['name' => 'Actividad Seguridad', 'active' => true, 'sort_order' => 1]
        );
        $approvalStatus = ApprovalStatus::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'approved'],
            ['name' => 'Aprobado', 'active' => true, 'sort_order' => 1]
        );

        $response = $this->actingAs($admin)->post(route('operational.store', 'time-entries'), [
            'person_id' => $person->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'entry_date' => '10/08/2026',
            'activity_id' => $activity->id,
            'hours_worked' => 2,
            'hours_approved' => 2,
            'hourly_value' => 1,
            'assignment_id' => $assignment->id,
            'approval_status_id' => $approvalStatus->id,
            'payment_status' => 'pending',
        ]);

        $response->assertRedirect(route('operational.index', 'time-entries'));
        $entry = \App\Models\TimeEntry::query()->latest('id')->firstOrFail();
        $this->assertSame(40000.0, (float) $entry->hourly_value);
        $this->assertSame(80000.0, (float) $entry->calculated_amount);
    }

    public function test_sales_document_calculated_fields_are_not_mass_assignable_during_http_requests(): void
    {
        $this->bindHttpRouteRequest();
        $this->expectException(MassAssignmentException::class);

        (new \App\Models\SalesDocument())->fill([
            'client_id' => 1,
            'document_type_id' => 1,
            'issue_date' => '2026-08-10',
            'net_amount' => 1000000,
            'company_id' => 999,
            'vat_amount' => 1,
            'gross_amount' => 2,
            'status' => 'Pagado',
        ]);
    }

    public function test_payroll_calculated_fields_are_not_mass_assignable_during_http_requests(): void
    {
        $this->bindHttpRouteRequest();
        $this->expectException(MassAssignmentException::class);

        (new PayrollRecord())->fill([
            'person_id' => 1,
            'period_date' => '2026-08-01',
            'monthly_value' => 1000000,
            'company_id' => 999,
            'employee_retention' => 1,
            'net_pay' => 2,
            'employer_cost' => 3,
            'status' => 'Pagado',
        ]);
    }

    public function test_cash_movement_company_id_is_derived_from_authenticated_user(): void
    {
        [$companyA, $adminA] = $this->companyWithUser('MOVA', 'admin');
        [$companyB] = $this->companyWithUser('MOVB', 'admin');

        $movementTypeId = CashMovementType::query()->where('company_id', $companyA->id)->valueOrFail('id');
        $paymentMethodId = PaymentMethod::query()->where('company_id', $companyA->id)->valueOrFail('id');
        $cashAccount = CashAccount::query()->create([
            'company_id' => $companyA->id,
            'name' => 'Caja Principal',
            'currency_id' => \App\Models\Currency::query()->where('company_id', $companyA->id)->valueOrFail('id'),
            'opening_balance' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($adminA)->post(route('operational.store', 'cash-movements'), [
            'company_id' => $companyB->id,
            'movement_type_id' => $movementTypeId,
            'counterparty_name' => 'Pago seguro',
            'movement_date' => '10/08/2026',
            'income' => 1000,
            'payment_method_id' => $paymentMethodId,
            'cash_account_id' => $cashAccount->id,
            'status' => 'posted',
        ]);

        $response->assertRedirect(route('operational.index', 'cash-movements'));
        $this->assertDatabaseHas('cash_movements', [
            'counterparty_name' => 'Pago seguro',
            'company_id' => $companyA->id,
        ]);
        $this->assertDatabaseMissing('cash_movements', [
            'counterparty_name' => 'Pago seguro',
            'company_id' => $companyB->id,
        ]);
    }

    public function test_admin_routes_are_forbidden_for_operational_users(): void
    {
        [$company] = $this->companyWithUser('ADMIN', 'admin');
        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Operador Seguridad',
            'email' => 'operador-seguridad@test.local',
            'password' => 'password',
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($user)->get(route('operational.index', 'currencies'))->assertForbidden();
        $this->actingAs($user)->get(route('operational.index', 'legal-parameters'))->assertForbidden();
    }

    public function test_login_is_rate_limited_and_inactive_users_cannot_authenticate(): void
    {
        [$company] = $this->companyWithUser('AUTH', 'admin');

        User::query()->create([
            'company_id' => $company->id,
            'name' => 'Inactivo',
            'email' => 'inactive@test.local',
            'password' => 'password',
            'role' => 'user',
            'active' => false,
        ]);

        $inactive = $this->post(route('login.attempt'), [
            'email' => 'inactive@test.local',
            'password' => 'password',
        ]);
        $inactive->assertSessionHasErrors('email');
        $this->assertGuest();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.attempt'), [
                'email' => 'admin-auth@test.local',
                'password' => 'incorrecta',
            ])->assertSessionHasErrors('email');
        }

        $locked = $this->post(route('login.attempt'), [
            'email' => 'admin-auth@test.local',
            'password' => 'incorrecta',
        ]);

        $locked->assertStatus(429);
        $locked->assertSee('Demasiados intentos de acceso', false);
    }

    public function test_login_initializes_absolute_session_timestamp_and_ignores_remember_me(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        config()->set('session.absolute_lifetime', 480);

        [$company, $user] = $this->companyWithUser('SESS', 'user');

        $response = $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('auth_session_started_at');
        $this->assertAuthenticatedAs($user);

        $rememberCookies = collect($response->baseResponse->headers->getCookies())
            ->filter(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));
        $this->assertCount(0, $rememberCookies);
    }

    public function test_absolute_session_timeout_logs_user_out_and_redirects_with_message(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        config()->set('session.absolute_lifetime', 480);

        [$company, $user] = $this->companyWithUser('ABSO', 'admin');
        $startedAt = Carbon::now()->subMinutes(481)->timestamp;

        $response = $this->actingAs($user)->withSession([
            'auth_session_started_at' => $startedAt,
        ])->get(route('operational.index', 'clients'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('session_expired', 'Tu sesión expiró por seguridad. Ingresa nuevamente.');
        $this->assertGuest();
    }

    public function test_absolute_session_timestamp_is_not_renewed_by_activity(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        config()->set('session.absolute_lifetime', 480);

        [$company, $user] = $this->companyWithUser('ACTV', 'admin');
        $startedAt = Carbon::now()->subMinutes(10)->timestamp;

        $response = $this->actingAs($user)->withSession([
            'auth_session_started_at' => $startedAt,
        ])->get(route('operational.index', 'clients'));

        $response->assertOk();
        $response->assertSessionHas('auth_session_started_at', $startedAt);
    }

    public function test_expired_session_cookie_redirects_to_login_with_message(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');

        [$company] = $this->companyWithUser('COOK', 'admin');

        $response = $this->withCookie(config('session.cookie'), 'stale-session-id')
            ->get(route('operational.index', 'clients'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('session_expired', 'Tu sesión expiró por seguridad. Ingresa nuevamente.');
    }

    public function test_keep_alive_endpoint_requires_authentication_and_csrf(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        config()->set('session.absolute_lifetime', 480);

        [$company, $user] = $this->companyWithUser('KEEP', 'admin');

        $this->post(route('session.keep-alive'))->assertRedirect(route('login'));

        $response = $this->actingAs($user)->withSession([
            '_token' => 'keepalive-token',
            'auth_session_started_at' => Carbon::now()->timestamp,
        ])->post(route('session.keep-alive'), [
            '_token' => 'keepalive-token',
        ]);

        $response->assertNoContent();
    }

    public function test_logout_invalidates_session(): void
    {
        [$company, $user] = $this->companyWithUser('LOGO', 'admin');

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_page_does_not_expose_remember_me_option(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertDontSee('Recordarme', false);
        $response->assertDontSee('name="remember"', false);
    }

    public function test_text_fields_are_escaped_in_blade_output(): void
    {
        [$company, $admin] = $this->companyWithUser('XSS', 'admin');

        $client = Client::query()->create([
            'company_id' => $company->id,
            'legal_name' => '<script>alert(1)</script>',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $response = $this->actingAs($admin)->get(route('operational.show', ['resource' => 'clients', 'record' => $client->id]));
        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_invalid_sort_is_ignored_safely(): void
    {
        [$company, $admin] = $this->companyWithUser('SORT', 'admin');

        Client::query()->create([
            'company_id' => $company->id,
            'legal_name' => 'Cliente Sort',
            'client_status_id' => $this->statusId($company->id, 'client', 'active'),
        ]);

        $response = $this->actingAs($admin)->get(route('operational.index', [
            'resource' => 'clients',
            'sort' => 'legal_name desc, sleep(1)',
            'direction' => 'desc',
        ]));

        $response->assertOk();
        $response->assertSee('Cliente Sort', false);
    }

    public function test_passwords_are_never_stored_in_plaintext(): void
    {
        $company = Company::query()->create([
            'code' => 'CMP-PASS',
            'name' => 'Empresa Password',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Hash Test',
            'email' => 'hash@test.local',
            'password' => 'secreto123',
            'role' => 'admin',
            'active' => true,
        ]);

        $this->assertNotSame('secreto123', $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('secreto123', $user->getRawOriginal('password')));
    }

    public function test_security_headers_are_present_on_authenticated_pages(): void
    {
        [, $admin] = $this->companyWithUser('HEAD', 'admin');

        $response = $this->actingAs($admin)->get(route('operational.index', 'clients'));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->assertHeader('Content-Security-Policy');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    public function test_sensitive_user_attributes_are_not_mass_assignable_during_http_requests(): void
    {
        $this->bindHttpRouteRequest();

        $this->expectException(MassAssignmentException::class);

        (new User())->fill([
            'name' => 'Usuario',
            'email' => 'user@test.local',
            'password' => 'password',
            'company_id' => 999,
            'role' => 'admin',
            'active' => false,
        ]);
    }

    public function test_user_role_and_admin_flags_are_not_mass_assignable_during_http_requests(): void
    {
        $this->bindHttpRouteRequest();

        $this->expectException(MassAssignmentException::class);

        (new User())->fill([
            'name' => 'Usuario',
            'email' => 'user-role@test.local',
            'password' => 'password',
            'company_id' => 999,
            'role' => 'admin',
            'is_admin' => true,
            'active' => true,
        ]);
    }

    public function test_admin_without_two_factor_is_redirected_to_security_after_login_and_cannot_open_dashboard(): void
    {
        $this->enableAdminTwoFactorEnforcement();

        [$company, $admin] = $this->companyWithUser('2FA-ADMIN', 'admin');

        $response = $this->post(route('login.attempt'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('account.security'));
        $this->assertAuthenticatedAs($admin);

        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('account.security'));
    }

    public function test_admin_without_two_factor_can_access_enrollment_routes_without_redirect_loop(): void
    {
        $this->enableAdminTwoFactorEnforcement();

        [$company, $admin] = $this->companyWithUser('2FA-ENROLL', 'admin');

        $this->actingAs($admin)->get(route('account.security'))->assertOk();
        $this->actingAs($admin)->get(route('password.confirm'))->assertOk();
    }

    public function test_admin_with_confirmed_two_factor_is_redirected_to_challenge_after_password_login(): void
    {
        $this->enableAdminTwoFactorEnforcement();

        [$company, $admin] = $this->companyWithUser('2FA-CHAL', 'admin');
        $this->enableConfirmedTwoFactor($admin);

        $response = $this->post(route('login.attempt'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_admin_can_complete_two_factor_challenge_with_valid_totp_code(): void
    {
        $this->enableAdminTwoFactorEnforcement();

        [$company, $admin] = $this->companyWithUser('2FA-TOTP', 'admin');
        $this->enableConfirmedTwoFactor($admin);

        $this->post(route('login.attempt'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $response = $this->post(route('two-factor.login.store'), [
            'code' => $this->currentTotpFor($admin->fresh(), 1),
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_invalid_two_factor_code_does_not_authenticate_admin(): void
    {
        $this->enableAdminTwoFactorEnforcement();

        [$company, $admin] = $this->companyWithUser('2FA-BAD', 'admin');
        $this->enableConfirmedTwoFactor($admin);

        $this->post(route('login.attempt'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $response = $this->from(route('two-factor.login'))->post(route('two-factor.login.store'), [
            'code' => '000000',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_recovery_code_allows_access_once_and_cannot_be_reused(): void
    {
        $this->enableAdminTwoFactorEnforcement();

        [$company, $admin] = $this->companyWithUser('2FA-REC', 'admin');
        $this->enableConfirmedTwoFactor($admin);
        $recoveryCode = $admin->fresh()->recoveryCodes()[0];

        $this->post(route('login.attempt'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.login.store'), [
            'recovery_code' => $recoveryCode,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin->fresh());
        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->post(route('login.attempt'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $response = $this->from(route('two-factor.login'))->post(route('two-factor.login.store'), [
            'recovery_code' => $recoveryCode,
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_non_admin_user_login_flow_remains_unchanged_without_two_factor(): void
    {
        $this->enableAdminTwoFactorEnforcement();

        [$company, $user] = $this->companyWithUser('2FA-USER', 'user');

        $response = $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_disabling_two_factor_is_blocked_again_from_operational_routes(): void
    {
        $this->enableAdminTwoFactorEnforcement();

        [$company, $admin] = $this->companyWithUser('2FA-DIS', 'admin');
        $this->enableConfirmedTwoFactor($admin);

        $response = $this->actingAs($admin)->withSession([
            'auth.password_confirmed_at' => now()->timestamp,
        ])->delete(route('account.security.disable-2fa'));

        $response->assertRedirect(route('account.security'));
        $this->actingAs($admin->fresh())->get(route('dashboard'))->assertRedirect(route('account.security'));
    }

    public function test_two_factor_secret_fields_remain_hidden_from_user_payloads(): void
    {
        $this->enableAdminTwoFactorEnforcement();

        [$company, $admin] = $this->companyWithUser('2FA-HIDE', 'admin');
        $this->enableConfirmedTwoFactor($admin);

        $payload = $admin->fresh()->toArray();

        $this->assertArrayNotHasKey('two_factor_secret', $payload);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $payload);
    }

    private function bindHttpRouteRequest(): void
    {
        $request = request()->duplicate();
        $request->setRouteResolver(function () {
            $route = new Route(['POST'], '/security/fill', fn () => null);
            $route->name('security.fill');

            return $route;
        });
        app()->instance('request', $request);
        app()->instance('mass_assignment.untrusted_request', true);
    }

    private function enableAdminTwoFactorEnforcement(): void
    {
        config()->set('fortify.enforce_admin_two_factor_in_tests', true);
    }

    private function enableConfirmedTwoFactor(User $user): void
    {
        app(\Laravel\Fortify\Actions\EnableTwoFactorAuthentication::class)($user, true);

        app(\Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication::class)(
            $user->fresh(),
            $this->currentTotpFor($user->fresh()),
        );
    }

    private function currentTotpFor(User $user, int $counterOffset = 0): string
    {
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $google2fa = new \PragmaRX\Google2FA\Google2FA();

        if ($counterOffset !== 0) {
            return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $counterOffset);
        }

        return $google2fa->getCurrentOtp($secret);
    }

    private function companyWithUser(string $suffix, string $role): array
    {
        $company = Company::query()->create([
            'code' => 'CMP-'.$suffix,
            'name' => 'Empresa '.$suffix,
            'status' => 'active',
        ]);

        app(CatalogService::class)->seedDefaultsForCompany($company->id);

        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Usuario '.$suffix,
            'email' => 'admin-'.strtolower($suffix).'@test.local',
            'password' => 'password',
            'role' => $role,
            'active' => true,
        ]);

        return [$company, $user];
    }

    private function statusId(int $companyId, string $domain, string $code): int
    {
        return RecordStatus::query()
            ->where('company_id', $companyId)
            ->where('domain', $domain)
            ->where('code', $code)
            ->valueOrFail('id');
    }

    private function employmentModeId(int $companyId, string $code): int
    {
        return \App\Models\EmploymentMode::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->valueOrFail('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
