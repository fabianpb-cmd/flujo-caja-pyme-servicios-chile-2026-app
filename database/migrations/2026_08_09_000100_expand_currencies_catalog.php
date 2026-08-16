<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            if (! Schema::hasColumn('currencies', 'symbol')) {
                $table->string('symbol', 12)->nullable()->after('name');
            }

            if (! Schema::hasColumn('currencies', 'minor_units')) {
                $table->unsignedSmallInteger('minor_units')->default(2)->after('symbol');
            }

            if (! Schema::hasColumn('currencies', 'is_base_currency')) {
                $table->boolean('is_base_currency')->default(false)->after('active');
            }
        });

        $defaults = [
            'CLP' => ['symbol' => '$', 'minor_units' => 0, 'is_base_currency' => true],
            'USD' => ['symbol' => 'US$', 'minor_units' => 2, 'is_base_currency' => false],
            'EUR' => ['symbol' => '€', 'minor_units' => 2, 'is_base_currency' => false],
            'UF' => ['symbol' => 'UF', 'minor_units' => 2, 'is_base_currency' => false],
        ];

        foreach ($defaults as $code => $payload) {
            DB::table('currencies')->where('code', $code)->update($payload);
        }
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            if (Schema::hasColumn('currencies', 'is_base_currency')) {
                $table->dropColumn('is_base_currency');
            }

            if (Schema::hasColumn('currencies', 'minor_units')) {
                $table->dropColumn('minor_units');
            }

            if (Schema::hasColumn('currencies', 'symbol')) {
                $table->dropColumn('symbol');
            }
        });
    }
};
