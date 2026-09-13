<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->string('original_filename', 255);
            $table->char('file_hash', 64);
            $table->date('statement_from')->nullable();
            $table->date('statement_to')->nullable();
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at');
            $table->string('status', 20)->default('imported');
            $table->unsignedInteger('row_count');
            $table->timestamps();

            $table->unique(['company_id', 'cash_account_id', 'file_hash'], 'bank_statement_imports_file_unique');
            $table->index(['company_id', 'cash_account_id', 'imported_at'], 'bank_statement_imports_account_imported_idx');
        });

        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_statement_import_id')->constrained('bank_statement_imports')->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            $table->string('description', 1000);
            $table->string('reference', 255)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('direction', 10);
            $table->string('external_id', 255)->nullable();
            $table->char('row_hash', 64);
            $table->string('status', 20)->default('unmatched');
            $table->foreignId('ignored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ignored_at')->nullable();
            $table->text('ignore_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'cash_account_id', 'row_hash'], 'bank_statement_lines_row_unique');
            $table->index(['company_id', 'cash_account_id', 'transaction_date'], 'bank_statement_lines_account_date_idx');
            $table->index(['bank_statement_import_id', 'status'], 'bank_statement_lines_import_status_idx');
        });

        Schema::create('bank_statement_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_statement_line_id')->constrained('bank_statement_lines')->restrictOnDelete();
            $table->foreignId('cash_movement_id')->constrained('cash_movements')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->string('match_type', 20)->default('manual');
            $table->unsignedSmallInteger('score')->nullable();
            $table->foreignId('matched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at');
            $table->text('reason')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            // A generated marker preserves the full reversal history while the
            // database enforces V1.2's active 1:1 matching invariant.
            $table->unsignedTinyInteger('active_marker')->nullable()
                ->storedAs("case when status = 'active' then 1 else null end");

            $table->index(['company_id', 'status'], 'bank_statement_matches_company_status_idx');
            $table->index(['cash_movement_id', 'status'], 'bank_statement_matches_movement_status_idx');
            $table->unique(['bank_statement_line_id', 'active_marker'], 'bank_statement_matches_one_active_line_unique');
            $table->unique(['cash_movement_id', 'active_marker'], 'bank_statement_matches_one_active_movement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_matches');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statement_imports');
    }
};
