<?php

namespace Database\Seeders;

use App\Models\CashAccount;
use App\Models\Company;
use App\Models\ProjectManager;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    private ?string $demoAdminPassword = null;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $company = Company::query()->firstOrCreate(
            ['code' => 'CMP-001'],
            ['name' => 'Empresa Demo', 'tax_id' => null, 'status' => 'active']
        );

        foreach ([
            ['code' => 'RESP_JAIME', 'name' => 'Jaime'],
            ['code' => 'RESP_EMILIO', 'name' => 'Emilio'],
        ] as $row) {
            ProjectManager::query()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $row['name']],
                ['code' => $row['code'], 'active' => true]
            );
        }

        foreach ([
            ['code' => 'IRIS_CONSULTOR_SENIOR', 'name' => 'IRIS Consultor Senior'],
            ['code' => 'IRIS_CONSULTOR', 'name' => 'IRIS Consultor'],
            ['code' => 'IRIS_CONSULTOR_JUNIOR', 'name' => 'IRIS Consultor Junior'],
            ['code' => 'BI_CONSULTOR_SENIOR', 'name' => 'BI Consultor Senior'],
            ['code' => 'BI_CONSULTOR', 'name' => 'BI Consultor'],
            ['code' => 'BI_CONSULTOR_JUNIOR', 'name' => 'BI Consultor Junior'],
        ] as $row) {
            Position::query()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $row['name']],
                ['code' => $row['code'], 'active' => true]
            );
        }

        CashAccount::query()->firstOrCreate(
            ['code' => 'BANK-001'],
            [
                'company_id' => $company->id,
                'name' => 'Cuenta Banco Local',
                'institution' => 'Banco local',
                'account_type' => 'Corriente',
                'currency' => 'CLP',
                'opening_balance' => 0,
                'is_active' => true,
            ]
        );

        User::query()->firstOrCreate(
            ['email' => 'admin@flujo.local'],
            [
                'company_id' => $company->id,
                'name' => 'Administrador local',
                'password' => Hash::make($this->demoAdminPassword()),
                'role' => 'admin',
                'active' => true,
                'email_verified_at' => now(),
            ]
        );

        if (! Storage::disk('local')->exists('uat_credentials.json')) {
            Storage::disk('local')->put(
                'uat_credentials.json',
                json_encode([
                    'email' => 'admin@flujo.local',
                    'password' => $this->demoAdminPassword(),
                    'generated_at' => now()->toIso8601String(),
                    'source' => 'DemoDataSeeder',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }
    }

    private function demoAdminPassword(): string
    {
        if ($this->demoAdminPassword !== null) {
            return $this->demoAdminPassword;
        }

        $configured = trim((string) env('UAT_ADMIN_PASSWORD', ''));

        return $this->demoAdminPassword = $configured !== ''
            ? $configured
            : $this->fallbackPassword();
    }

    private function fallbackPassword(): string
    {
        $key = 'UAT_ADMIN_PASSWORD_FALLBACK';
        $stored = Storage::disk('local')->exists($key.'.txt')
            ? trim((string) Storage::disk('local')->get($key.'.txt'))
            : '';

        if ($stored !== '') {
            return $stored;
        }

        $password = Str::password(20);
        Storage::disk('local')->put($key.'.txt', $password);

        return $password;
    }
}
