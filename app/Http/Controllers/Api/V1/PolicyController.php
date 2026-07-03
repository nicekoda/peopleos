<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AcknowledgementMethod;
use App\Enums\AcknowledgementStatus;
use App\Enums\PolicyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Policy\AcknowledgePolicyRequest;
use App\Http\Requests\Policy\AssignPolicyRequest;
use App\Http\Requests\Policy\PublishPolicyRequest;
use App\Http\Requests\Policy\StorePolicyRequest;
use App\Http\Requests\Policy\StorePolicyVersionRequest;
use App\Http\Requests\Policy\UpdatePolicyRequest;
use App\Http\Resources\PolicyAcknowledgementResource;
use App\Http\Resources\PolicyResource;
use App\Http\Resources\PolicyVersionResource;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyVersion;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PolicyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $policies = Policy::query()->orderBy('title')->paginate();

        return PolicyResource::collection($policies);
    }

    public function store(StorePolicyRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['status'] ??= PolicyStatus::Draft->value;
        $validated['tenant_id'] = app(Tenant::class)->id;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $policy = Policy::query()->create($validated);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'policy.created',
            module: 'policies',
            tenantId: $policy->tenant_id,
            auditableType: Policy::class,
            auditableId: $policy->id,
            description: "Policy '{$policy->title}' created.",
            newValues: $policy->only(['title', 'slug', 'code', 'category', 'status']),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new PolicyResource($policy))->response()->setStatusCode(201);
    }

    public function show(Request $request, Policy $policy): PolicyResource
    {
        $this->ensureBelongsToCurrentTenant($policy);

        return new PolicyResource($policy);
    }

    public function update(UpdatePolicyRequest $request, Policy $policy): PolicyResource
    {
        $this->ensureBelongsToCurrentTenant($policy);

        $validated = $request->validated();

        if (($validated['status'] ?? null) === PolicyStatus::Archived->value) {
            abort_unless($request->user()->hasPermission('policies.archive'), 403);
        }

        $originalValues = $policy->getOriginal();

        $policy->fill($validated);
        $policy->updated_by = $request->user()->id;
        $policy->save();

        $changes = $policy->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            $action = ($changes['status'] ?? null) === PolicyStatus::Archived->value
                ? 'policy.archived'
                : 'policy.updated';

            AuditLogger::logFor(
                actor: $request->user(),
                action: $action,
                module: 'policies',
                tenantId: $policy->tenant_id,
                auditableType: Policy::class,
                auditableId: $policy->id,
                description: "Policy '{$policy->title}' updated.",
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new PolicyResource($policy);
    }

    public function storeVersion(StorePolicyVersionRequest $request, Policy $policy): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($policy);

        $nextVersionNumber = (int) $policy->versions()->max('version_number') + 1;

        $version = PolicyVersion::query()->create([
            'tenant_id' => $policy->tenant_id,
            'policy_id' => $policy->id,
            'version_number' => $nextVersionNumber,
            'title' => $request->validated('title'),
            'summary' => $request->validated('summary'),
            'content' => $request->validated('content'),
            'employee_document_id' => $request->validated('employee_document_id'),
            'status' => PolicyStatus::Draft,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'policy.version_created',
            module: 'policies',
            tenantId: $policy->tenant_id,
            auditableType: PolicyVersion::class,
            auditableId: $version->id,
            description: "Version {$version->version_number} created for policy '{$policy->title}'.",
            metadata: ['policy_id' => $policy->id, 'version_number' => $version->version_number],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new PolicyVersionResource($version))->response()->setStatusCode(201);
    }

    public function publish(PublishPolicyRequest $request, Policy $policy): PolicyResource
    {
        $this->ensureBelongsToCurrentTenant($policy);

        /** @var PolicyVersion $version */
        $version = PolicyVersion::query()->findOrFail($request->validated('policy_version_id'));

        // Old published versions remain available for history — archived,
        // never deleted.
        PolicyVersion::query()
            ->where('policy_id', $policy->id)
            ->where('status', PolicyStatus::Published)
            ->where('id', '!=', $version->id)
            ->update(['status' => PolicyStatus::Archived]);

        $version->update([
            'status' => PolicyStatus::Published,
            'published_by' => $request->user()->id,
            'published_at' => now(),
        ]);

        $policy->update([
            'status' => PolicyStatus::Published,
            'current_version_id' => $version->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'policy.published',
            module: 'policies',
            tenantId: $policy->tenant_id,
            auditableType: Policy::class,
            auditableId: $policy->id,
            description: "Policy '{$policy->title}' published (version {$version->version_number}).",
            metadata: ['policy_version_id' => $version->id, 'version_number' => $version->version_number],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new PolicyResource($policy->fresh());
    }

    /**
     * Only a published policy (one with a current_version_id) can be
     * assigned — assigning a draft would mean asking someone to
     * acknowledge content that isn't final yet.
     */
    public function assign(AssignPolicyRequest $request, Policy $policy): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($policy);

        abort_unless($policy->current_version_id, 422, 'This policy has no published version to assign.');

        $created = [];
        $skipped = [];

        foreach ($request->validated('employee_ids') as $employeeId) {
            $exists = PolicyAcknowledgement::query()
                ->where('policy_version_id', $policy->current_version_id)
                ->where('employee_id', $employeeId)
                ->exists();

            if ($exists) {
                $skipped[] = $employeeId;

                continue;
            }

            $acknowledgement = PolicyAcknowledgement::query()->create([
                'tenant_id' => $policy->tenant_id,
                'policy_id' => $policy->id,
                'policy_version_id' => $policy->current_version_id,
                'employee_id' => $employeeId,
                'assigned_by' => $request->user()->id,
                'assigned_at' => now(),
                'due_date' => $request->validated('due_date'),
                'acknowledgement_status' => AcknowledgementStatus::Pending,
            ]);

            $created[] = $acknowledgement->id;

            AuditLogger::logFor(
                actor: $request->user(),
                action: 'policy.assigned',
                module: 'policies',
                tenantId: $policy->tenant_id,
                auditableType: PolicyAcknowledgement::class,
                auditableId: $acknowledgement->id,
                targetUserId: null,
                description: "Policy '{$policy->title}' assigned to employee #{$employeeId}.",
                metadata: ['policy_id' => $policy->id, 'employee_id' => $employeeId, 'due_date' => $request->validated('due_date')],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return response()->json(['created' => $created, 'skipped_duplicates' => $skipped], 201);
    }

    public function acknowledgements(Request $request, Policy $policy): AnonymousResourceCollection
    {
        $this->ensureBelongsToCurrentTenant($policy);

        $acknowledgements = $policy->acknowledgements()->orderByDesc('assigned_at')->paginate();

        return PolicyAcknowledgementResource::collection($acknowledgements);
    }

    /**
     * Two paths, both safe (Checkpoint 11):
     *
     * 1. Self-acknowledgement: employee_id omitted (or explicitly equals
     *    the caller's own linked employee). Always allowed with just
     *    policies.acknowledge — this is what makes it safe to grant that
     *    permission to the Employee role now.
     * 2. Admin-recorded on behalf of another employee: employee_id
     *    explicitly differs from the caller's own link (or the caller has
     *    no link at all). Requires policies.assign in addition — reusing
     *    the existing "trusted with assignments" permission rather than
     *    inventing a new one. Employee-role users never hold
     *    policies.assign, so they can never resolve to anyone but
     *    themselves, regardless of what employee_id they submit.
     *
     * See docs/security.md for the full reasoning.
     */
    public function acknowledge(AcknowledgePolicyRequest $request, Policy $policy): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($policy);

        $user = $request->user();
        $ownEmployeeId = $user->employee?->id;
        $requestedEmployeeId = $request->validated('employee_id') ?? $ownEmployeeId;

        abort_if(
            $requestedEmployeeId === null,
            422,
            'You have no linked employee record. Specify employee_id to record on behalf of someone else (requires policies.assign).',
        );

        $isSelfAcknowledgement = $requestedEmployeeId === $ownEmployeeId;

        if (! $isSelfAcknowledgement) {
            abort_unless(
                $user->hasPermission('policies.assign'),
                403,
                'You are not authorized to record acknowledgement on behalf of another employee.',
            );
        }

        $acknowledgement = PolicyAcknowledgement::query()
            ->where('policy_id', $policy->id)
            ->where('employee_id', $requestedEmployeeId)
            ->where('acknowledgement_status', AcknowledgementStatus::Pending)
            ->first();

        abort_if($acknowledgement === null, 404, 'No pending acknowledgement found for this employee and policy.');

        // The policy may have been republished since this employee was
        // assigned — only the current version can be acknowledged.
        abort_unless(
            $acknowledgement->policy_version_id === $policy->current_version_id,
            409,
            'This assignment refers to an outdated policy version. Reassignment is required.',
        );

        $method = $isSelfAcknowledgement ? AcknowledgementMethod::Web : AcknowledgementMethod::AdminRecorded;

        $acknowledgement->update([
            'acknowledged_at' => now(),
            'acknowledgement_status' => AcknowledgementStatus::Acknowledged,
            'acknowledgement_method' => $method,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        AuditLogger::logFor(
            actor: $user,
            action: 'policy.acknowledged',
            module: 'policies',
            tenantId: $policy->tenant_id,
            auditableType: PolicyAcknowledgement::class,
            auditableId: $acknowledgement->id,
            description: "Policy '{$policy->title}' acknowledged for employee #{$requestedEmployeeId}.",
            metadata: ['policy_id' => $policy->id, 'employee_id' => $requestedEmployeeId, 'method' => $method->value],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new PolicyAcknowledgementResource($acknowledgement))->response()->setStatusCode(200);
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403: don't
     * reveal that a record exists in another tenant.
     */
    protected function ensureBelongsToCurrentTenant(Policy $policy): void
    {
        abort_unless($policy->tenant_id === app(Tenant::class)->id, 404);
    }
}
