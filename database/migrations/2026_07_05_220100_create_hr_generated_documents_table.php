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
        Schema::create('hr_generated_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUlid('hr_document_template_id')->nullable()->constrained('hr_document_templates')->nullOnDelete();

            // Checkpoint 34 is content-only (Option A, approved) — this
            // stays null until a future checkpoint adds real PDF/DOCX file
            // generation. Nullable, same forward-compatible-placeholder
            // shape as policy_versions.employee_document_id.
            $table->foreignUlid('employee_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();

            $table->string('title');
            // Copied from the template at generation time (not a live FK
            // lookup) so editing a template later never rewrites the
            // history of documents already generated from it.
            $table->string('document_type');
            $table->string('status')->default('draft');

            $table->longText('rendered_content');

            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

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
        Schema::dropIfExists('hr_generated_documents');
    }
};
