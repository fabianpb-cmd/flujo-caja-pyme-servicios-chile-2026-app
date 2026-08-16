<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\CatalogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('security')]
class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_company_users(): void
    {
        [$company, $admin] = $this->companyWithUser('USR-LIST', 'admin');
        $companyUser = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Usuario Uno',
            'email' => 'usuario-uno@test.local',
            'password' => 'password',
            'role' => 'user',
            'active' => true,
        ]);
        [$otherCompany] = $this->companyWithUser('USR-OTH', 'admin');
        User::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Usuario Ajeno',
            'email' => 'usuario-ajeno@test.local',
            'password' => 'password',
            'role' => 'user',
            'active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertSee('Usuario Uno');
        $response->assertDontSee('Usuario Ajeno');
    }

    public function test_non_admin_user_gets_forbidden_on_user_administration_routes(): void
    {
        [$company] = $this->companyWithUser('USR-FORB', 'admin');
        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Usuario Operador',
            'email' => 'usuario-operador@test.local',
            'password' => 'password',
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.users.store'), [])->assertForbidden();
    }

    public function test_admin_can_create_user_and_company_id_cannot_be_manipulated(): void
    {
        [$company, $admin] = $this->companyWithUser('USR-CREATE', 'admin');
        [$otherCompany] = $this->companyWithUser('USR-CREATE-B', 'admin');

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Nuevo Usuario',
            'email' => 'nuevo-usuario@test.local',
            'role' => 'user',
            'active' => '1',
            'company_id' => $otherCompany->id,
            'password' => 'Segura12345$',
            'password_confirmation' => 'Segura12345$',
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $user = User::query()->where('email', 'nuevo-usuario@test.local')->firstOrFail();
        $this->assertSame($company->id, $user->company_id);
        $this->assertTrue(Hash::check('Segura12345$', $user->password));
        $this->assertNotSame('Segura12345$', $user->password);
    }

    public function test_admin_can_update_user_but_not_other_company_user(): void
    {
        [$company, $admin] = $this->companyWithUser('USR-UPD', 'admin');
        [$otherCompany, $otherAdmin] = $this->companyWithUser('USR-UPD-B', 'admin');

        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Usuario Editable',
            'email' => 'editable@test.local',
            'password' => 'password',
            'role' => 'user',
            'active' => true,
        ]);

        $foreignUser = User::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Usuario Ajeno',
            'email' => 'ajeno@test.local',
            'password' => 'password',
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'name' => 'Usuario Editado',
            'email' => 'editable@test.local',
            'role' => 'admin',
            'active' => '1',
        ])->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Usuario Editado',
            'role' => 'admin',
        ]);

        $this->actingAs($admin)->get(route('admin.users.edit', $foreignUser))->assertForbidden();
        $this->actingAs($otherAdmin)->put(route('admin.users.update', $user), [
            'name' => 'Hack',
            'email' => 'editable@test.local',
            'role' => 'user',
            'active' => '1',
        ])->assertForbidden();
    }

    public function test_admin_can_toggle_active_and_reset_password(): void
    {
        [$company, $admin] = $this->companyWithUser('USR-STATE', 'admin');
        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Usuario Estado',
            'email' => 'estado@test.local',
            'password' => 'password',
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($admin)->patch(route('admin.users.toggle-active', $user))
            ->assertRedirect(route('admin.users.index'));
        $this->assertFalse($user->fresh()->active);

        $this->actingAs($admin)->put(route('admin.users.password.update', $user), [
            'password' => 'NuevaSegura123$',
            'password_confirmation' => 'NuevaSegura123$',
        ])->assertRedirect(route('admin.users.index'));

        $this->assertTrue(Hash::check('NuevaSegura123$', $user->fresh()->password));
    }

    public function test_admin_can_reset_two_factor_configuration(): void
    {
        [$company, $admin] = $this->companyWithUser('USR-2FA', 'admin');
        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Usuario 2FA',
            'email' => 'usuario-2fa@test.local',
            'password' => 'password',
            'role' => 'user',
            'active' => true,
        ]);

        $this->enableConfirmedTwoFactor($user);

        $this->actingAs($admin)->delete(route('admin.users.two-factor.reset', $user))
            ->assertRedirect(route('admin.users.index'));

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_last_active_admin_protection_is_enforced(): void
    {
        [$company, $admin] = $this->companyWithUser('USR-LAST', 'admin');

        $this->actingAs($admin)->patch(route('admin.users.toggle-active', $admin))
            ->assertSessionHasErrors('active');

        $this->actingAs($admin)->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'user',
            'active' => '1',
        ])->assertSessionHasErrors('role');
    }

    public function test_csrf_protection_and_audit_logging_are_maintained(): void
    {
        [$company, $admin] = $this->companyWithUser('USR-CSRF', 'admin');

        $route = app('router')->getRoutes()->getByName('admin.users.store');

        $this->assertNotNull($route);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('POST', $route->methods());

        $this->actingAs($admin)->withSession(['_token' => 'token-users'])->post(route('admin.users.store'), [
            '_token' => 'token-users',
            'name' => 'Con Token',
            'email' => 'con-token@test.local',
            'role' => 'user',
            'active' => '1',
            'password' => 'Segura12345$',
            'password_confirmation' => 'Segura12345$',
        ])->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'action' => 'user.created',
        ]);
    }

    private function enableConfirmedTwoFactor(User $user): void
    {
        app(\Laravel\Fortify\Actions\EnableTwoFactorAuthentication::class)($user, true);

        app(\Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication::class)(
            $user->fresh(),
            $this->currentTotpFor($user->fresh()),
        );
    }

    private function currentTotpFor(User $user): string
    {
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $google2fa = new \PragmaRX\Google2FA\Google2FA();

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
