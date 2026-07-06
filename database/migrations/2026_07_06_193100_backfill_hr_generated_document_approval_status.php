<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint 37 — every existing hr_generated_documents row with the
 * old status value `generated` is treated as already-finalized: it maps
 * to `approved`, with `approved_at`/`approved_by` backfilled from the
 * document's own `generated_at`/`generated_by` — the closest accurate
 * proxy available (we don't know who "approved" it under the old
 * content-only model, but the person who generated it is the only real
 * actor on record). `submitted_at`/`submitted_by` stay null — these
 * documents were never actually submitted through an approval flow that
 * didn't exist yet. Uses the query builder, not the Eloquent model
 * classes — same reasoning as Checkpoint 36's template-version backfill:
 * a data migration must stay correct against the schema/enum as it
 * existed at this point in history, independent of how the models
 * evolve afterward.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('hr_generated_documents')
            ->where('status', 'generated')
            ->update([
                'status' => 'approved',
                'approved_at' => DB::raw('generated_at'),
                'approved_by' => DB::raw('generated_by'),
            ]);
    }

    /**
     * Reverse the migrations. Best-effort: only reverts rows whose
     * approved_at/approved_by still exactly match generated_at/generated_by
     * (the signature this migration itself wrote) — a row genuinely
     * approved later, or approved by someone other than the generator,
     * is left alone rather than incorrectly reset.
     */
    public function down(): void
    {
        DB::table('hr_generated_documents')
            ->where('status', 'approved')
            ->whereColumn('approved_at', 'generated_at')
            ->whereColumn('approved_by', 'generated_by')
            ->update([
                'status' => 'generated',
                'approved_at' => null,
                'approved_by' => null,
            ]);
    }
};
