<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('company_settings', 'active')) {
                $table->boolean('active')->default(true)->after('is_public');
            }
        });

        Schema::table('legal_parameters', function (Blueprint $table) {
            if (! Schema::hasColumn('legal_parameters', 'category')) {
                $table->string('category', 80)->nullable()->after('parameter_name');
            }
            if (! Schema::hasColumn('legal_parameters', 'active')) {
                $table->boolean('active')->default(true)->after('notes');
            }
            if (! Schema::hasColumn('legal_parameters', 'source_name')) {
                $table->string('source_name')->nullable()->after('source');
            }
            if (! Schema::hasColumn('legal_parameters', 'source_url')) {
                $table->string('source_url')->nullable()->after('source_name');
            }
        });

        Schema::table('uf_values', function (Blueprint $table) {
            if (! Schema::hasColumn('uf_values', 'active')) {
                $table->boolean('active')->default(true)->after('notes');
            }
            if (! Schema::hasColumn('uf_values', 'source_name')) {
                $table->string('source_name')->nullable()->after('source');
            }
            if (! Schema::hasColumn('uf_values', 'source_url')) {
                $table->string('source_url')->nullable()->after('source_name');
            }
        });

        Schema::table('afp_rates', function (Blueprint $table) {
            if (! Schema::hasColumn('afp_rates', 'active')) {
                $table->boolean('active')->default(true)->after('source');
            }
            if (! Schema::hasColumn('afp_rates', 'source_url')) {
                $table->string('source_url')->nullable()->after('source');
            }
        });

        Schema::table('income_tax_brackets', function (Blueprint $table) {
            if (! Schema::hasColumn('income_tax_brackets', 'active')) {
                $table->boolean('active')->default(true)->after('source_url');
            }
        });

        if (! Schema::hasTable('utm_values')) {
            Schema::create('utm_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('period_year');
                $table->unsignedTinyInteger('period_month');
                $table->decimal('value', 18, 4);
                $table->string('source')->nullable();
                $table->string('source_name')->nullable();
                $table->string('source_url')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->unique(['company_id', 'period_year', 'period_month'], 'utm_values_period_unique');
            });
        }

        if (! Schema::hasTable('exchange_rates')) {
            Schema::create('exchange_rates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('currency_id')->constrained('currencies')->cascadeOnDelete();
                $table->date('rate_date');
                $table->decimal('value_clp', 18, 6);
                $table->string('source')->nullable();
                $table->string('source_name')->nullable();
                $table->string('source_url')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->unique(['company_id', 'currency_id', 'rate_date'], 'exchange_rates_unique_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('utm_values');

        Schema::table('income_tax_brackets', function (Blueprint $table) {
            if (Schema::hasColumn('income_tax_brackets', 'active')) {
                $table->dropColumn('active');
            }
        });

        Schema::table('afp_rates', function (Blueprint $table) {
            if (Schema::hasColumn('afp_rates', 'source_url')) {
                $table->dropColumn('source_url');
            }
            if (Schema::hasColumn('afp_rates', 'active')) {
                $table->dropColumn('active');
            }
        });

        Schema::table('uf_values', function (Blueprint $table) {
            foreach (['active', 'source_name', 'source_url'] as $column) {
                if (Schema::hasColumn('uf_values', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('legal_parameters', function (Blueprint $table) {
            foreach (['category', 'active', 'source_name', 'source_url'] as $column) {
                if (Schema::hasColumn('legal_parameters', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('company_settings', function (Blueprint $table) {
            if (Schema::hasColumn('company_settings', 'active')) {
                $table->dropColumn('active');
            }
        });
    }
};
