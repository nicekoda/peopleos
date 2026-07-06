<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Checkpoint 36 — every existing HrDocumentTemplate gets a version 1
 * (published) carrying its current content_template, and every existing
 * HrGeneratedDocument gets its hr_document_template_version_id backfilled
 * to that same version — accurate, not a guess, since before this
 * checkpoint a template only ever had one live content_template, so every
 * document ever generated from it was necessarily generated from what is
 * now "version 1". Deliberately uses the query builder (DB::table), not
 * the Eloquent model classes — a data-migration must stay correct against
 * the schema as it existed at this point in history, independent of how
 * the models evolve afterward.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('hr_document_templates')->orderBy('id')->cursor()->each(function ($template): void {
            $versionId = (string) Str::ulid();

            DB::table('hr_document_template_versions')->insert([
                'id' => $versionId,
                'tenant_id' => $template->tenant_id,
                'hr_document_template_id' => $template->id,
                'version_number' => 1,
                'content_template' => $template->content_template,
                'status' => 'published',
                'published_by' => null,
                'published_at' => $template->updated_at,
                'created_by' => $template->created_by,
                'updated_by' => $template->updated_by,
                'created_at' => $template->created_at,
                'updated_at' => $template->updated_at,
            ]);

            DB::table('hr_document_templates')->where('id', $template->id)
                ->update(['current_version_id' => $versionId]);

            DB::table('hr_generated_documents')->where('hr_document_template_id', $template->id)
                ->update(['hr_document_template_version_id' => $versionId]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('hr_generated_documents')->update(['hr_document_template_version_id' => null]);
        DB::table('hr_document_templates')->update(['current_version_id' => null]);
        DB::table('hr_document_template_versions')->delete();
    }
};
