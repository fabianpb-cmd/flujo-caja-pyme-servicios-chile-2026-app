<?php

use App\Services\CatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createSimpleCatalog('client_types');
        $this->createSimpleCatalog('project_types');
        $this->createPaymentTerms();
        $this->createCurrencyCatalog();
        $this->createSimpleCatalog('activities');
        $this->createSimpleCatalog('approval_statuses');
        $this->createSimpleCatalog('expense_types');
        $this->createSimpleCatalog('cash_movement_types');
        $this->createSimpleCatalog('bank_account_types');

        $this->addForeignIdIfMissing('clients', 'client_type_id', fn (Blueprint $table) => $table->foreignId('client_type_id')->nullable()->after('tax_id')->constrained('client_types')->nullOnDelete());
        $this->addForeignIdIfMissing('clients', 'payment_term_id', fn (Blueprint $table) => $table->foreignId('payment_term_id')->nullable()->after('payment_term_days')->constrained('payment_terms')->nullOnDelete());
        $this->addForeignIdIfMissing('projects', 'project_type_id', fn (Blueprint $table) => $table->foreignId('project_type_id')->nullable()->after('name')->constrained('project_types')->nullOnDelete());
        $this->addForeignIdIfMissing('projects', 'payment_term_id', fn (Blueprint $table) => $table->foreignId('payment_term_id')->nullable()->after('payment_form')->constrained('payment_terms')->nullOnDelete());
        $this->addForeignIdIfMissing('time_entries', 'activity_id', fn (Blueprint $table) => $table->foreignId('activity_id')->nullable()->after('activity')->constrained('activities')->nullOnDelete());
        $this->addForeignIdIfMissing('time_entries', 'approval_status_id', fn (Blueprint $table) => $table->foreignId('approval_status_id')->nullable()->after('approval_status')->constrained('approval_statuses')->nullOnDelete());
        $this->addForeignIdIfMissing('expense_documents', 'expense_type_id', fn (Blueprint $table) => $table->foreignId('expense_type_id')->nullable()->after('expense_type')->constrained('expense_types')->nullOnDelete());
        $this->addForeignIdIfMissing('cash_accounts', 'bank_account_type_id', fn (Blueprint $table) => $table->foreignId('bank_account_type_id')->nullable()->after('account_type')->constrained('bank_account_types')->nullOnDelete());
        $this->addForeignIdIfMissing('cash_accounts', 'currency_id', fn (Blueprint $table) => $table->foreignId('currency_id')->nullable()->after('currency')->constrained('currencies')->nullOnDelete());
        $this->addForeignIdIfMissing('cash_movements', 'movement_type_id', fn (Blueprint $table) => $table->foreignId('movement_type_id')->nullable()->after('movement_type')->constrained('cash_movement_types')->nullOnDelete());

        $service = new CatalogService();
        DB::table('companies')->pluck('id')->each(fn ($companyId) => $service->backfillCompany((int) $companyId));
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('movement_type_id');
        });

        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_type_id');
            $table->dropConstrainedForeignId('currency_id');
        });

        Schema::table('expense_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_type_id');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activity_id');
            $table->dropConstrainedForeignId('approval_status_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_type_id');
            $table->dropConstrainedForeignId('payment_term_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_type_id');
            $table->dropConstrainedForeignId('payment_term_id');
        });

        Schema::dropIfExists('bank_account_types');
        Schema::dropIfExists('cash_movement_types');
        Schema::dropIfExists('expense_types');
        Schema::dropIfExists('approval_statuses');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('payment_terms');
        Schema::dropIfExists('project_types');
        Schema::dropIfExists('client_types');
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

    private function createPaymentTerms(): void
    {
        if (Schema::hasTable('payment_terms')) {
            return;
        }

        Schema::create('payment_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->unsignedSmallInteger('days')->nullable();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'name']);
        });
    }

    private function createCurrencyCatalog(): void
    {
        if (Schema::hasTable('currencies')) {
            return;
        }

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
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
