<?php

namespace App\Services;

use App\Enums\ApplicationStage;
use App\Enums\ApplicationStatus;
use App\Enums\HrGeneratedDocumentStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\LifecycleProcessStatus;
use App\Enums\TenantModule;
use App\Models\HrGeneratedDocument;
use App\Models\LeaveRequest;
use App\Models\LifecycleProcess;
use App\Models\RecruitmentApplication;
use App\Models\Tenant;
use App\Models\TenantModuleAssignment;
use App\Models\User;

/**
 * The single place that answers "is module X enabled for this tenant"
 * and performs the toggle write — mirrors LifecycleVisibilityService's
 * "one reusable place, not re-derived per controller/middleware" shape.
 *
 * Missing-row-means-enabled is a fallback only, per your approved
 * design — the intended steady state is an explicit row for every
 * toggleable module on every tenant, created by provisionDefaults()
 * (called from Tenant's own `created` hook) and backfilled for
 * pre-existing tenants by the tenant_modules migration itself.
 */
class TenantModuleService
{
    public function isEnabled(TenantModule $module): bool
    {
        if (! $module->isToggleable()) {
            return true;
        }

        $row = TenantModuleAssignment::query()->where('module_key', $module->value)->first();

        return $row === null ? true : $row->enabled;
    }

    /**
     * @return array<string, bool>
     */
    public function enabledMap(): array
    {
        $rows = TenantModuleAssignment::query()->get()->keyBy(fn (TenantModuleAssignment $row) => $row->module_key->value);

        $map = [];
        foreach (TenantModule::toggleable() as $module) {
            $row = $rows->get($module->value);
            $map[$module->value] = $row === null ? true : $row->enabled;
        }

        return $map;
    }

    public function setEnabled(TenantModule $module, bool $enabled, User $actor): TenantModuleAssignment
    {
        $tenantId = app(Tenant::class)->id;

        $row = TenantModuleAssignment::query()->firstOrNew([
            'tenant_id' => $tenantId,
            'module_key' => $module->value,
        ]);
        $row->tenant_id = $tenantId;
        $row->module_key = $module->value;
        $row->enabled = $enabled;

        if ($enabled) {
            $row->enabled_by = $actor->id;
            $row->enabled_at = now();
        } else {
            $row->disabled_by = $actor->id;
            $row->disabled_at = now();
        }

        $row->save();

        return $row;
    }

    /**
     * Called from Tenant's own `created` hook — every new tenant
     * (whether via TenantSeeder or a future real provisioning flow)
     * gets an explicit enabled row for every toggleable module
     * immediately, so "missing row" never becomes the normal case going
     * forward. Idempotent (firstOrCreate), safe to call more than once.
     */
    public function provisionDefaults(Tenant $tenant): void
    {
        foreach (TenantModule::toggleable() as $module) {
            TenantModuleAssignment::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_key' => $module->value],
                ['enabled' => true],
            );
        }
    }

    /**
     * Safe, lightweight, aggregate-only counts surfaced alongside module
     * state (Checkpoint 47) — tenant-scoped via each model's own
     * BelongsToTenant global scope, never a raw record list, never a
     * name/applicant/employee detail. Documents/Policies are
     * deliberately omitted (no natural "pending" concept was specified
     * and forcing one would be scope creep — see docs/security.md).
     *
     * @return array<string, int>
     */
    public function warningCounts(): array
    {
        return [
            TenantModule::Recruitment->value => RecruitmentApplication::query()
                ->where('status', ApplicationStatus::Active->value)
                ->whereNotIn('stage', [ApplicationStage::Hired->value, ApplicationStage::Rejected->value, ApplicationStage::Withdrawn->value])
                ->count(),
            TenantModule::Leave->value => LeaveRequest::query()
                ->where('status', LeaveRequestStatus::Pending->value)
                ->count(),
            TenantModule::Lifecycle->value => LifecycleProcess::query()
                ->where('status', LifecycleProcessStatus::InProgress->value)
                ->count(),
            TenantModule::HrDocuments->value => HrGeneratedDocument::query()
                ->where('status', HrGeneratedDocumentStatus::PendingApproval->value)
                ->count(),
        ];
    }
}
