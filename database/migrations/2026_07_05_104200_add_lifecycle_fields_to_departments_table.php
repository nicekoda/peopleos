<?php

use App\Models\Department;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Checkpoint 32 — additive only. `slug` is nullable (not backed by a
     * NOT NULL constraint) so this never risks failing against
     * pre-existing rows (seeded via DemoDataSeeder since Checkpoint 26);
     * existing rows are backfilled below via plain Eloquent rather than
     * driver-specific SQL, so it behaves identically on the Postgres
     * dev/demo database and the SQLite test database. No existing
     * column is touched or removed.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
            $table->text('description')->nullable()->after('slug');
            $table->string('status')->default('active')->after('description');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->unique(['tenant_id', 'slug']);
        });

        Department::withoutGlobalScopes()->whereNull('slug')->get()->each(function (Department $department): void {
            $base = Str::slug($department->name);
            $slug = $base;
            $suffix = 1;

            while (
                Department::withoutGlobalScopes()
                    ->where('tenant_id', $department->tenant_id)
                    ->where('slug', $slug)
                    ->where('id', '!=', $department->id)
                    ->exists()
            ) {
                $suffix++;
                $slug = "{$base}-{$suffix}";
            }

            $department->forceFill(['slug' => $slug])->saveQuietly();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'slug']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['slug', 'description', 'status']);
        });
    }
};
