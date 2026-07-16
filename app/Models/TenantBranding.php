<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Singleton per tenant (Checkpoint 47) — a separate table from
 * `tenants` (your explicit approved choice): branding is cosmetic
 * configuration data, `tenants` stays focused on identity/security
 * fields, and a dedicated table keeps this resource's audit/permission
 * boundary clean. Tenant display name continues to use the existing
 * `tenants.name` / PATCH /tenant mechanism — not duplicated here.
 */
class TenantBranding extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'tenant_branding';

    /**
     * tenant_id fillable for the same "explicit assignment outside a
     * resolved-tenant context" reason as TenantModuleAssignment — see
     * TenantBrandingController::show(), which uses firstOrNew() and may
     * create a row explicitly.
     */
    protected $fillable = [
        'tenant_id',
        'logo_path',
        'logo_original_filename',
        'primary_color',
        'secondary_color',
        'created_by',
        'updated_by',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The only safe way this app ever exposes the logo — never
     * `logo_path` itself (see TenantBrandingResource).
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }
}
