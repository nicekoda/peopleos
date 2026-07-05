<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * personal_email and phone are treated as sensitive — visible only
     * with employees.view_sensitive, on top of the employees.view already
     * required to reach this resource at all. work_email is business
     * contact info, not gated.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canViewSensitive = $request->user()?->hasPermission('employees.view_sensitive') ?? false;

        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'preferred_name' => $this->preferred_name,
            'full_name' => $this->fullName(),
            'work_email' => $this->work_email,
            'personal_email' => $canViewSensitive ? $this->personal_email : null,
            'phone' => $canViewSensitive ? $this->phone : null,
            'status' => $this->status->value,
            'employment_type' => $this->employment_type->value,
            // Raw IDs kept for backward compatibility (existing
            // consumers, e.g. the manager-assignment picker, already use
            // them). The nested {id, name} objects below are additive
            // (Checkpoint 32) — EmployeeController eager-loads
            // department/location/position on every response this
            // resource wraps, so these are always resolved, never a
            // query-time surprise; each is simply null if the employee
            // has no department/location/position assigned.
            'department_id' => $this->department_id,
            'location_id' => $this->location_id,
            'position_id' => $this->position_id,
            'department' => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null,
            'location' => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ] : null,
            'position' => $this->position ? [
                'id' => $this->position->id,
                'name' => $this->position->name,
            ] : null,
            'manager_employee_id' => $this->manager_employee_id,
            'start_date' => $this->start_date?->toDateString(),
            'probation_end_date' => $this->probation_end_date?->toDateString(),
            'confirmation_date' => $this->confirmation_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
