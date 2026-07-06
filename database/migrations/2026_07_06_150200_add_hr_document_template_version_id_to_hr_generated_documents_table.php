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
        Schema::table('hr_generated_documents', function (Blueprint $table) {
            $table->foreignUlid('hr_document_template_version_id')->nullable()
                ->after('hr_document_template_id')
                ->constrained('hr_document_template_versions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_generated_documents', function (Blueprint $table) {
            $table->dropForeign(['hr_document_template_version_id']);
            $table->dropColumn('hr_document_template_version_id');
        });
    }
};
