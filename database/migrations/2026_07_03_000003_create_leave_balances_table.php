<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUlid('leave_type_id')->constrained('leave_types')->restrictOnDelete();

            $table->unsignedSmallInteger('year');
            // decimal(6,2), not integer — half-day leave isn't built this
            // checkpoint, but this makes it a data-only change later
            // rather than a schema migration too.
            $table->decimal('entitlement_days', 6, 2);
            $table->decimal('used_days', 6, 2)->default(0);
            $table->decimal('pending_days', 6, 2)->default(0);
            $table->decimal('carried_forward_days', 6, 2)->default(0);
            $table->decimal('adjustment_days', 6, 2)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        // A composite unique constraint alone would block recreating a
        // balance after the original was soft-deleted (both Postgres and
        // SQLite still enforce uniqueness against soft-deleted rows
        // unless the index itself excludes them). Partial index, same
        // pattern as roles.slug's platform-role uniqueness (see that
        // migration) — works on both drivers.
        DB::statement(
            'CREATE UNIQUE INDEX leave_balances_tenant_employee_type_year_unique '.
            'ON leave_balances (tenant_id, employee_id, leave_type_id, year) '.
            'WHERE deleted_at IS NULL',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS leave_balances_tenant_employee_type_year_unique');

        Schema::dropIfExists('leave_balances');
    }
};
