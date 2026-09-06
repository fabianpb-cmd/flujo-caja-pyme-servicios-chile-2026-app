<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_billing_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('name');
            $table->date('planned_invoice_date')->nullable();
            $table->decimal('percentage', 7, 4);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'sequence']);
        });

        Schema::table('sales_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_documents', 'project_billing_milestone_id')) {
                $table->foreignId('project_billing_milestone_id')->nullable()->after('project_id')
                    ->constrained('project_billing_milestones')->nullOnDelete();
                $table->index(['company_id', 'project_billing_milestone_id'], 'sales_docs_milestone_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_documents', 'project_billing_milestone_id')) {
                $table->dropIndex('sales_docs_milestone_idx');
                $table->dropConstrainedForeignId('project_billing_milestone_id');
            }
        });
        Schema::dropIfExists('project_billing_milestones');
    }
};
