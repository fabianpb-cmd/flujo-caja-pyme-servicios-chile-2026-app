<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_source_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_document_id')->constrained('expense_documents')->restrictOnDelete();
            $table->string('document_type', 80)->nullable();
            $table->string('document_number', 120)->nullable();
            $table->string('supplier_tax_id', 40)->nullable();
            $table->string('original_filename', 255);
            $table->string('storage_path', 500);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('sha256', 64);
            $table->json('extracted_payload')->nullable();
            $table->string('extraction_model', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'expense_document_id'], 'esd_company_expense_idx');
            $table->unique(['company_id', 'sha256'], 'esd_company_sha256_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_source_documents');
    }
};
