<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PositionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Position\StorePositionRequest;
use App\Http\Requests\Position\UpdatePositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\Position;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Checkpoint 32 — Position already uses BelongsToTenant (Checkpoint
 * 26), the standard two-layer tenant pattern. Same shape as
 * DepartmentController — see docs/architecture.md.
 */
class PositionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $positions = Position::query()->orderBy('name')->paginate();

        return PositionResource::collection($positions);
    }

    public function store(StorePositionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['tenant_id'] = app(Tenant::class)->id;
        $validated['slug'] = $this->uniqueSlugFor($validated['name'], $validated['tenant_id']);
        $validated['status'] = PositionStatus::Active->value;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $position = Position::query()->create($validated);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'position.created',
            module: 'employees',
            tenantId: $position->tenant_id,
            auditableType: Position::class,
            auditableId: $position->id,
            description: "Position '{$position->name}' created.",
            newValues: $position->only(['name', 'slug', 'description', 'status']),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new PositionResource($position))->response()->setStatusCode(201);
    }

    public function show(Request $request, Position $position): PositionResource
    {
        $this->ensureBelongsToCurrentTenant($position);

        return new PositionResource($position);
    }

    public function update(UpdatePositionRequest $request, Position $position): PositionResource
    {
        $this->ensureBelongsToCurrentTenant($position);

        $originalValues = $position->getOriginal();

        $position->fill($request->validated());
        $position->updated_by = $request->user()->id;
        $position->save();

        $changes = $position->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'position.updated',
                module: 'employees',
                tenantId: $position->tenant_id,
                auditableType: Position::class,
                auditableId: $position->id,
                description: "Position '{$position->name}' updated.",
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new PositionResource($position);
    }

    /**
     * Soft delete only — see DepartmentController::destroy() for the
     * full reasoning, identical here.
     */
    public function destroy(Request $request, Position $position): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($position);

        $snapshot = $position->only(['name', 'slug', 'status']);

        $position->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'position.archived',
            module: 'employees',
            tenantId: $position->tenant_id,
            auditableType: Position::class,
            auditableId: $position->id,
            description: "Position '{$position->name}' archived.",
            oldValues: $snapshot,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Position archived.']);
    }

    private function uniqueSlugFor(string $name, string $tenantId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Position::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403:
     * don't reveal that a record exists in another tenant.
     */
    protected function ensureBelongsToCurrentTenant(Position $position): void
    {
        abort_unless($position->tenant_id === app(Tenant::class)->id, 404);
    }
}
