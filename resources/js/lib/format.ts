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
