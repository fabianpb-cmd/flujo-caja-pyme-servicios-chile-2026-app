<?php

namespace App\Services;

use App\Models\CompanySetting;

class CompanySettingsService
{
    public function get(int $companyId, string $key, ?string $default = null): ?string
    {
        return CompanySetting::query()
            ->forCompany($companyId)
            ->where('setting_key', $key)
            ->value('setting_value') ?? $default;
    }
}
