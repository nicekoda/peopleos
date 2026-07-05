<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LifecycleTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'process_id' => $this->process_id,
            'title' => $this->title,
            'description' => $this->description,
            'assigned_to_user_id' => $this->assigned_to_user_id,
            'assigned_to' => $this->whenLoaded('assignedToUser', fn () => $this->assignedToUser === null ? null : [
                'id' => $this->assignedToUser->id,
                'name' => $this->assignedToUser->name,
            ]),
            'status' => $this->status->value,
            'due_date' => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
