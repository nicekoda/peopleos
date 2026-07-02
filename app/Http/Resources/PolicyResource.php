<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'code' => $this->code,
            'description' => $this->description,
            'category' => $this->category,
            'owner_user_id' => $this->owner_user_id,
            'status' => $this->status->value,
            'current_version_id' => $this->current_version_id,
            'effective_date' => $this->effective_date?->toDateString(),
            'review_date' => $this->review_date?->toDateString(),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
