<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movement_bank_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_movement_id')->constrained('cash_movements')->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->string('classification', 20);
            $table->string('status', 20)->default('active');
            $table->text('reason');
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            // MySQL and SQLite both support a stored generated column. NULL permits
            // historical reversed rows, while the unique index permits only one active row.
            $table->unsignedTinyInteger('active_marker')->nullable()
                ->storedAs("case when status = 'active' then 1 else null end");

            $table->index(['company_id', 'status'], 'cash_movement_bank_assignments_company_status_idx');
            $table->index(['cash_account_id', 'status'], 'cash_movement_bank_assignments_account_status_idx');
            $table->index(['cash_movement_id', 'status'], 'cash_movement_bank_assignments_movement_status_idx');
            $table->index(['cash_movement_id', 'assigned_at'], 'cash_movement_bank_assignments_movement_history_idx');
            $table->unique(['cash_movement_id', 'active_marker'], 'cash_movement_bank_assignments_one_active_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movement_bank_assignments');
    }
};
