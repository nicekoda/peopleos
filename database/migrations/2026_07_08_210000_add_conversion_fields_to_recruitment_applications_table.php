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
        Schema::table('recruitment_applications', function (Blueprint $table) {
            $table->foreignUlid('converted_employee_id')->nullable()->after('ready_for_conversion')->constrained('employees')->nullOnDelete();
            $table->timestamp('converted_at')->nullable()->after('converted_employee_id');
            $table->foreignId('converted_by')->nullable()->after('converted_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recruitment_applications', function (Blueprint $table) {
            $table->dropForeign(['converted_by']);
            $table->dropForeign(['converted_employee_id']);
            $table->dropColumn(['converted_employee_id', 'converted_at', 'converted_by']);
        });
    }
};
