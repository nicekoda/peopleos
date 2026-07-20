<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_form_fields', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('custom_form_section_id')->constrained('custom_form_sections')->cascadeOnDelete();
            $table->foreignUlid('custom_field_definition_id')->constrained('custom_field_definitions')->restrictOnDelete();

            $table->string('label_override')->nullable();
            $table->string('help_text')->nullable();
            $table->string('placeholder')->nullable();
            $table->boolean('is_required_override')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('status')->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['custom_form_section_id', 'custom_field_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_form_fields');
    }
};
