<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_form_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('custom_form_id')->constrained('custom_forms')->cascadeOnDelete();

            $table->string('section_key');
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('status')->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['custom_form_id', 'section_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_form_sections');
    }
};
