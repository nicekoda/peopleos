<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_forms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $table->string('entity_type');
            $table->string('form_key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->integer('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'entity_type', 'form_key']);
            $table->index(['tenant_id', 'entity_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_forms');
    }
};
