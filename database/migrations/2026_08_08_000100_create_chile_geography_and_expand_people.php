<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('regions')) {
            Schema::create('regions', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique();
                $table->string('name');
                $table->boolean('active')->default(true);
                $table->unsignedInteger('sort_order')->nullable();
                $table->timestamps();
                $table->unique('name');
            });
        }

        if (! Schema::hasTable('communes')) {
            Schema::create('communes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
                $table->string('code', 20)->unique();
                $table->string('name');
                $table->boolean('active')->default(true);
                $table->unsignedInteger('sort_order')->nullable();
                $table->timestamps();
                $table->unique(['region_id', 'name']);
            });
        }

        Schema::table('people', function (Blueprint $table) {
            if (! Schema::hasColumn('people', 'first_names')) {
                $table->string('first_names')->nullable()->after('code');
            }
            if (! Schema::hasColumn('people', 'paternal_surname')) {
                $table->string('paternal_surname')->nullable()->after('first_names');
            }
            if (! Schema::hasColumn('people', 'maternal_surname')) {
                $table->string('maternal_surname')->nullable()->after('paternal_surname');
            }
            if (! Schema::hasColumn('people', 'rut')) {
                $table->string('rut', 20)->nullable()->after('identifier');
            }
            if (! Schema::hasColumn('people', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('rut');
            }
            if (! Schema::hasColumn('people', 'nationality')) {
                $table->string('nationality', 120)->nullable()->after('birth_date');
            }
            if (! Schema::hasColumn('people', 'email')) {
                $table->string('email')->nullable()->after('nationality');
            }
            if (! Schema::hasColumn('people', 'phone_country_code')) {
                $table->string('phone_country_code', 8)->default('+56')->after('email');
            }
            if (! Schema::hasColumn('people', 'phone_number')) {
                $table->string('phone_number', 30)->nullable()->after('phone_country_code');
            }
            if (! Schema::hasColumn('people', 'secondary_phone')) {
                $table->string('secondary_phone', 30)->nullable()->after('phone_number');
            }
            if (! Schema::hasColumn('people', 'address_street')) {
                $table->string('address_street')->nullable()->after('secondary_phone');
            }
            if (! Schema::hasColumn('people', 'address_number')) {
                $table->string('address_number', 30)->nullable()->after('address_street');
            }
            if (! Schema::hasColumn('people', 'address_unit')) {
                $table->string('address_unit', 80)->nullable()->after('address_number');
            }
            if (! Schema::hasColumn('people', 'region_id')) {
                $table->foreignId('region_id')->nullable()->after('address_unit')->constrained('regions')->nullOnDelete();
            }
            if (! Schema::hasColumn('people', 'commune_id')) {
                $table->foreignId('commune_id')->nullable()->after('region_id')->constrained('communes')->nullOnDelete();
            }
            if (! Schema::hasColumn('people', 'postal_code')) {
                $table->string('postal_code', 20)->nullable()->after('commune_id');
            }
            if (! Schema::hasColumn('people', 'address_reference')) {
                $table->string('address_reference')->nullable()->after('postal_code');
            }
            if (! Schema::hasColumn('people', 'bank_id')) {
                $table->foreignId('bank_id')->nullable()->after('payment_data')->constrained('banks')->nullOnDelete();
            }
            if (! Schema::hasColumn('people', 'bank_account_type_id')) {
                $table->foreignId('bank_account_type_id')->nullable()->after('bank_id')->constrained('bank_account_types')->nullOnDelete();
            }
            if (! Schema::hasColumn('people', 'bank_account_number')) {
                $table->string('bank_account_number', 60)->nullable()->after('bank_account_type_id');
            }
            if (! Schema::hasColumn('people', 'bank_account_holder_rut')) {
                $table->string('bank_account_holder_rut', 20)->nullable()->after('bank_account_number');
            }
            if (! Schema::hasColumn('people', 'emergency_contact_name')) {
                $table->string('emergency_contact_name')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('people', 'emergency_contact_relationship')) {
                $table->string('emergency_contact_relationship', 120)->nullable()->after('emergency_contact_name');
            }
            if (! Schema::hasColumn('people', 'emergency_contact_phone')) {
                $table->string('emergency_contact_phone', 30)->nullable()->after('emergency_contact_relationship');
            }
        });

        Schema::table('people', function (Blueprint $table) {
            $table->unique(['company_id', 'rut']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('people')) {
            Schema::table('people', function (Blueprint $table) {
                foreach ([
                    'bank_account_holder_rut',
                    'bank_account_number',
                    'bank_account_type_id',
                    'bank_id',
                    'emergency_contact_phone',
                    'emergency_contact_relationship',
                    'emergency_contact_name',
                    'address_reference',
                    'postal_code',
                    'commune_id',
                    'region_id',
                    'address_unit',
                    'address_number',
                    'address_street',
                    'secondary_phone',
                    'phone_number',
                    'phone_country_code',
                    'email',
                    'nationality',
                    'birth_date',
                    'rut',
                    'maternal_surname',
                    'paternal_surname',
                    'first_names',
                ] as $column) {
                    if (Schema::hasColumn('people', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('communes')) {
            Schema::dropIfExists('communes');
        }

        if (Schema::hasTable('regions')) {
            Schema::dropIfExists('regions');
        }
    }
};
