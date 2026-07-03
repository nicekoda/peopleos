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
        Schema::table('employees', function (Blueprint $table) {
            // unique (not just indexed): enforces "one employee <-> one
            // user" in both directions at once — a user_id can appear on
            // at most one employee row, and each employee row can only
            // ever hold one user_id (it's a single column). nullOnDelete:
            // if the linked user account is ever hard-deleted, the
            // employee record survives, just unlinked.
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('linked_by');
            $table->dropColumn('linked_at');
        });
    }
};
