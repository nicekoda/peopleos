<?php

namespace App\Enums;

/**
 * Checkpoint 47 — the single backend-defined module registry. Every
 * module key that can ever reach a controller, a permission grant, or a
 * route's `module:{key}` middleware parameter must be a case here —
 * nothing accepts a free-text module key from the frontend anywhere in
 * this app (see TenantModuleController, which rejects unknown/core keys
 * with a 422, not a route-model-binding 404).
 *
 * Deliberately an enum with behavior (routeGroupPrefixes(),
 * additionalGatedUris(), etc.) rather than a config array, matching the
 * "enum with behavior" pattern already used throughout this app
 * (ApplicationStage::canTransitionTo(), RecruitmentJobStatus, ...) —
 * testable, and a missing `match()` arm is a compile-time-visible bug,
 * not a silently-absent array key.
 */
enum TenantModule: string
{
    // Core — never tenant-toggleable. Listed here (not just "everything
    // not in toggleable()") so isToggleable() has one authoritative
    // source of truth per case, not an inferred default.
    case Employees = 'employees';
    case Settings = 'settings';
    case UsersAccess = 'users_access';
    case AuditLogs = 'audit_logs';
    case Dashboard = 'dashboard';
    case ManagerHierarchy = 'manager_hierarchy';
    case PasswordReset = 'password_reset';
    case AccountInvites = 'account_invites';

    // Toggleable — MVP set (Checkpoint 47).
    case Recruitment = 'recruitment';
    case Lifecycle = 'lifecycle';
    case Leave = 'leave';
    case Documents = 'documents';
    case Policies = 'policies';
    case HrDocuments = 'hr_documents';

    public function label(): string
    {
        return match ($this) {
            self::Employees => 'Employees',
            self::Settings => 'Settings',
            self::UsersAccess => 'Users & Access',
            self::AuditLogs => 'Audit Logs',
            self::Dashboard => 'Dashboard',
            self::ManagerHierarchy => 'Manager Hierarchy',
            self::PasswordReset => 'Password Reset',
            self::AccountInvites => 'Account Invites',
            self::Recruitment => 'Recruitment & Applicant Tracking',
            self::Lifecycle => 'Onboarding & Offboarding',
            self::Leave => 'Leave Management',
            self::Documents => 'Document Repository',
            self::Policies => 'Policy Management',
            self::HrDocuments => 'HR Documents & Letter Generation',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Employees => 'Employee records — always available.',
            self::Settings => 'Tenant configuration — always available.',
            self::UsersAccess => 'User accounts and role assignment — always available.',
            self::AuditLogs => 'Security and activity audit trail — always available.',
            self::Dashboard => 'Tenant summary dashboard — always available.',
            self::ManagerHierarchy => 'Manager/direct-report relationships — always available.',
            self::PasswordReset => 'Forgot-password and invite-token flow — always available.',
            self::AccountInvites => 'Invite-based user account creation — always available.',
            self::Recruitment => 'Job openings, applicants, and the hiring pipeline.',
            self::Lifecycle => 'Onboarding/offboarding processes and tasks.',
            self::Leave => 'Leave types, requests, approval, and balances.',
            self::Documents => 'Employee document uploads and categories.',
            self::Policies => 'Policy authoring, publishing, and acknowledgement.',
            self::HrDocuments => 'HR letter templates and generated documents.',
        };
    }

    public function isToggleable(): bool
    {
        return in_array($this, self::toggleable(), true);
    }

    /**
     * @return list<self>
     */
    public static function toggleable(): array
    {
        return [
            self::Recruitment,
            self::Lifecycle,
            self::Leave,
            self::Documents,
            self::Policies,
            self::HrDocuments,
        ];
    }

    /**
     * @return list<self>
     */
    public static function core(): array
    {
        return [
            self::Employees,
            self::Settings,
            self::UsersAccess,
            self::AuditLogs,
            self::Dashboard,
            self::ManagerHierarchy,
            self::PasswordReset,
            self::AccountInvites,
        ];
    }

    /**
     * Route URIs (as registered — `{param}` placeholders intact) that
     * this module's routes start with, in both routes/api.php and
     * routes/web.php. Only meaningful for toggleable modules; used by
     * both `route:audit-module-gates` and documentation — not by the
     * middleware itself (which is applied explicitly, per-route, same
     * as `permission:`).
     *
     * @return list<string>
     */
    public function routeGroupPrefixes(): array
    {
        return match ($this) {
            self::Recruitment => ['job-openings', 'job-applications', 'recruitment'],
            self::Lifecycle => ['lifecycle-processes', 'lifecycle-tasks', 'lifecycle', 'settings/lifecycle-task-templates'],
            self::Leave => ['leave-types', 'leave-requests', 'leave-balances', 'leave', 'settings/leave-types'],
            self::Documents => ['employees/{employee}/documents', 'document-categories', 'documents', 'settings/document-categories'],
            self::Policies => ['policies'],
            self::HrDocuments => ['hr-document-templates', 'hr-document-template-versions', 'hr-generated-documents', 'hr-documents', 'settings/hr-document-templates', 'settings/hr-document-template-versions'],
            default => [],
        };
    }

    /**
     * Exact route URIs that belong to a *different* module's own route
     * group (by prefix) but must additionally be gated by this module
     * too — e.g. the recruitment-to-onboarding handoff endpoint lives
     * under `job-applications/...` (Recruitment's prefix) but must also
     * be blocked when Lifecycle is disabled (Checkpoint 41's own
     * requirement), and the self-service leave balance summary lives
     * under `me/...`, not `leave-balances/...`.
     *
     * @return list<string>
     */
    public function additionalGatedUris(): array
    {
        return match ($this) {
            self::Lifecycle => ['job-applications/{jobApplication}/start-onboarding'],
            self::Leave => ['me/leave-balances'],
            default => [],
        };
    }

    /**
     * Other toggleable modules this one has a soft relationship with,
     * surfaced to the UI as "related_modules" (Checkpoint 47) — purely
     * informational this checkpoint, no dependency is actually enforced
     * (e.g. disabling Recruitment doesn't force-disable Lifecycle) —
     * see docs/architecture.md's future-scope notes.
     *
     * @return list<self>
     */
    public function relatedModules(): array
    {
        return match ($this) {
            self::Recruitment => [self::Lifecycle],
            self::Lifecycle => [self::Recruitment],
            default => [],
        };
    }
}
