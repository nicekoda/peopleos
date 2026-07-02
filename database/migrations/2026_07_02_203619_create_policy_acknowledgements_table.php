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
        Schema::create('policy_acknowledgements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignUlid('policy_version_id')->constrained('policy_versions')->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->date('due_date')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgement_status')->default('pending');
            $table->string('acknowledgement_method')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            // Prevents duplicate active acknowledgement rows for the same
            // employee + policy version. No soft delete on this table —
            // not in the spec's field list, and there's no delete
            // endpoint; these are compliance-evidence records.
            $table->unique(['tenant_id', 'policy_version_id', 'employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgements');
    }
};
