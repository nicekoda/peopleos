<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never exposes `logo_path` (the internal storage path) — only the
 * public, servable URL, built from it (Checkpoint 47). No row ID, no
 * created_by/updated_by, no timestamps — this is display/config data,
 * not an auditable record the frontend needs to reference by ID.
 */
class TenantBrandingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'logo_url' => $this->logoUrl(),
            'logo_original_filename' => $this->logo_original_filename,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
        ];
    }
}
