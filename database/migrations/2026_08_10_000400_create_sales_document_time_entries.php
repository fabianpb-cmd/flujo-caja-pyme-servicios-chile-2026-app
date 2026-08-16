<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_documents', 'billing_source')) {
                $table->string('billing_source', 40)->nullable()->after('is_voided');
            }
            if (! Schema::hasColumn('sales_documents', 'billing_period_date')) {
                $table->date('billing_period_date')->nullable()->after('billing_source');
            }
            if (! Schema::hasColumn('sales_documents', 'adjustment_amount')) {
                $table->decimal('adjustment_amount', 18, 2)->default(0)->after('billing_period_date');
            }
            if (! Schema::hasColumn('sales_documents', 'adjustment_reason')) {
                $table->text('adjustment_reason')->nullable()->after('adjustment_amount');
            }
            if (! Schema::hasColumn('sales_documents', 'billing_snapshot')) {
                $table->json('billing_snapshot')->nullable()->after('adjustment_reason');
            }
            if (! Schema::hasColumn('sales_documents', 'calculation_status')) {
                $table->string('calculation_status', 30)->default('OK')->after('billing_snapshot');
            }
            if (! Schema::hasColumn('sales_documents', 'calculation_notes')) {
                $table->text('calculation_notes')->nullable()->after('calculation_status');
            }
        });

        if (! Schema::hasTable('sales_document_time_entries')) {
            Schema::create('sales_document_time_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sales_document_id')->constrained('sales_documents')->cascadeOnDelete();
                $table->foreignId('time_entry_id')->constrained('time_entries')->cascadeOnDelete();
                $table->foreignId('project_assignment_id')->nullable()->constrained('project_assignments')->nullOnDelete();
                $table->decimal('hours_approved', 12, 4);
                $table->decimal('hourly_rate_amount', 18, 6);
                $table->string('rate_unit_type', 20);
                $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
                $table->decimal('subtotal_original', 18, 6);
                $table->decimal('conversion_rate', 18, 6)->nullable();
                $table->date('conversion_date')->nullable();
                $table->decimal('subtotal_clp', 18, 2);
                $table->json('snapshot')->nullable();
                $table->timestamps();
                $table->unique('time_entry_id', 'sales_doc_time_entry_unique');
                $table->index(['company_id', 'sales_document_id'], 'sales_doc_time_entries_doc_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_time_entries');
    }
};
