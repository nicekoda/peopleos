<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PolicyAcknowledgementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'policy_id' => $this->policy_id,
            'policy_version_id' => $this->policy_version_id,
            'employee_id' => $this->employee_id,
            'assigned_by' => $this->assigned_by,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'due_date' => $this->due_date?->toDateString(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'acknowledgement_status' => $this->acknowledgement_status->value,
            'acknowledgement_method' => $this->acknowledgement_method?->value,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
