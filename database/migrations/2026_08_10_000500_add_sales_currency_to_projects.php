<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'sales_currency_id')) {
                $table->foreignId('sales_currency_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained('currencies')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'sales_currency_id')) {
                $table->dropConstrainedForeignId('sales_currency_id');
            }
        });
    }
};
