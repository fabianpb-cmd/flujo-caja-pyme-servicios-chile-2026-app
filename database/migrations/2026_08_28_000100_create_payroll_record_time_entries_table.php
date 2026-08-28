<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_record_time_entries')) {
            return;
        }

        Schema::create('payroll_record_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_record_id')
                ->constrained('payroll_records')
                ->cascadeOnDelete();
            $table->foreignId('time_entry_id')
                ->constrained('time_entries')
                ->restrictOnDelete();
            $table->timestamps();

            $table->index('payroll_record_id', 'prte_payroll_record_idx');
            $table->unique('time_entry_id', 'prte_time_entry_unique');
            $table->unique(['payroll_record_id', 'time_entry_id'], 'prte_payroll_time_entry_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_record_time_entries');
    }
};
