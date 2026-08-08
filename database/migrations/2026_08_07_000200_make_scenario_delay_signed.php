<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE scenarios MODIFY collection_delay_days SMALLINT NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE scenarios MODIFY collection_delay_days SMALLINT UNSIGNED NOT NULL DEFAULT 0');
        }
    }
};
