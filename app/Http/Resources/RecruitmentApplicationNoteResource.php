<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecruitmentApplicationNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'note' => $this->note,
            'visibility' => $this->visibility,
            'created_by' => $this->created_by,
            'author' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
