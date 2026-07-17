<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_validation_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('custom_field_definition_id')->constrained('custom_field_definitions')->cascadeOnDelete();

            $table->string('rule_key');
            $table->string('rule_value')->nullable();

            $table->timestamps();

            $table->unique(['custom_field_definition_id', 'rule_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_validation_rules');
    }
};
