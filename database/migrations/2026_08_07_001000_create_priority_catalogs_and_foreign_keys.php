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
        $this->createSimpleCatalog('project_managers');
        $this->createSimpleCatalog('positions');
        $this->createSimpleCatalog('employment_modes');
        $this->createDomainCatalog('contract_types');
        $this->createSimpleCatalog('cost_centers');
        $this->createSimpleCatalog('banks');
        $this->createSimpleCatalog('payment_methods');
        $this->createSimpleCatalog('expense_categories');
        $this->createExpenseSubcategories();
        $this->createDomainCatalog('document_types');
        $this->createSimpleCatalog('obligation_types');
        $this->createDomainCatalog('record_statuses');

        $this->addForeignIdIfMissing('clients', 'client_status_id', fn (Blueprint $table) => $table->foreignId('client_status_id')->nullable()->after('status')->constrained('record_statuses')->nullOnDelete());
        $this->addForeignIdIfMissing('projects', 'manager_id', fn (Blueprint $table) => $table->foreignId('manager_id')->nullable()->after('manager')->constrained('project_managers')->nullOnDelete());
        $this->addForeignIdIfMissing('projects', 'contract_type_id', fn (Blueprint $table) => $table->foreignId('contract_type_id')->nullable()->after('contract_type')->constrained('contract_types')->nullOnDelete());
        $this->addForeignIdIfMissing('projects', 'project_status_id', fn (Blueprint $table) => $table->foreignId('project_status_id')->nullable()->after('project_status')->constrained('record_statuses')->nullOnDelete());
        $this->addForeignIdIfMissing('projects', 'billing_status_id', fn (Blueprint $table) => $table->foreignId('billing_status_id')->nullable()->after('billing_status')->constrained('record_statuses')->nullOnDelete());
        $this->addForeignIdIfMissing('people', 'position_id', fn (Blueprint $table) => $table->foreignId('position_id')->nullable()->after('role')->constrained('positions')->nullOnDelete());
        $this->addForeignIdIfMissing('people', 'employment_mode_id', fn (Blueprint $table) => $table->foreignId('employment_mode_id')->nullable()->after('modality')->constrained('employment_modes')->nullOnDelete());
        $this->addForeignIdIfMissing('people', 'employment_contract_type_id', fn (Blueprint $table) => $table->foreignId('employment_contract_type_id')->nullable()->after('contract_type')->constrained('contract_types')->nullOnDelete());
        $this->addForeignIdIfMissing('people', 'worker_status_id', fn (Blueprint $table) => $table->foreignId('worker_status_id')->nullable()->after('status')->constrained('record_statuses')->nullOnDelete());
        $this->addForeignIdIfMissing('project_assignments', 'cost_center_id', fn (Blueprint $table) => $table->foreignId('cost_center_id')->nullable()->after('cost_center')->constrained('cost_centers')->nullOnDelete());
        $this->addForeignIdIfMissing('project_assignments', 'assignment_status_id', fn (Blueprint $table) => $table->foreignId('assignment_status_id')->nullable()->after('status')->constrained('record_statuses')->nullOnDelete());
        $this->addForeignIdIfMissing('time_entries', 'cost_center_id', fn (Blueprint $table) => $table->foreignId('cost_center_id')->nullable()->after('cost_center')->constrained('cost_centers')->nullOnDelete());
        $this->addForeignIdIfMissing('sales_documents', 'document_type_id', fn (Blueprint $table) => $table->foreignId('document_type_id')->nullable()->after('document_type')->constrained('document_types')->nullOnDelete());
        $this->addForeignIdIfMissing('expense_documents', 'expense_category_id', fn (Blueprint $table) => $table->foreignId('expense_category_id')->nullable()->after('category')->constrained('expense_categories')->nullOnDelete());
        $this->addForeignIdIfMissing('expense_documents', 'expense_subcategory_id', fn (Blueprint $table) => $table->foreignId('expense_subcategory_id')->nullable()->after('subcategory')->constrained('expense_subcategories')->nullOnDelete());
        $this->addForeignIdIfMissing('expense_documents', 'document_type_id', fn (Blueprint $table) => $table->foreignId('document_type_id')->nullable()->after('document_type')->constrained('document_types')->nullOnDelete());
        $this->addForeignIdIfMissing('legal_obligations', 'obligation_type_id', fn (Blueprint $table) => $table->foreignId('obligation_type_id')->nullable()->after('obligation_type')->constrained('obligation_types')->nullOnDelete());
        $this->addForeignIdIfMissing('cash_accounts', 'bank_id', fn (Blueprint $table) => $table->foreignId('bank_id')->nullable()->after('institution')->constrained('banks')->nullOnDelete());
        $this->addForeignIdIfMissing('cash_movements', 'payment_method_id', fn (Blueprint $table) => $table->foreignId('payment_method_id')->nullable()->after('payment_method')->constrained('payment_methods')->nullOnDelete());

        if (Schema::hasTable('bank_account_types')) {
            $service = new CatalogService();

            DB::table('companies')->pluck('id')->each(function ($companyId) use ($service) {
                $service->backfillCompany((int) $companyId);
            });
        }
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
        });

        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_id');
        });

        Schema::table('legal_obligations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('obligation_type_id');
        });

        Schema::table('expense_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_category_id');
            $table->dropConstrainedForeignId('expense_subcategory_id');
            $table->dropConstrainedForeignId('document_type_id');
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_type_id');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_center_id');
        });

        Schema::table('project_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_center_id');
            $table->dropConstrainedForeignId('assignment_status_id');
        });

        Schema::table('people', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
            $table->dropConstrainedForeignId('employment_mode_id');
            $table->dropConstrainedForeignId('employment_contract_type_id');
            $table->dropConstrainedForeignId('worker_status_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropConstrainedForeignId('contract_type_id');
            $table->dropConstrainedForeignId('project_status_id');
            $table->dropConstrainedForeignId('billing_status_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_status_id');
        });

        Schema::dropIfExists('record_statuses');
        Schema::dropIfExists('obligation_types');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('expense_subcategories');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('banks');
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('contract_types');
        Schema::dropIfExists('employment_modes');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('project_managers');
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

    private function createDomainCatalog(string $tableName): void
    {
        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 40);
            $table->string('code', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'domain', 'code']);
            $table->unique(['company_id', 'domain', 'name']);
        });
    }

    private function createExpenseSubcategories(): void
    {
        if (Schema::hasTable('expense_subcategories')) {
            return;
        }

        Schema::create('expense_subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'expense_category_id', 'code']);
            $table->unique(['company_id', 'expense_category_id', 'name']);
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
