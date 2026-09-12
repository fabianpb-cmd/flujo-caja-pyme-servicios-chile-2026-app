<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_accounts', function (Blueprint $table): void {
            $table->date('opening_balance_date')->nullable()->after('opening_balance');
        });

        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->cascadeOnDelete();
            $table->date('reconciliation_date');
            $table->decimal('bank_balance', 18, 2);
            $table->decimal('system_balance_snapshot', 18, 2);
            $table->decimal('difference', 18, 2);
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reconciled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'cash_account_id', 'reconciliation_date'], 'bank_reconciliations_company_account_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
        Schema::table('cash_accounts', function (Blueprint $table): void {
            $table->dropColumn('opening_balance_date');
        });
    }
};
