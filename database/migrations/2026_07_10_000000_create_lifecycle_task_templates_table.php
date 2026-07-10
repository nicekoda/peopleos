<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkpoint 42 — Onboarding & Offboarding Task Templates Foundation.
 * A tenant-owned catalog of default tasks per LifecycleProcess type
 * (onboarding/offboarding) — deliberately its own table, not a column
 * on employee_lifecycle_tasks, so a template can be edited/archived
 * without touching any task that was ever generated from it (tasks
 * copy title/description/due-date-offset at generation time and then
 * live entirely independently, the same "generate once, then
 * independent" posture HR Documents already established for rendered
 * content vs. template content).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lifecycle_task_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('due_in_days')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->unique(['tenant_id', 'type', 'title']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lifecycle_task_templates');
    }
};
