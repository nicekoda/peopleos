<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_visibility_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('custom_field_definition_id')->constrained('custom_field_definitions')->cascadeOnDelete();
            // roles.id is a bigint, unlike every other tenant-owned table
            // this custom-fields subsystem otherwise references — a
            // foreignUlid() here would be a type mismatch.
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            $table->boolean('can_view');
            $table->boolean('can_edit');
            $table->string('status')->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['custom_field_definition_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_visibility_rules');
    }
};
