<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BootstrapCompanySeeder extends Seeder
{
    public function run(): void
    {
        $companyName = trim((string) env('BOOTSTRAP_COMPANY_NAME', ''));
        $adminEmail = trim((string) env('BOOTSTRAP_ADMIN_EMAIL', ''));
        $adminPassword = trim((string) env('BOOTSTRAP_ADMIN_PASSWORD', ''));

        if ($companyName === '' || $adminEmail === '' || $adminPassword === '') {
            return;
        }

        $companyCode = trim((string) env('BOOTSTRAP_COMPANY_CODE', ''));
        $generatedCompanyCode = $companyCode !== '' ? $companyCode : (Str::slug($companyName) ?: 'BOOTSTRAP-COMPANY');

        $company = Company::query()->firstOrCreate(
            ['code' => $generatedCompanyCode],
            ['name' => $companyName, 'status' => 'active']
        );

        User::query()->updateOrCreate(
            ['email' => $adminEmail],
            [
                'company_id' => $company->id,
                'name' => trim((string) env('BOOTSTRAP_ADMIN_NAME', 'Administrador inicial')) ?: 'Administrador inicial',
                'password' => Hash::make($adminPassword),
                'role' => 'admin',
                'active' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
