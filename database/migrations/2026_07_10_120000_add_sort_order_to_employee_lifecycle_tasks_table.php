<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkpoint 45 — Lifecycle Task Ordering & Reminders. sort_order
 * mirrors lifecycle_task_templates.sort_order (Checkpoint 42) — a task
 * generated from a template copies the template's sort_order at
 * generation time (see LifecycleTaskTemplateApplier); a manually-added
 * task is placed at the end of its process's existing list (see
 * LifecycleTaskController::store()). The full list can also be bulk
 * reordered afterward (see LifecycleTaskController::reorder()).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employee_lifecycle_tasks', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_lifecycle_tasks', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
