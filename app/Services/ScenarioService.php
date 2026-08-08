<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Scenario;

class ScenarioService
{
    public function activeForCompany(int $companyId, ?string $code = null): Scenario
    {
        $code ??= CompanySetting::query()
            ->forCompany($companyId)
            ->where('setting_key', 'active_scenario')
            ->value('setting_value');

        return Scenario::query()
            ->forCompany($companyId)
            ->when($code, fn ($query) => $query->where('code', strtoupper((string) $code)))
            ->orderByDesc('is_active')
            ->firstOrFail();
    }
}
