<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobOpeningResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
            'position_id' => $this->position_id,
            'position' => $this->whenLoaded('position', fn () => [
                'id' => $this->position->id,
                'name' => $this->position->name,
            ]),
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ]),
            'employment_type' => $this->employment_type?->value,
            'description' => $this->description,
            'status' => $this->status->value,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
