<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_INDEX = 'payroll_records_company_person_period_idx';
    private const UNIQUE_INDEX = 'payroll_records_company_person_period_unique';

    public function up(): void
    {
        if (! Schema::hasTable('payroll_records')) {
            return;
        }

        Schema::table('payroll_records', function (Blueprint $table) {
            if ($this->indexExists(self::LEGACY_INDEX)) {
                $table->dropIndex(self::LEGACY_INDEX);
            }

            if (! $this->indexExists(self::UNIQUE_INDEX)) {
                $table->unique(['company_id', 'person_id', 'period_date'], self::UNIQUE_INDEX);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_records')) {
            return;
        }

        Schema::table('payroll_records', function (Blueprint $table) {
            if ($this->indexExists(self::UNIQUE_INDEX)) {
                $table->dropUnique(self::UNIQUE_INDEX);
            }

            if (! $this->indexExists(self::LEGACY_INDEX)) {
                $table->index(['company_id', 'person_id', 'period_date'], self::LEGACY_INDEX);
            }
        });
    }

    private function indexExists(string $index): bool
    {
        return collect(Schema::getIndexes('payroll_records'))
            ->contains(fn (array $definition): bool => ($definition['name'] ?? null) === $index);
    }
};
