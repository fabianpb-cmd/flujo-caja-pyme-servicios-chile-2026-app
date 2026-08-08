<?php

namespace Database\Seeders;

use App\Models\Afp;
use App\Models\AfpRate;
use Illuminate\Database\Seeder;

class SystemCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $afps = [
            'CAPITAL' => ['name' => 'Capital', 'commission' => 0.0144],
            'CUPRUM' => ['name' => 'Cuprum', 'commission' => 0.0144],
            'HABITAT' => ['name' => 'Habitat', 'commission' => 0.0127],
            'MODELO' => ['name' => 'Modelo', 'commission' => 0.0058],
            'PLANVITAL' => ['name' => 'PlanVital', 'commission' => 0.0116],
            'PROVIDA' => ['name' => 'Provida', 'commission' => 0.0145],
            'UNO' => ['name' => 'Uno', 'commission' => 0.0046],
        ];

        foreach ($afps as $code => $data) {
            $afp = Afp::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $data['name'], 'is_active' => true]
            );

            $exists = AfpRate::query()
                ->where('afp_id', $afp->id)
                ->whereDate('valid_from', '2026-01-01')
                ->exists();

            if (! $exists) {
                AfpRate::query()->create([
                    'afp_id' => $afp->id,
                    'valid_from' => '2026-01-01',
                    'valid_to' => null,
                    'employee_commission_rate' => $data['commission'],
                    'employer_commission_rate' => 0,
                    'insurance_rate' => 0,
                    'source' => '03_Listas + 02_Parametros_Legales',
                ]);
            }
        }
    }
}
