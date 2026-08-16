<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\BootstrapCompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BootstrapCompanySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_company_seeder_creates_company_and_admin_from_env(): void
    {
        $this->withBootstrapEnv([
            'BOOTSTRAP_COMPANY_NAME' => 'Empresa Staging',
            'BOOTSTRAP_COMPANY_CODE' => 'STAGING',
            'BOOTSTRAP_ADMIN_NAME' => 'Admin Staging',
            'BOOTSTRAP_ADMIN_EMAIL' => 'admin@staging.example.com',
            'BOOTSTRAP_ADMIN_PASSWORD' => 'Secret123!Secret123!',
        ], function (): void {
            $this->seed(BootstrapCompanySeeder::class);

            $company = Company::query()->where('code', 'STAGING')->firstOrFail();
            $admin = User::query()->where('email', 'admin@staging.example.com')->firstOrFail();

            $this->assertSame($company->id, $admin->company_id);
            $this->assertSame('Admin Staging', $admin->name);
            $this->assertTrue($admin->active);
            $this->assertSame('admin', $admin->role);
        });
    }

    private function withBootstrapEnv(array $values, callable $callback): void
    {
        $previous = [];

        foreach ($values as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            $callback();
        } finally {
            foreach ($values as $key => $_) {
                if ($previous[$key] === false || $previous[$key] === null) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                    continue;
                }

                putenv($key.'='.$previous[$key]);
                $_ENV[$key] = $previous[$key];
                $_SERVER[$key] = $previous[$key];
            }
        }
    }
}
