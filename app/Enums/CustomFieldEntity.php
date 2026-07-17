<?php

namespace App\Enums;

use App\Models\RecruitmentApplicant;

/**
 * Checkpoint 48 — the backend-defined allowlist of entities that support
 * custom fields. Nothing accepts a free-text entity type from the
 * frontend anywhere in this app (same posture as TenantModule) —
 * `CustomFieldDefinitionController` resolves this via `tryFrom()` and
 * returns 422 on an unknown value, never a route-model-binding 404.
 *
 * MVP ships exactly one case (RecruitmentApplicant) per the approved
 * checkpoint scope — Employees is deliberately deferred until the engine
 * has real field experience elsewhere (see docs/architecture.md).
 */
enum CustomFieldEntity: string
{
    case RecruitmentApplicant = 'recruitment_applicant';

    public function label(): string
    {
        return match ($this) {
            self::RecruitmentApplicant => 'Recruitment Applicant',
        };
    }

    /**
     * @return class-string
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::RecruitmentApplicant => RecruitmentApplicant::class,
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
            self::RecruitmentApplicant => 'job_applications.view',
        };
    }

    /**
     * The permission that already gates *writing* this entity through its
     * own controller — reused as-is for writing its custom field values.
     */
    public function valueUpdatePermission(): string
    {
        return match ($this) {
            self::RecruitmentApplicant => 'job_applications.update',
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
            // a tenant admin's point of view, and job_applications (the
            // next entity in the roadmap) will need them reserved anyway.
            self::RecruitmentApplicant => [
                'id', 'tenant_id', 'first_name', 'last_name', 'email', 'phone', 'source',
                'created_at', 'updated_at', 'created_by', 'updated_by', 'deleted_at',
                'status', 'stage',
            ],
        };

        return [...$entitySpecific, ...$shared];
    }
}
