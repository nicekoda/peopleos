<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checkpoint 36 — runs after the backfill migration, so every template's
 * content already lives in hr_document_template_versions (version 1)
 * before this column disappears. See docs/architecture.md for why
 * content_template moved to the version table and metadata (title/
 * description/document_type) deliberately did not.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hr_document_templates', function (Blueprint $table) {
            $table->dropColumn('content_template');
        });
    }

    /**
     * Reverse the migrations. Restores the column and repopulates it from
     * each template's current_version_id — safe as long as this runs
     * before the backfill migration's own down() deletes the version rows
     * (Laravel rolls back migrations in reverse order, so this happens
     * automatically).
     */
    public function down(): void
    {
        Schema::table('hr_document_templates', function (Blueprint $table) {
            $table->longText('content_template')->nullable()->after('document_type');
        });

        // A portable per-row loop, not a cross-database UPDATE...JOIN —
        // JOIN-based UPDATE syntax isn't consistent across the
        // SQLite/PostgreSQL drivers this app runs on (see docs/testing.md
        // for why the test suite runs on SQLite while production runs
        // PostgreSQL).
        DB::table('hr_document_templates')->whereNotNull('current_version_id')->orderBy('id')->cursor()->each(function ($template): void {
            $version = DB::table('hr_document_template_versions')->where('id', $template->current_version_id)->first();

            if ($version) {
                DB::table('hr_document_templates')->where('id', $template->id)
                    ->update(['content_template' => $version->content_template]);
            }
        });
    }
};
