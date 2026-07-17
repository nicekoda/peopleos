<?php

namespace App\Enums;

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
 * generalizes across entities without a storage redesign. Employees is
 * still deliberately deferred until the engine has more field
 * experience and field-level visibility exists (see docs/architecture.md).
 */
enum CustomFieldEntity: string
{
    case RecruitmentApplicant = 'recruitment_applicant';
    case JobApplication = 'job_application';

    public function label(): string
    {
        return match ($this) {
            self::RecruitmentApplicant => 'Recruitment Applicant',
            self::JobApplication => 'Job Application',
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
        };

        return [...$entitySpecific, ...$shared];
    }
}
