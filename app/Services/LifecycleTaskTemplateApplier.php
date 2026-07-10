<?php

namespace App\Services;

use App\Enums\LifecycleTaskStatus;
use App\Models\LifecycleProcess;
use App\Models\LifecycleTask;
use App\Models\LifecycleTaskTemplate;

/**
 * Checkpoint 42 — Onboarding & Offboarding Task Templates Foundation.
 * Copies every active (non-archived) template matching a newly created
 * process's own tenant + type into real LifecycleTask rows, so starting
 * a process no longer leaves it completely bare. Deliberately the only
 * thing this does: no assignment (a template can't know who should get
 * a task), no notifications, no due-date recompute after the fact if a
 * template changes later — every generated task is fully independent of
 * its template from the moment it's created, the same "generate once,
 * then independent" posture HR Documents already established for
 * rendered content vs. template content. See docs/architecture.md.
 */
class LifecycleTaskTemplateApplier
{
    public static function applyToProcess(LifecycleProcess $process, int $actorUserId): void
    {
        $templates = LifecycleTaskTemplate::query()
            ->where('tenant_id', $process->tenant_id)
            ->where('type', $process->type->value)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        foreach ($templates as $template) {
            LifecycleTask::query()->create([
                'tenant_id' => $process->tenant_id,
                'process_id' => $process->id,
                'title' => $template->title,
                'description' => $template->description,
                'status' => LifecycleTaskStatus::Pending->value,
                'due_date' => $template->due_in_days !== null
                    ? now()->addDays($template->due_in_days)->toDateString()
                    : null,
                // Checkpoint 45 — copied once, then independent, same
                // "generate once" posture as title/description/due_date
                // above: editing a template's sort_order afterward never
                // reaches back into tasks already generated from it.
                'sort_order' => $template->sort_order,
                'created_by' => $actorUserId,
                'updated_by' => $actorUserId,
            ]);
        }
    }
}
