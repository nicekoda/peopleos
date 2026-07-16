<?php

use App\Enums\TenantModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('module_key');
            $table->boolean('enabled')->default(true);
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'module_key']);
        });

        // Backfill — explicit enabled rows for every existing tenant, for
        // every toggleable module, per your approved "explicit rows are
        // the steady state, missing-row-means-enabled is a fallback
        // only" design. Uses the query builder directly (not Eloquent),
        // same "correctness against historical schema shape" reasoning
        // every prior data-migration backfill in this app already
        // follows. On a fresh install there are no tenants yet at
        // migration time (TenantSeeder runs after migrations), so this
        // is a no-op then — new tenants are provisioned instead by
        // Tenant's own creation hook (see App\Models\Tenant).
        $tenantIds = DB::table('tenants')->pluck('id');
        $now = now();

        foreach ($tenantIds as $tenantId) {
            foreach (TenantModule::toggleable() as $module) {
                DB::table('tenant_modules')->insertOrIgnore([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $tenantId,
                    'module_key' => $module->value,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
    }
};
