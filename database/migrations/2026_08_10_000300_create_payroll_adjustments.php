<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_adjustments')) {
            Schema::create('payroll_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
                $table->date('period_date');
                $table->string('type', 40);
                $table->decimal('amount', 18, 2)->nullable();
                $table->decimal('quantity', 18, 4)->nullable();
                $table->text('description')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index(['company_id', 'person_id', 'period_date', 'active'], 'payroll_adj_person_period_idx');
            });
        }

        if (Schema::hasTable('payroll_records') && ! $this->indexExists('payroll_records', 'payroll_records_company_person_period_idx')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->index(['company_id', 'person_id', 'period_date'], 'payroll_records_company_person_period_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustments');
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $definition): bool => ($definition['name'] ?? null) === $index);
    }
};
