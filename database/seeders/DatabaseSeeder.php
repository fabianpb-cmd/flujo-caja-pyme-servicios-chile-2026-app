<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SystemCatalogSeeder::class);
        $this->call(DemoDataSeeder::class);
        $this->call(OperationalCatalogSeeder::class);
    }
}
