<?php

namespace App\Enums;

/**
 * Deliberately separate from ApplicationStage — status is "is this
 * application still a live record" (active/archived), stage is "where is
 * it in the pipeline" (applied/screening/.../hired). Same split
 * LifecycleProcess uses between its own status and a soft-delete-driven
 * archive, except here archiving needs an explicit flag because a
 * terminal *stage* (hired/rejected/withdrawn) doesn't itself mean the
 * record should disappear from the active list — see JobApplicationController.
 */
enum ApplicationStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
