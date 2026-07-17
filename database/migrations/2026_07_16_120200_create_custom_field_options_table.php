<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_options', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('custom_field_definition_id')->constrained('custom_field_definitions')->cascadeOnDelete();

            $table->string('option_key');
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->string('status')->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['custom_field_definition_id', 'option_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_options');
    }
};
