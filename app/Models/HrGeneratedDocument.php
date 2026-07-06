<?php

namespace App\Models;

use App\Enums\HrDocumentType;
use App\Enums\HrGeneratedDocumentStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrGeneratedDocument extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * Deliberately excludes tenant_id — never mass-assignable, always set
     * explicitly by the controller from the resolved tenant. generated_by/
     * created_by/updated_by are never accepted from *request* input either,
     * but must be fillable for the controller's trusted, explicit
     * assignment to actually persist — same reasoning as EmployeeDocument/
     * DocumentCategory.
     */
    protected $fillable = [
        'employee_id',
        'hr_document_template_id',
        'hr_document_template_version_id',
        'employee_document_id',
        'title',
        'document_type',
        'status',
        'rendered_content',
        'generated_at',
        'generated_by',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => HrDocumentType::class,
            'status' => HrGeneratedDocumentStatus::class,
            'generated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(HrDocumentTemplate::class, 'hr_document_template_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(HrDocumentTemplateVersion::class, 'hr_document_template_version_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
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
