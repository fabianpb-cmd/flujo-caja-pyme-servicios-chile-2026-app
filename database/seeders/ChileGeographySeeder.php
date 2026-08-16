<?php

namespace Database\Seeders;

use App\Models\Commune;
use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class ChileGeographySeeder extends Seeder
{
    public function run(): void
    {
        $payload = json_decode(
            File::get(database_path('seeders/data/chile_geography.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($payload['regions'] as $region) {
            Region::query()->firstOrCreate(
                ['code' => $region['code']],
                [
                    'name' => $region['name'],
                    'active' => true,
                    'sort_order' => $region['sort_order'] ?? null,
                ]
            );
        }

        foreach ($payload['communes'] as $commune) {
            $regionId = Region::query()->where('code', $commune['region_code'])->value('id');
            if (! $regionId) {
                continue;
            }

            Commune::query()->firstOrCreate(
                ['code' => $commune['code']],
                [
                    'region_id' => $regionId,
                    'name' => $commune['name'],
                    'active' => true,
                    'sort_order' => $commune['sort_order'] ?? null,
                ]
            );
        }
    }
}
