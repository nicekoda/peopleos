<?php

namespace App\Enums;

use App\Models\Employee;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;

/**
 * Checkpoint 48 — the backend-defined allowlist of entities that support
 * custom fields. Nothing accepts a free-text entity type from the
 * frontend anywhere in this app (same posture as TenantModule) —
 * `CustomFieldDefinitionController` resolves this via `tryFrom()` and
 * returns 422 on an unknown value, never a route-model-binding 404.
 *
 * Checkpoint 49 added `JobApplication` (App\Models\RecruitmentApplication
 * — the pipeline/stage record, distinct from RecruitmentApplicant, the
 * candidate's identity) as entity #2, adding no changes to any
 * migration, model cast, or custom-field service — proof the engine
 * generalizes across entities without a storage redesign. Checkpoint 51
 * added `Employee` as entity #3, the same proof a third time, on top of
 * the field-level visibility model Checkpoint 50 introduced.
 */
enum CustomFieldEntity: string
{
    case RecruitmentApplicant = 'recruitment_applicant';
    case JobApplication = 'job_application';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::RecruitmentApplicant => 'Recruitment Applicant',
            self::JobApplication => 'Job Application',
            self::Employee => 'Employee',
        };
    }

    /**
     * @return class-string
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::RecruitmentApplicant => RecruitmentApplicant::class,
            self::JobApplication => RecruitmentApplication::class,
            self::Employee => Employee::class,
        };
    }

    /**
     * The permission that already gates *reading* this entity through its
     * own controller — reused as-is for reading its custom field values.
     * No second, independent value-permission system exists in this
     * checkpoint (your explicit approved decision).
     */
    public function valueViewPermission(): string
    {
        return match ($this) {
            self::RecruitmentApplicant, self::JobApplication => 'job_applications.view',
            self::Employee => 'employees.view',
        };
    }

    /**
     * The permission that already gates *writing* this entity through its
     * own controller — reused as-is for writing its custom field values.
     */
    public function valueUpdatePermission(): string
    {
        return match ($this) {
            self::RecruitmentApplicant, self::JobApplication => 'job_applications.update',
            self::Employee => 'employees.update',
        };
    }

    /**
     * Checkpoint 51 — which toggleable module (if any) must be enabled
     * for this entity's custom-field definitions/values to be reachable
     * at all. Null means the entity belongs to a core, never-toggleable
     * module (Employees) and is therefore never module-gated. This is
     * the single source of truth `CustomFieldDefinitionController` checks
     * at runtime — replaces the old hardcoded `module:recruitment` route
     * middleware, which incorrectly would have blocked Employee custom
     * fields behind the Recruitment module once a non-Recruitment entity
     * existed (found and fixed in Checkpoint 51 — see docs/architecture.md).
     * A future entity belonging to a different toggleable module
     * (`lifecycle_processes` -> Lifecycle, `leave_requests` -> Leave)
     * declares its own requirement through this same method — no further
     * route-layer redesign needed.
     */
    public function requiredModule(): ?TenantModule
    {
        return match ($this) {
            self::RecruitmentApplicant, self::JobApplication => TenantModule::Recruitment,
            self::Employee => null,
        };
    }

    /**
     * Real column names / dangerous generic names a tenant must never be
     * able to shadow with a custom field key (Checkpoint 48, decision 8).
     * Per-entity real columns plus a shared conservative "dangerous name"
     * set reused across every entity case.
     *
     * @return list<string>
     */
    public function reservedFieldKeys(): array
    {
        $shared = [
            'password', 'token', 'api_key', 'secret',
            'role', 'permission', 'is_admin', 'is_platform_admin',
        ];

        $entitySpecific = match ($this) {
            // first_name/last_name/email/phone/source are RecruitmentApplicant's
            // own columns; status/stage belong to the sibling
            // RecruitmentApplication row but are reserved here too since
            // both are conceptually "this applicant's pipeline state" from
            // a tenant admin's point of view.
            self::RecruitmentApplicant => [
                'id', 'tenant_id', 'first_name', 'last_name', 'email', 'phone', 'source',
                'created_at', 'updated_at', 'created_by', 'updated_by', 'deleted_at',
                'status', 'stage',
            ],
            // RecruitmentApplication's own real columns (Checkpoint 49).
            self::JobApplication => [
                'id', 'tenant_id', 'recruitment_job_id', 'recruitment_applicant_id',
                'stage', 'status', 'resume_document_id', 'cover_letter', 'ready_for_conversion',
                'converted_employee_id', 'converted_at', 'converted_by', 'onboarding_process_id',
                'created_at', 'updated_at', 'created_by', 'updated_by', 'deleted_at',
            ],
            // Employee's own real columns (Checkpoint 51), plus the
            // relation names (department/location/position/manager, not
            // just their _id columns) a tenant must not be able to shadow
            // either — a custom field literally named "department" would
            // read confusingly next to the real nested department object
            // EmployeeResource already returns.
            self::Employee => [
                'id', 'tenant_id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'preferred_name',
                'work_email', 'personal_email', 'phone', 'status', 'employment_type',
                'department_id', 'location_id', 'position_id', 'manager_employee_id',
                'start_date', 'probation_end_date', 'confirmation_date',
                'user_id', 'linked_at', 'linked_by',
                'created_at', 'updated_at', 'created_by', 'updated_by', 'deleted_at',
                'department', 'location', 'position', 'manager',
            ],
        };

        return [...$entitySpecific, ...$shared];
    }
}
