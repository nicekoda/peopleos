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
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->restrictOnDelete();

            $table->string('status')->default('active')->after('password');
            $table->boolean('is_platform_admin')->default(false)->after('status');
            $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->softDeletes();
        });

        // Platform admins must not belong to a tenant; tenant users must.
        // Enforced at the app layer too (User::booted saving guard) since
        // SQLite (used for the test suite) can't ALTER TABLE ADD CONSTRAINT.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE users
                ADD CONSTRAINT users_platform_admin_tenant_consistency
                CHECK (
                    (is_platform_admin = true AND tenant_id IS NULL)
                    OR (is_platform_admin = false AND tenant_id IS NOT NULL)
                )
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_platform_admin_tenant_consistency');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['status', 'is_platform_admin', 'last_login_at', 'last_login_ip', 'deleted_at']);
        });
    }
};
