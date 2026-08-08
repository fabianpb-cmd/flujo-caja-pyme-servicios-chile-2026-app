<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('tax_id', 30)->nullable()->index();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('setting_key', 100);
            $table->longText('setting_value')->nullable();
            $table->string('setting_type', 20)->default('string');
            $table->boolean('is_public')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'setting_key']);
        });

        Schema::create('legal_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('parameter_code', 80);
            $table->string('parameter_name');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->decimal('value', 18, 6);
            $table->string('unit', 20);
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'parameter_code', 'valid_from'], 'legal_parameters_unique_vigency');
        });

        Schema::create('uf_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('value_date');
            $table->decimal('value', 18, 4);
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'value_date']);
        });

        Schema::create('afps', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('afp_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('afp_id')->constrained('afps')->cascadeOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->decimal('employee_commission_rate', 8, 6)->default(0);
            $table->decimal('employer_commission_rate', 8, 6)->default(0);
            $table->decimal('insurance_rate', 8, 6)->default(0);
            $table->string('source')->nullable();
            $table->timestamps();
            $table->unique(['afp_id', 'valid_from']);
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('legal_name');
            $table->string('tax_id', 30)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->unsignedSmallInteger('payment_term_days')->default(30);
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'tax_id']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('manager')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('contract_type', 40)->nullable();
            $table->decimal('sale_net', 18, 2)->default(0);
            $table->decimal('vat_rate', 8, 6)->default(0);
            $table->decimal('sale_total', 18, 2)->default(0);
            $table->string('payment_form', 40)->nullable();
            $table->unsignedSmallInteger('installments')->default(1);
            $table->date('invoice_date')->nullable();
            $table->date('projected_collection_date')->nullable();
            $table->string('project_status', 20)->default('active');
            $table->string('billing_status', 20)->default('pending');
            $table->decimal('contracted_hourly_rate', 18, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'client_id']);
        });

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('identifier', 40)->nullable();
            $table->string('role')->nullable();
            $table->string('modality', 40);
            $table->string('contract_type', 40)->nullable();
            $table->foreignId('afp_id')->nullable()->constrained('afps')->nullOnDelete();
            $table->string('health_system', 40)->nullable();
            $table->decimal('additional_health_plan', 18, 2)->nullable();
            $table->decimal('monthly_value', 18, 2)->nullable();
            $table->decimal('hourly_value', 18, 2)->nullable();
            $table->unsignedSmallInteger('monthly_hours')->nullable();
            $table->text('payment_data')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('project_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->decimal('hourly_value', 18, 2)->nullable();
            $table->decimal('project_value', 18, 2)->nullable();
            $table->unsignedSmallInteger('monthly_hours')->nullable();
            $table->string('cost_center')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'person_id', 'project_id']);
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('project_assignments')->nullOnDelete();
            $table->date('entry_date');
            $table->string('activity');
            $table->decimal('hours_worked', 10, 2)->default(0);
            $table->decimal('hours_approved', 10, 2)->default(0);
            $table->decimal('hourly_value', 18, 2)->nullable();
            $table->decimal('calculated_amount', 18, 2)->default(0);
            $table->string('approval_status', 20)->default('pending');
            $table->string('payment_status', 20)->default('pending');
            $table->date('pay_period')->nullable();
            $table->string('cost_center')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'person_id', 'project_id', 'entry_date']);
        });

        Schema::create('payroll_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->date('period_date');
            $table->date('payment_date')->nullable();
            $table->unsignedSmallInteger('worked_days')->nullable();
            $table->unsignedSmallInteger('month_days')->nullable();
            $table->decimal('monthly_value', 18, 2)->nullable();
            $table->decimal('hourly_value', 18, 2)->nullable();
            $table->decimal('project_value', 18, 2)->nullable();
            $table->decimal('base_salary', 18, 2)->default(0);
            $table->decimal('bonuses', 18, 2)->default(0);
            $table->decimal('non_taxable_allowances', 18, 2)->default(0);
            $table->decimal('taxable_amount', 18, 2)->default(0);
            $table->decimal('employee_retention', 18, 2)->default(0);
            $table->decimal('employer_cost', 18, 2)->default(0);
            $table->decimal('vacation_provision', 18, 2)->default(0);
            $table->decimal('net_pay', 18, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'person_id', 'period_date']);
        });

        Schema::create('sales_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('document_type', 40);
            $table->string('document_number', 60)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('projected_collection_date')->nullable();
            $table->date('scenario_collection_date')->nullable();
            $table->date('actual_collection_date')->nullable();
            $table->decimal('payment_probability', 8, 6)->nullable();
            $table->decimal('net_amount', 18, 2)->default(0);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('collected_amount', 18, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->boolean('is_voided')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'client_id', 'project_id', 'issue_date']);
        });

        Schema::create('expense_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->string('vendor_name')->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('category', 80)->nullable();
            $table->string('subcategory', 80)->nullable();
            $table->string('expense_type', 40)->nullable();
            $table->string('document_type', 40)->nullable();
            $table->string('document_number', 60)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('projected_payment_date')->nullable();
            $table->date('actual_payment_date')->nullable();
            $table->decimal('net_amount', 18, 2)->default(0);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('recoverable_vat_amount', 18, 2)->default(0);
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->string('payment_status', 20)->default('pending');
            $table->boolean('tax_deductible')->default(true);
            $table->boolean('deductible_vat')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'project_id', 'due_date']);
        });

        Schema::create('legal_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->string('obligation_type', 80);
            $table->date('period_date');
            $table->date('due_date')->nullable();
            $table->text('base_detail')->nullable();
            $table->decimal('estimated_amount', 18, 2)->default(0);
            $table->date('payment_date')->nullable();
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('pending_amount', 18, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('source_calculation')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('vat_carryforward_amount', 18, 2)->default(0);
            $table->timestamps();
            $table->index(['company_id', 'period_date', 'due_date']);
        });

        Schema::create('scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->decimal('sales_factor', 8, 4)->default(1);
            $table->decimal('cost_factor', 8, 4)->default(1);
            $table->smallInteger('collection_delay_days')->default(0);
            $table->decimal('new_hires_monthly', 18, 2)->default(0);
            $table->foreignId('affected_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->boolean('client_loss_flag')->default(false);
            $table->decimal('tariff_variation', 8, 4)->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('scenario_id')->nullable()->constrained('scenarios')->nullOnDelete();
            $table->date('period_date');
            $table->decimal('revenue_budget', 18, 2)->default(0);
            $table->decimal('personnel_budget', 18, 2)->default(0);
            $table->decimal('other_direct_budget', 18, 2)->default(0);
            $table->decimal('legal_budget', 18, 2)->default(0);
            $table->decimal('other_indirect_budget', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'period_date', 'project_id']);
        });

        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('institution')->nullable();
            $table->string('account_type', 40)->nullable();
            $table->string('currency', 10)->default('CLP');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->string('movement_type', 40);
            $table->string('source_document_type', 40)->nullable();
            $table->string('source_document_code', 40)->nullable();
            $table->string('counterparty_name')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->date('movement_date');
            $table->decimal('income', 18, 2)->default(0);
            $table->decimal('expense', 18, 2)->default(0);
            $table->string('payment_method', 40)->nullable();
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('posted');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'movement_date', 'movement_type']);
        });

        Schema::create('monthly_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period_date');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->decimal('closing_balance', 18, 2)->default(0);
            $table->decimal('cash_in', 18, 2)->default(0);
            $table->decimal('cash_out', 18, 2)->default(0);
            $table->decimal('accounts_receivable', 18, 2)->default(0);
            $table->decimal('accounts_payable', 18, 2)->default(0);
            $table->string('status', 20)->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'period_date']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('monthly_closures');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_accounts');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('scenarios');
        Schema::dropIfExists('legal_obligations');
        Schema::dropIfExists('expense_documents');
        Schema::dropIfExists('sales_documents');
        Schema::dropIfExists('payroll_records');
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('project_assignments');
        Schema::dropIfExists('people');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('afp_rates');
        Schema::dropIfExists('afps');
        Schema::dropIfExists('uf_values');
        Schema::dropIfExists('legal_parameters');
        Schema::dropIfExists('company_settings');
        Schema::dropIfExists('companies');
    }
};
