<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomFieldOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'option_key' => $this->option_key,
            'label' => $this->label,
            'sort_order' => $this->sort_order,
            'status' => $this->status->value,
        ];
    }
}
