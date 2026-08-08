<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasIndex('scenarios', 'scenarios_company_id_code_unique')) {
            return;
        }

        Schema::table('scenarios', function (Blueprint $table) {
            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        if (! $this->hasIndex('scenarios', 'scenarios_company_id_code_unique')) {
            return;
        }

        Schema::table('scenarios', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'code']);
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        }

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            return collect($indexes)->contains(fn ($row) => ($row->name ?? null) === $index);
        }

        return false;
    }
};
