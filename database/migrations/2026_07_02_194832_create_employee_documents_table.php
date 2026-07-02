<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUlid('document_category_id')->nullable()->constrained('document_categories')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('mime_type');
            $table->string('file_extension', 20);
            $table->unsignedBigInteger('file_size');
            $table->string('checksum', 64)->nullable();

            $table->string('status')->default('active');
            $table->boolean('is_sensitive')->default(false);

            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
