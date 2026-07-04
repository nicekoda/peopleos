<?php

namespace App\Http\Resources;

use App\Services\Audit\AuditValueSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately never returns `ip_address`/`user_agent` (Checkpoint 24,
 * Refinement 3) — omitted entirely, not just optional, per your
 * explicit "if unsure, omit" instruction. `metadata`/`old_values`/
 * `new_values` all pass through AuditValueSanitizer before leaving this
 * Resource, independent of whatever masking already happened at write
 * time in AuditLogger.
 *
 * @return array<string, mixed>
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'actor_user_id' => $this->actor_user_id,
            'actor_type' => $this->actor_type,
            'action' => $this->action,
            'module' => $this->module,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'target_user_id' => $this->target_user_id,
            'description' => $this->description,
            'severity' => $this->severity,
            'created_at' => $this->created_at?->toIso8601String(),
            'metadata' => AuditValueSanitizer::sanitize($this->metadata),
            'old_values' => AuditValueSanitizer::sanitize($this->old_values),
            'new_values' => AuditValueSanitizer::sanitize($this->new_values),
        ];
    }
}
