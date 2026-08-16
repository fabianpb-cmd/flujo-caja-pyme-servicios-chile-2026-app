<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            if (! Schema::hasColumn('people', 'hourly_rate_unit_type')) {
                $table->string('hourly_rate_unit_type', 20)->default('CURRENCY')->after('hourly_value');
            }

            if (! Schema::hasColumn('people', 'hourly_rate_currency_id')) {
                $table->foreignId('hourly_rate_currency_id')->nullable()->after('hourly_rate_unit_type')->constrained('currencies')->nullOnDelete();
            }
        });

        Schema::table('project_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('project_assignments', 'hourly_rate_unit_type')) {
                $table->string('hourly_rate_unit_type', 20)->default('CURRENCY')->after('hourly_value');
            }

            if (! Schema::hasColumn('project_assignments', 'hourly_rate_currency_id')) {
                $table->foreignId('hourly_rate_currency_id')->nullable()->after('hourly_rate_unit_type')->constrained('currencies')->nullOnDelete();
            }
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_documents', 'vat_rate')) {
                $table->decimal('vat_rate', 8, 6)->nullable()->after('net_amount');
            }
        });

        Schema::table('expense_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('expense_documents', 'vat_rate')) {
                $table->decimal('vat_rate', 8, 6)->nullable()->after('net_amount');
            }
        });

        $companyCurrencies = DB::table('currencies')
            ->where('code', 'CLP')
            ->pluck('id', 'company_id');

        foreach (DB::table('people')->select('id', 'company_id', 'hourly_value')->whereNotNull('hourly_value')->get() as $row) {
            $currencyId = $companyCurrencies[$row->company_id] ?? null;
            if ($currencyId) {
                DB::table('people')->where('id', $row->id)->update([
                    'hourly_rate_unit_type' => 'CURRENCY',
                    'hourly_rate_currency_id' => $currencyId,
                ]);
            }
        }

        foreach (DB::table('project_assignments')->select('id', 'company_id', 'hourly_value')->whereNotNull('hourly_value')->get() as $row) {
            $currencyId = $companyCurrencies[$row->company_id] ?? null;
            if ($currencyId) {
                DB::table('project_assignments')->where('id', $row->id)->update([
                    'hourly_rate_unit_type' => 'CURRENCY',
                    'hourly_rate_currency_id' => $currencyId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('expense_documents', function (Blueprint $table) {
            if (Schema::hasColumn('expense_documents', 'vat_rate')) {
                $table->dropColumn('vat_rate');
            }
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            if (Schema::hasColumn('sales_documents', 'vat_rate')) {
                $table->dropColumn('vat_rate');
            }
        });

        Schema::table('project_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('project_assignments', 'hourly_rate_currency_id')) {
                $table->dropConstrainedForeignId('hourly_rate_currency_id');
            }

            if (Schema::hasColumn('project_assignments', 'hourly_rate_unit_type')) {
                $table->dropColumn('hourly_rate_unit_type');
            }
        });

        Schema::table('people', function (Blueprint $table) {
            if (Schema::hasColumn('people', 'hourly_rate_currency_id')) {
                $table->dropConstrainedForeignId('hourly_rate_currency_id');
            }

            if (Schema::hasColumn('people', 'hourly_rate_unit_type')) {
                $table->dropColumn('hourly_rate_unit_type');
            }
        });
    }
};
