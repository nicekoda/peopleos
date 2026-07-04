<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Checkpoint 28 — distinguishes seeded/built-in roles (protected:
     * view-only, no edit, no permission changes, no delete) from
     * tenant-admin-created custom roles (fully manageable). Defaults
     * `false` so any row inserted by a future codepath that forgets to
     * set it explicitly fails safe as "custom," never accidentally as
     * "system" — the reverse (a real system role missing this flag)
     * would be the dangerous direction, so every existing seeded row is
     * explicitly backfilled to `true` below, and `RoleSeeder` itself now
     * sets it explicitly on every row it creates (see docs/security.md).
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_system_role')->default(false);
        });

        DB::table('roles')->update(['is_system_role' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_system_role');
        });
    }
};
