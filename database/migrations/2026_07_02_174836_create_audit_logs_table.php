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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type')->nullable();

            $table->string('action');
            $table->string('module');

            // Polymorphic reference, deliberately not a real FK: auditable
            // records may have mixed PK types (bigint today, ULID for
            // future tenant-owned models).
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id')->nullable();

            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('severity')->nullable()->default('info');

            $table->timestamp('created_at')->useCurrent();

            $table->index('action');
            $table->index('module');
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
