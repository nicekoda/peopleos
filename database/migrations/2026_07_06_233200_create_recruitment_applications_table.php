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
        Schema::create('recruitment_applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('recruitment_job_id')->constrained('recruitment_jobs')->cascadeOnDelete();
            $table->foreignUlid('recruitment_applicant_id')->constrained('recruitment_applicants')->cascadeOnDelete();

            $table->string('stage')->default('applied');
            $table->string('status')->default('active');

            // Reserved for a future resume-upload feature — no upload
            // endpoint exists yet this checkpoint (see docs/security.md).
            $table->foreignUlid('resume_document_id')->nullable();
            $table->text('cover_letter')->nullable();

            $table->boolean('ready_for_conversion')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'recruitment_job_id']);
            $table->index(['tenant_id', 'stage']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_applications');
    }
};
