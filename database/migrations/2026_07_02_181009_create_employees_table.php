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
        Schema::create('employees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $table->string('employee_number');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('preferred_name')->nullable();

            $table->string('work_email')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('phone')->nullable();

            $table->string('status')->default('draft');
            $table->string('employment_type');

            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignUlid('position_id')->nullable()->constrained('positions')->nullOnDelete();

            // Self-referencing FK: column defined here, constraint added
            // below in a separate Schema::table() call once the table
            // (and its primary key) fully exists — Postgres rejects a
            // self-referencing FK constraint added within the same
            // CREATE TABLE statement.
            $table->ulid('manager_employee_id')->nullable();

            $table->date('start_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->date('confirmation_date')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'employee_number']);
            $table->unique(['tenant_id', 'work_email']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('manager_employee_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
