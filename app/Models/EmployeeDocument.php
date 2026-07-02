<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeDocument extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * Deliberately excludes tenant_id — never mass-assignable, always set
     * explicitly by the controller from the resolved tenant. Storage
     * fields (stored_filename, storage_disk, storage_path, mime_type,
     * file_extension, file_size, checksum) are set by the controller from
     * the actual uploaded file, never from arbitrary request input.
     */
    protected $fillable = [
        'employee_id',
        'document_category_id',
        'title',
        'description',
        'original_filename',
        'stored_filename',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_extension',
        'file_size',
        'checksum',
        'status',
        'is_sensitive',
        'issue_date',
        'expiry_date',
        'uploaded_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'is_sensitive' => 'boolean',
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'approved_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'document_category_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
