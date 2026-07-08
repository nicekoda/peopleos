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
     * endpoint exists yet). converted_employee_id/converted_at/
     * converted_by (Checkpoint 40) are set only by
     * JobApplicationController::convertToEmployee() — never accepted
     * from request input (not in ConvertApplicationToEmployeeRequest's
     * rules at all) — but must stay mass-assignable for that explicit,
     * server-side assignment to persist.
     */
    protected $fillable = [
        'recruitment_job_id',
        'recruitment_applicant_id',
        'stage',
        'status',
        'resume_document_id',
        'cover_letter',
        'ready_for_conversion',
        'converted_employee_id',
        'converted_at',
        'converted_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'stage' => ApplicationStage::class,
            'status' => ApplicationStatus::class,
            'ready_for_conversion' => 'boolean',
            'converted_at' => 'datetime',
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

    public function convertedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'converted_employee_id');
    }

    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }
}
