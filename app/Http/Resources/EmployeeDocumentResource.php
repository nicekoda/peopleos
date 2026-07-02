<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeDocumentResource extends JsonResource
{
    /**
     * Deliberately never exposes storage_disk/storage_path/stored_filename
     * — those are internal storage details, not something a client needs
     * or should see. original_filename is safe (just a display name).
     * Sensitive-document gating (documents.view_sensitive) happens at the
     * controller/query level — a sensitive document a user can't see is
     * excluded entirely, not included with masked fields, since a
     * document's mere existence can itself be sensitive.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'document_category_id' => $this->document_category_id,
            'title' => $this->title,
            'description' => $this->description,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_extension' => $this->file_extension,
            'file_size' => $this->file_size,
            'status' => $this->status->value,
            'is_sensitive' => $this->is_sensitive,
            'issue_date' => $this->issue_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'uploaded_by' => $this->uploaded_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
