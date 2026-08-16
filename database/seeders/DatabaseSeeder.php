<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SystemCatalogSeeder::class);
        $this->call(IncomeTaxBracketSeeder::class);
        $this->call(ChileGeographySeeder::class);
        $this->call(BootstrapCompanySeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoDataSeeder::class);
        }

        $this->call(OperationalCatalogSeeder::class);
    }
}
