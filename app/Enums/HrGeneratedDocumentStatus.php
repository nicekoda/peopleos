<?php

namespace App\Enums;

/**
 * Checkpoint 37 — HR Document Approval Workflow Foundation. Replaces the
 * Checkpoint 34 draft/generated/archived shape (where `draft` was never
 * actually reachable) with a real, centrally-guarded flow.
 */
enum HrGeneratedDocumentStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Archived = 'archived';

    /**
     * The single source of truth for valid status transitions — checked
     * server-side on every submit/approve/reject/archive action, not
     * inferred from which endpoint was called. Mirrors
     * LifecycleProcessStatus::allowedNextStates(). `archived` is terminal;
     * `approved` transitions only to `archived` (never editable, never
     * resubmittable — per the approved Checkpoint 37 design). Archiving
     * itself is allowed from every non-terminal status, including
     * `pending_approval` — unconditional archiving matches this
     * controller's pre-Checkpoint-37 behavior (destroy() had no status
     * guard at all) and avoids a new blocking rule nobody asked for.
     *
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Archived],
            self::PendingApproval => [self::Approved, self::Rejected, self::Archived],
            self::Rejected => [self::PendingApproval, self::Archived],
            self::Approved => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNextStates(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Archived;
    }
}
