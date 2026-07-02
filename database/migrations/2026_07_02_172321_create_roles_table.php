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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->boolean('is_platform_role')->default(false);
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
        });

        // Platform roles (tenant_id IS NULL) still need slug uniqueness
        // among themselves. A plain composite unique on (tenant_id, slug)
        // doesn't cover this: both Postgres and SQLite treat every NULL as
        // distinct for uniqueness purposes. A partial unique index does —
        // and this syntax works on both drivers, unlike the CHECK
        // constraint below.
        DB::statement('CREATE UNIQUE INDEX roles_platform_slug_unique ON roles (slug) WHERE tenant_id IS NULL');

        // Platform admins must not belong to a tenant; tenant users must.
        // Enforced at the app layer too (Role::booted saving guard) since
        // SQLite (used for the test suite) can't ALTER TABLE ADD CONSTRAINT.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE roles
                ADD CONSTRAINT roles_platform_tenant_consistency
                CHECK (
                    (is_platform_role = true AND tenant_id IS NULL)
                    OR (is_platform_role = false AND tenant_id IS NOT NULL)
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
            DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_platform_tenant_consistency');
        }

        DB::statement('DROP INDEX IF EXISTS roles_platform_slug_unique');

        Schema::dropIfExists('roles');
    }
};
