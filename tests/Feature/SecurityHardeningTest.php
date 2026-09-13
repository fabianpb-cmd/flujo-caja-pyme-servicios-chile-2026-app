<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\CatalogService;
use App\Support\Security\AuthenticationRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('security')]
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_password_policy_accepts_passphrases_and_rejects_invalid_lengths_and_common_passwords(): void
    {
        [, $admin] = $this->companyWithUser('PASSWORD', 'admin');

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Passphrase User',
            'email' => 'passphrase@test.local',
            'role' => 'user',
            'active' => '1',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertRedirect(route('admin.users.index'));

        foreach (['corta', str_repeat('a', 129), 'passwordpassword'] as $password) {
            $this->actingAs($admin)->from(route('admin.users.create'))->post(route('admin.users.store'), [
                'name' => 'Invalid User',
                'email' => 'invalid-'.md5($password).'@test.local',
                'role' => 'user',
                'active' => '1',
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertRedirect(route('admin.users.create'))->assertSessionHasErrors('password');
        }
    }

    public function test_login_rate_limits_email_ip_account_and_ip_without_revealing_account_existence(): void
    {
        $limiter = app(AuthenticationRateLimiter::class);
        $request = Request::create('/login', 'POST', ['email' => 'unknown@test.local']);
        $request->server->set('REMOTE_ADDR', '203.0.113.10');

        foreach (range(1, 5) as $_) {
            $limiter->hitLogin($request);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post(route('login.attempt'), [
            'email' => 'unknown@test.local',
            'password' => 'wrong password',
        ]);

        $response->assertStatus(429);
        $response->assertSee('Demasiados intentos de acceso.');

        $accountRequest = Request::create('/login', 'POST', ['email' => 'account-limit@test.local']);
        $accountRequest->server->set('REMOTE_ADDR', '203.0.113.11');
        foreach (range(1, 20) as $_) {
            $limiter->hitLogin($accountRequest);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])->post(route('login.attempt'), [
            'email' => 'account-limit@test.local',
            'password' => 'wrong password',
        ])->assertStatus(429);

        foreach (range(1, 60) as $_) {
            $limiter->hitLogin(Request::create('/login', 'POST', ['email' => 'different-'.$_.'@test.local'], server: ['REMOTE_ADDR' => '203.0.113.12']));
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])->post(route('login.attempt'), [
            'email' => 'another@test.local',
            'password' => 'wrong password',
        ])->assertStatus(429);
    }

    public function test_successful_login_clears_failures_and_regenerates_the_authenticated_session(): void
    {
        [, $user] = $this->companyWithUser('LOGIN', 'user');
        $limiter = app(AuthenticationRateLimiter::class);
        $request = Request::create('/login', 'POST', ['email' => $user->email]);
        $request->server->set('REMOTE_ADDR', '203.0.113.20');
        foreach (range(1, 4) as $_) {
            $limiter->hitLogin($request);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(session('auth_session_started_at'));
    }

    public function test_two_factor_rate_limiter_tracks_the_challenged_user_and_clears_after_success(): void
    {
        [, $user] = $this->companyWithUser('TWOFACTOR', 'user');
        $this->enableConfirmedTwoFactor($user);

        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        foreach (range(1, 4) as $_) {
            $this->from(route('two-factor.login'))->post(route('two-factor.login.store'), ['code' => '000000'])
                ->assertRedirect(route('two-factor.login'));
        }

        $this->post(route('two-factor.login.store'), ['code' => $this->currentTwoFactorCode($user->fresh(), 1)])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user->fresh());
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        foreach (range(1, 5) as $_) {
            $this->from(route('two-factor.login'))->post(route('two-factor.login.store'), ['code' => '000000'])
                ->assertRedirect(route('two-factor.login'));
        }

        $this->from(route('two-factor.login'))->post(route('two-factor.login.store'), ['code' => '000000'])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHasErrors('code');
    }

    public function test_two_factor_secrets_are_not_rendered_before_password_confirmation(): void
    {
        [, $user] = $this->companyWithUser('SECRET', 'user');
        $this->enableConfirmedTwoFactor($user);
        $freshUser = $user->fresh();
        $unconfirmed = $this->actingAs($freshUser)->withSession(['auth.password_confirmed_at' => 0])
            ->get(route('account.security'));
        $unconfirmed->assertOk();
        $unconfirmed->assertViewHas('manualSecret', null);
        $unconfirmed->assertViewHas('qrSvg', null);
        $unconfirmed->assertViewHas('recoveryCodes', []);

        $confirmed = $this->actingAs($freshUser)->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get(route('account.security'));
        $confirmed->assertOk();
        $confirmed->assertViewHas('manualSecret', fn (?string $secret): bool => filled($secret));
        $confirmed->assertViewHas('qrSvg', fn (?string $svg): bool => filled($svg));
        $confirmed->assertViewHas('recoveryCodes', fn (array $codes): bool => count($codes) > 0);
    }

    public function test_sensitive_responses_and_local_assets_have_browser_security_protections(): void
    {
        [, $user] = $this->companyWithUser('HEADERS', 'user');

        $login = $this->get(route('login'));
        $login->assertOk();
        $this->assertSame(
            ['max-age=0', 'must-revalidate', 'no-cache', 'no-store', 'private'],
            array_values(array_filter(array_map(trim(...), explode(',', (string) $login->headers->get('Cache-Control'))))),
        );
        $login->assertHeader('Pragma', 'no-cache');
        $login->assertHeader('Expires', '0');
        $login->assertHeader('X-Frame-Options', 'DENY');
        $login->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $login->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
        $login->assertSee('vendor/bootstrap/bootstrap.min.css', false);
        $login->assertSee('vendor/bootstrap-icons/bootstrap-icons.min.css', false);
        $login->assertDontSee('cdn.jsdelivr.net', false);

        $this->actingAs($user)->get(route('account.security'))->assertHeader('Pragma', 'no-cache');
        $this->assertFileExists(public_path('vendor/bootstrap/bootstrap.bundle.min.js'));
        $this->assertFileExists(public_path('vendor/bootstrap-icons/fonts/bootstrap-icons.woff2'));
        $this->assertFileExists(public_path('vendor/chartjs/chart.umd.min.js'));
    }

    private function enableConfirmedTwoFactor(User $user): void
    {
        app(\Laravel\Fortify\Actions\EnableTwoFactorAuthentication::class)($user, true);
        $freshUser = $user->fresh();
        app(\Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication::class)($freshUser, $this->currentTwoFactorCode($freshUser));
    }

    private function currentTwoFactorCode(User $user, int $counterOffset = 0): string
    {
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        $google2fa = new \PragmaRX\Google2FA\Google2FA();

        return $counterOffset === 0
            ? $google2fa->getCurrentOtp($secret)
            : $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $counterOffset);
    }

    private function companyWithUser(string $suffix, string $role): array
    {
        $company = Company::query()->create([
            'code' => 'SEC-'.$suffix,
            'name' => 'Security '.$suffix,
            'status' => 'active',
        ]);
        app(CatalogService::class)->seedDefaultsForCompany($company->id);

        $user = User::query()->create([
            'company_id' => $company->id,
            'name' => 'Security '.$suffix,
            'email' => strtolower($suffix).'@security.test',
            'password' => 'password',
            'role' => $role,
            'active' => true,
        ]);

        return [$company, $user];
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('unused-security-hardening-key');
        parent::tearDown();
    }
}
