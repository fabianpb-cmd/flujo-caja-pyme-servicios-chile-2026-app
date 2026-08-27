<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('time_entries', 'period_batch_id')) {
                $table->string('period_batch_id', 36)->nullable()->after('assignment_id');
                $table->index(['company_id', 'period_batch_id'], 'time_entries_company_period_batch_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            if (Schema::hasColumn('time_entries', 'period_batch_id')) {
                $table->dropIndex('time_entries_company_period_batch_idx');
                $table->dropColumn('period_batch_id');
            }
        });
    }
};
