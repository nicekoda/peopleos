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
        Schema::create('policy_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('policy_id')->constrained('policies')->cascadeOnDelete();

            $table->unsignedInteger('version_number');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();

            // Nullable, tenant-validated at the app layer. See
            // docs/database.md for the schema mismatch this carries: a
            // policy document isn't owned by any single employee, but
            // employee_documents.employee_id is NOT NULL. content is the
            // primary path for this checkpoint; this field is a
            // forward-compatible placeholder until a general
            // tenant-level document table exists.
            $table->foreignUlid('employee_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();

            $table->string('status')->default('draft');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'policy_id', 'version_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_versions');
    }
};
