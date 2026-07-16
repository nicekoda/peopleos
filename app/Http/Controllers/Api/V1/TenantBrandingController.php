<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateTenantBrandingRequest;
use App\Http\Requests\Tenant\UploadTenantLogoRequest;
use App\Http\Resources\TenantBrandingResource;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Checkpoint 47 — singleton-per-tenant branding, same shape as
 * TenantController/TenantModuleController: no {tenant} URL parameter,
 * always operates on app(Tenant::class). Tenant display name
 * deliberately stays on the existing PATCH /tenant mechanism — not
 * duplicated here.
 */
class TenantBrandingController extends Controller
{
    private const STORAGE_DISK = 'public';

    public function show(Request $request): TenantBrandingResource
    {
        $branding = $this->currentBranding();

        return new TenantBrandingResource($branding);
    }

    public function update(UpdateTenantBrandingRequest $request): JsonResponse
    {
        $branding = $this->currentBranding();
        $validated = $request->validated();

        $fieldsChanged = [];
        foreach (['primary_color', 'secondary_color'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== $branding->{$field}) {
                $fieldsChanged[] = $field;
            }
        }

        $branding->fill($validated);
        $branding->updated_by = $request->user()->id;
        if (! $branding->exists) {
            $branding->created_by = $request->user()->id;
        }
        $branding->save();

        if ($fieldsChanged !== []) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'branding.updated',
                module: 'settings',
                tenantId: $branding->tenant_id,
                auditableType: TenantBranding::class,
                auditableId: $branding->id,
                description: 'Tenant branding updated.',
                metadata: ['fields_changed' => $fieldsChanged, 'logo_changed' => false],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        // Explicit 200, not Laravel's default (a JsonResource wrapping a
        // model with wasRecentlyCreated=true auto-responds 201) — this
        // is semantically an update-or-initialize of a singleton config
        // row, never a "created a new resource" action from the
        // caller's point of view, even the first time branding is ever
        // set for a tenant.
        return (new TenantBrandingResource($branding))->response()->setStatusCode(200);
    }

    /**
     * Stored on the public disk under a tenant-scoped, unguessable
     * path — `tenant-branding/{tenant_id}/{random_40}.{ext}` — never
     * a sequential/numeric identifier in the path, and the internal
     * logo_path itself is never returned to the frontend (see
     * TenantBrandingResource, which exposes only the public URL).
     */
    public function uploadLogo(UploadTenantLogoRequest $request): JsonResponse
    {
        $branding = $this->currentBranding();
        $tenantId = $branding->tenant_id;

        $file = $request->file('logo');
        $extension = strtolower($file->getClientOriginalExtension());
        $storedFilename = Str::random(40).'.'.$extension;
        $directory = "tenant-branding/{$tenantId}";
        $storedPath = $file->storeAs($directory, $storedFilename, self::STORAGE_DISK);

        $previousPath = $branding->logo_path;

        $branding->logo_path = $storedPath;
        $branding->logo_original_filename = $file->getClientOriginalName();
        $branding->updated_by = $request->user()->id;
        if (! $branding->exists) {
            $branding->created_by = $request->user()->id;
        }
        $branding->save();

        if ($previousPath) {
            Storage::disk(self::STORAGE_DISK)->delete($previousPath);
        }

        // Never the storage path — only the original filename/mime type,
        // both already known to whoever uploaded the file.
        AuditLogger::logFor(
            actor: $request->user(),
            action: 'branding.logo_uploaded',
            module: 'settings',
            tenantId: $tenantId,
            auditableType: TenantBranding::class,
            auditableId: $branding->id,
            description: 'Tenant logo uploaded.',
            metadata: ['original_filename' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType()],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new TenantBrandingResource($branding))->response()->setStatusCode(201);
    }

    public function removeLogo(Request $request): TenantBrandingResource
    {
        $branding = $this->currentBranding();

        abort_if($branding->logo_path === null, 422, 'No logo is currently set.');

        Storage::disk(self::STORAGE_DISK)->delete($branding->logo_path);

        $branding->logo_path = null;
        $branding->logo_original_filename = null;
        $branding->updated_by = $request->user()->id;
        $branding->save();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'branding.logo_removed',
            module: 'settings',
            tenantId: $branding->tenant_id,
            auditableType: TenantBranding::class,
            auditableId: $branding->id,
            description: 'Tenant logo removed.',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new TenantBrandingResource($branding);
    }

    private function currentBranding(): TenantBranding
    {
        $tenantId = app(Tenant::class)->id;

        return TenantBranding::query()->firstOrNew(['tenant_id' => $tenantId]);
    }
}
