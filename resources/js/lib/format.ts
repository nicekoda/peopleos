/**
 * Neither LeaveRequestResource nor EmployeeResource returns an employee
 * *name* alongside a leave request — only the raw employee_id
 * (Checkpoint 18, Refinement 1). Rather than display that ID
 * prominently as if it were a finished design, this renders "You" for
 * the viewer's own requests (comparing against auth.user.employee_id,
 * already safely shared) and a deliberately subtle, truncated
 * placeholder otherwise. Future work: an employee name/summary field on
 * the leave API — see docs/security.md.
 */
export function formatEmployeeRef(employeeId: string, viewerEmployeeId: string | null): string {
    if (viewerEmployeeId && employeeId === viewerEmployeeId) {
        return 'You';
    }

    return `Employee record (ID ending •••${employeeId.slice(-4)})`;
}

/**
 * AuditLogResource returns only actor_user_id/actor_type (Checkpoint
 * 24, Refinement 7) — never a name, since resolving one server-side
 * would mean adding a new join the audit endpoint doesn't need for
 * anything else. Name resolution happens client-side instead, from a
 * lookup map built off the existing, already-tested, tenant-scoped
 * GET /api/v1/users (Checkpoint 23) — never a new backend call, never
 * a cross-tenant lookup. Falls back to "System" for system-actor
 * entries, or a truncated numeric reference if a user can't be
 * resolved (e.g. since soft-deleted, so absent from that list).
 */
export function formatActorRef(userId: number | null, usersById: Map<number, string>): string {
    if (userId === null) {
        return 'System';
    }

    return usersById.get(userId) ?? `User #${userId}`;
}
