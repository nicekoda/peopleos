<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately minimal — id/name/subdomain/status/dates only. No
 * billing, security, or system-flag fields exist on the Tenant model to
 * accidentally expose (see docs/architecture.md), and this never will
 * expose anything platform-level (e.g. no list of other tenants —
 * this Resource only ever wraps the single, already-resolved
 * app(Tenant::class) instance, never a queried collection).
 *
 * @return array<string, mixed>
 */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subdomain' => $this->subdomain,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
