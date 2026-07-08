<?php

namespace App\Models;

use App\Enums\ApplicationStage;
use App\Enums\ApplicationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecruitmentApplication extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * resume_document_id/cover_letter stay fillable for the same
     * "controller assigns explicitly, from validated request input"
     * reason as every other field here — see StoreJobApplicationRequest.
     * resume_document_id is reserved/unused this checkpoint (no upload
     * endpoint exists yet).
     */
    protected $fillable = [
        'recruitment_job_id',
        'recruitment_applicant_id',
        'stage',
        'status',
        'resume_document_id',
        'cover_letter',
        'ready_for_conversion',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'stage' => ApplicationStage::class,
            'status' => ApplicationStatus::class,
            'ready_for_conversion' => 'boolean',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(RecruitmentJob::class, 'recruitment_job_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplicant::class, 'recruitment_applicant_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(RecruitmentApplicationNote::class);
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
