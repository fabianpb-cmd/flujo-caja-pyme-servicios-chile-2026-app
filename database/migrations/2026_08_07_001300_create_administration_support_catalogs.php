<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createSimpleCatalog('health_systems');
        $this->createSimpleCatalog('tax_regimes');
        $this->createSimpleCatalog('legal_organizations');
        $this->createSimpleCatalog('occupational_insurance_entities');

        $this->addForeignIdIfMissing('people', 'health_system_id', fn (Blueprint $table) => $table->foreignId('health_system_id')->nullable()->after('health_system')->constrained('health_systems')->nullOnDelete());
        $this->addForeignIdIfMissing('legal_obligations', 'organization_id', fn (Blueprint $table) => $table->foreignId('organization_id')->nullable()->after('obligation_type_id')->constrained('legal_organizations')->nullOnDelete());
    }

    public function down(): void
    {
        if (Schema::hasColumn('legal_obligations', 'organization_id')) {
            Schema::table('legal_obligations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('organization_id');
            });
        }

        if (Schema::hasColumn('people', 'health_system_id')) {
            Schema::table('people', function (Blueprint $table) {
                $table->dropConstrainedForeignId('health_system_id');
            });
        }

        Schema::dropIfExists('occupational_insurance_entities');
        Schema::dropIfExists('legal_organizations');
        Schema::dropIfExists('tax_regimes');
        Schema::dropIfExists('health_systems');
    }

    private function createSimpleCatalog(string $tableName): void
    {
        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'name']);
        });
    }

    private function addForeignIdIfMissing(string $tableName, string $column, callable $callback): void
    {
        if (Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, $callback);
    }
};
