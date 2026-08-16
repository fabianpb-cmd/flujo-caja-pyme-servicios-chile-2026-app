<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('income_tax_brackets')) {
            Schema::create('income_tax_brackets', function (Blueprint $table) {
                $table->id();
                $table->unsignedSmallInteger('period_year');
                $table->unsignedTinyInteger('period_month');
                $table->string('period_type', 20)->default('MENSUAL');
                $table->decimal('from_amount', 18, 2)->default(0);
                $table->decimal('to_amount', 18, 2)->nullable();
                $table->decimal('factor', 10, 6)->default(0);
                $table->decimal('rebate_amount', 18, 2)->default(0);
                $table->decimal('effective_max_rate', 8, 4)->nullable();
                $table->string('source_url')->nullable();
                $table->timestamps();
                $table->unique(['period_year', 'period_month', 'period_type', 'from_amount'], 'itb_period_from_unique');
                $table->index(['period_year', 'period_month', 'period_type']);
            });
        }

        Schema::table('payroll_records', function (Blueprint $table) {
            $columns = [
                'amount_basis' => fn () => $table->string('amount_basis', 10)->default('GROSS')->after('project_value'),
                'hours_approved' => fn () => $table->decimal('hours_approved', 10, 2)->nullable()->after('amount_basis'),
                'gross_amount' => fn () => $table->decimal('gross_amount', 18, 2)->default(0)->after('base_salary'),
                'taxable_gross' => fn () => $table->decimal('taxable_gross', 18, 2)->default(0)->after('taxable_amount'),
                'pension_health_base' => fn () => $table->decimal('pension_health_base', 18, 2)->default(0)->after('taxable_gross'),
                'afc_base' => fn () => $table->decimal('afc_base', 18, 2)->default(0)->after('pension_health_base'),
                'uf_value' => fn () => $table->decimal('uf_value', 18, 4)->nullable()->after('afc_base'),
                'pension_cap_uf' => fn () => $table->decimal('pension_cap_uf', 10, 4)->nullable()->after('uf_value'),
                'afc_cap_uf' => fn () => $table->decimal('afc_cap_uf', 10, 4)->nullable()->after('pension_cap_uf'),
                'retention_rate' => fn () => $table->decimal('retention_rate', 10, 6)->default(0)->after('employee_retention'),
                'afp_mandatory' => fn () => $table->decimal('afp_mandatory', 18, 2)->default(0)->after('retention_rate'),
                'afp_commission_rate' => fn () => $table->decimal('afp_commission_rate', 10, 6)->default(0)->after('afp_mandatory'),
                'afp_commission' => fn () => $table->decimal('afp_commission', 18, 2)->default(0)->after('afp_commission_rate'),
                'health_legal' => fn () => $table->decimal('health_legal', 18, 2)->default(0)->after('afp_commission'),
                'health_additional' => fn () => $table->decimal('health_additional', 18, 2)->default(0)->after('health_legal'),
                'health_employee' => fn () => $table->decimal('health_employee', 18, 2)->default(0)->after('health_additional'),
                'afc_employee_rate' => fn () => $table->decimal('afc_employee_rate', 10, 6)->default(0)->after('health_employee'),
                'afc_employee' => fn () => $table->decimal('afc_employee', 18, 2)->default(0)->after('afc_employee_rate'),
                'afc_employer_rate' => fn () => $table->decimal('afc_employer_rate', 10, 6)->default(0)->after('afc_employee'),
                'afc_employer' => fn () => $table->decimal('afc_employer', 18, 2)->default(0)->after('afc_employer_rate'),
                'employer_pension_rate' => fn () => $table->decimal('employer_pension_rate', 10, 6)->default(0)->after('afc_employer'),
                'employer_pension' => fn () => $table->decimal('employer_pension', 18, 2)->default(0)->after('employer_pension_rate'),
                'accident_insurance_rate' => fn () => $table->decimal('accident_insurance_rate', 10, 6)->default(0)->after('employer_pension'),
                'accident_insurance' => fn () => $table->decimal('accident_insurance', 18, 2)->default(0)->after('accident_insurance_rate'),
                'sanna_rate' => fn () => $table->decimal('sanna_rate', 10, 6)->default(0)->after('accident_insurance'),
                'sanna' => fn () => $table->decimal('sanna', 18, 2)->default(0)->after('sanna_rate'),
                'iusc_taxable_base' => fn () => $table->decimal('iusc_taxable_base', 18, 2)->default(0)->after('sanna'),
                'iusc_bracket' => fn () => $table->string('iusc_bracket')->nullable()->after('iusc_taxable_base'),
                'iusc_factor' => fn () => $table->decimal('iusc_factor', 10, 6)->default(0)->after('iusc_bracket'),
                'iusc_rebate' => fn () => $table->decimal('iusc_rebate', 18, 2)->default(0)->after('iusc_factor'),
                'iusc_amount' => fn () => $table->decimal('iusc_amount', 18, 2)->default(0)->after('iusc_rebate'),
                'advances' => fn () => $table->decimal('advances', 18, 2)->default(0)->after('iusc_amount'),
                'other_deductions' => fn () => $table->decimal('other_deductions', 18, 2)->default(0)->after('advances'),
                'vacation_days_accrued_period' => fn () => $table->decimal('vacation_days_accrued_period', 10, 4)->default(0)->after('vacation_provision'),
                'vacation_daily_value' => fn () => $table->decimal('vacation_daily_value', 18, 2)->default(0)->after('vacation_days_accrued_period'),
                'vacation_provision_amount' => fn () => $table->decimal('vacation_provision_amount', 18, 2)->default(0)->after('vacation_daily_value'),
                'legal_snapshot' => fn () => $table->json('legal_snapshot')->nullable()->after('net_pay'),
                'calculation_status' => fn () => $table->string('calculation_status', 30)->default('OK')->after('legal_snapshot'),
                'calculation_notes' => fn () => $table->text('calculation_notes')->nullable()->after('calculation_status'),
            ];

            foreach ($columns as $name => $definition) {
                if (! Schema::hasColumn('payroll_records', $name)) {
                    $definition();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_tax_brackets');
    }
};
