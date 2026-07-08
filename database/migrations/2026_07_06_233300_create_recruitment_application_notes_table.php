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
        Schema::create('recruitment_application_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUlid('recruitment_application_id')->constrained('recruitment_applications')->cascadeOnDelete();

            $table->text('note');
            // Only 'internal' is ever written this checkpoint — reserved
            // as a string (not an enum-backed column) in case a future
            // checkpoint adds a second visibility tier. See docs/security.md.
            $table->string('visibility')->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'recruitment_application_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_application_notes');
    }
};
