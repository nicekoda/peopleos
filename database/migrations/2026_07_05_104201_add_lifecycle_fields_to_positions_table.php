<?php

use App\Models\Position;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Checkpoint 32 — additive only. See the equivalent departments
     * migration for the full reasoning (nullable slug, plain-Eloquent
     * backfill so it behaves identically on Postgres and SQLite).
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
            $table->text('description')->nullable()->after('slug');
            $table->string('status')->default('active')->after('description');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->unique(['tenant_id', 'slug']);
        });

        Position::withoutGlobalScopes()->whereNull('slug')->get()->each(function (Position $position): void {
            $base = Str::slug($position->name);
            $slug = $base;
            $suffix = 1;

            while (
                Position::withoutGlobalScopes()
                    ->where('tenant_id', $position->tenant_id)
                    ->where('slug', $slug)
                    ->where('id', '!=', $position->id)
                    ->exists()
            ) {
                $suffix++;
                $slug = "{$base}-{$suffix}";
            }

            $position->forceFill(['slug' => $slug])->saveQuietly();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'slug']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['slug', 'description', 'status']);
        });
    }
};
