<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'leave_type_id' => $this->leave_type_id,
            'year' => $this->year,
            'entitlement_days' => (float) $this->entitlement_days,
            'used_days' => (float) $this->used_days,
            'pending_days' => (float) $this->pending_days,
            'carried_forward_days' => (float) $this->carried_forward_days,
            'adjustment_days' => (float) $this->adjustment_days,
            // Computed, never stored — see App\Models\LeaveBalance::availableDays().
            'available_days' => $this->availableDays(),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
