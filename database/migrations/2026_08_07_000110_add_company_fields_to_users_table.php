<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            $table->string('role', 30)->default('user')->after('password');
            $table->boolean('active')->default(true)->after('role');
            $table->index(['company_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'email']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['role', 'active']);
        });
    }
};
