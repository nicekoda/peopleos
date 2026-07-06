<?php

namespace App\Models;

use App\Enums\HrDocumentTemplateVersionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrDocumentTemplateVersion extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * Deliberately excludes title/description/document_type (Checkpoint 36
     * approved design) — those stay solely on HrDocumentTemplate; only
     * content_template varies per version, avoiding a "which is
     * authoritative" question between the template and its versions.
     */
    protected $fillable = [
        'hr_document_template_id',
        'version_number',
        'content_template',
        'status',
        'published_by',
        'published_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => HrDocumentTemplateVersionStatus::class,
            'version_number' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(HrDocumentTemplate::class, 'hr_document_template_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
