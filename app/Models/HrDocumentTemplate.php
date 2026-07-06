<?php

namespace App\Models;

use App\Enums\HrDocumentTemplateStatus;
use App\Enums\HrDocumentType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrDocumentTemplate extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * created_by/updated_by are never accepted from *request* input, but
     * must be fillable for the controller's trusted, explicit assignment
     * to actually persist — same reasoning as DocumentCategory.
     * current_version_id is set only by the controller (on create, and by
     * HrDocumentTemplateVersionController::publish()), never accepted from
     * request input. content_template moved to HrDocumentTemplateVersion
     * in Checkpoint 36 — see docs/architecture.md.
     */
    protected $fillable = [
        'title',
        'slug',
        'description',
        'document_type',
        'status',
        'current_version_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => HrDocumentType::class,
            'status' => HrDocumentTemplateStatus::class,
        ];
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(HrGeneratedDocument::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(HrDocumentTemplateVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(HrDocumentTemplateVersion::class, 'current_version_id');
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
