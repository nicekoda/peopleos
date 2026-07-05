<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LocationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Location\StoreLocationRequest;
use App\Http\Requests\Location\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Checkpoint 32 — Location already uses BelongsToTenant (Checkpoint
 * 26), the standard two-layer tenant pattern. Same shape as
 * DepartmentController — see docs/architecture.md.
 */
class LocationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $locations = Location::query()->orderBy('name')->paginate();

        return LocationResource::collection($locations);
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['tenant_id'] = app(Tenant::class)->id;
        $validated['slug'] = $this->uniqueSlugFor($validated['name'], $validated['tenant_id']);
        $validated['status'] = LocationStatus::Active->value;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $location = Location::query()->create($validated);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'location.created',
            module: 'employees',
            tenantId: $location->tenant_id,
            auditableType: Location::class,
            auditableId: $location->id,
            description: "Location '{$location->name}' created.",
            newValues: $location->only(['name', 'slug', 'description', 'status']),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new LocationResource($location))->response()->setStatusCode(201);
    }

    public function show(Request $request, Location $location): LocationResource
    {
        $this->ensureBelongsToCurrentTenant($location);

        return new LocationResource($location);
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        $this->ensureBelongsToCurrentTenant($location);

        $originalValues = $location->getOriginal();

        $location->fill($request->validated());
        $location->updated_by = $request->user()->id;
        $location->save();

        $changes = $location->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'location.updated',
                module: 'employees',
                tenantId: $location->tenant_id,
                auditableType: Location::class,
                auditableId: $location->id,
                description: "Location '{$location->name}' updated.",
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new LocationResource($location);
    }

    /**
     * Soft delete only — see DepartmentController::destroy() for the
     * full reasoning, identical here.
     */
    public function destroy(Request $request, Location $location): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($location);

        $snapshot = $location->only(['name', 'slug', 'status']);

        $location->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'location.archived',
            module: 'employees',
            tenantId: $location->tenant_id,
            auditableType: Location::class,
            auditableId: $location->id,
            description: "Location '{$location->name}' archived.",
            oldValues: $snapshot,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Location archived.']);
    }

    private function uniqueSlugFor(string $name, string $tenantId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Location::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
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
    protected function ensureBelongsToCurrentTenant(Location $location): void
    {
        abort_unless($location->tenant_id === app(Tenant::class)->id, 404);
    }
}
