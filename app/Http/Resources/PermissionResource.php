<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read-only catalog entry (Checkpoint 23, Refinement 8) — no
 * role/user pivot data, since Permission itself is a global,
 * non-tenant-owned definition (see docs/architecture.md).
 *
 * @return array<string, mixed>
 */
class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'category' => $this->category,
            'description' => $this->description,
        ];
    }
}
