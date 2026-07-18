<?php

namespace App\Models;

use App\Enums\CustomFieldEntity;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Concerns\BelongsToTenant;
use App\Services\ManagerHierarchyService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * Deliberately excludes tenant_id — never mass-assignable, always set
     * explicitly by the controller from the resolved tenant (see
     * StoreEmployeeRequest / EmployeeController). created_by/updated_by
     * ARE fillable (fixed in Checkpoint 10 — see note below): they're
     * never accepted from *request* input, but must be mass-assignable
     * for the controller's trusted, explicit assignment to persist at all.
     */
    protected $fillable = [
        'employee_number',
        'first_name',
        'middle_name',
        'last_name',
        'preferred_name',
        'work_email',
        'personal_email',
        'phone',
        'status',
        'employment_type',
        'department_id',
        'location_id',
        'position_id',
        'manager_employee_id',
        'start_date',
        'probation_end_date',
        'confirmation_date',
        // created_by/updated_by are never accepted as *request* input
        // (not in StoreEmployeeRequest's rules) but are always set
        // explicitly by the controller from the trusted authenticated
        // user — they must be fillable for that assignment to actually
        // persist. Excluding them silently dropped every created_by/
        // updated_by value since Checkpoint 6 (found and fixed in
        // Checkpoint 10).
        'created_by',
        'updated_by',
        // Set only by EmployeeUserLinkController, never by
        // StoreEmployeeRequest/UpdateEmployeeRequest — linking is a
        // distinct, permission-gated action (employees.link_user /
        // employees.unlink_user), not a field editable via the general
        // employee update endpoint.
        'user_id',
        'linked_at',
        'linked_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'employment_type' => EmploymentType::class,
            'start_date' => 'date',
            'probation_end_date' => 'date',
            'confirmation_date' => 'date',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_employee_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /**
     * Checkpoint 51 — entity #3 for the custom-fields engine, mirroring
     * RecruitmentApplicant's own relation exactly.
     */
    public function customFieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class, 'entity_id')
            ->where('entity_type', CustomFieldEntity::Employee->value);
    }

    public function policyAcknowledgements(): HasMany
    {
        return $this->hasMany(PolicyAcknowledgement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Convenience wrappers over ManagerHierarchyService — see
     * docs/architecture.md for why the actual logic lives in the
     * service (reusable outside a model instance context) rather than
     * here directly.
     */
    public function manages(self $employee): bool
    {
        return app(ManagerHierarchyService::class)->isManagerOf($this, $employee);
    }

    public function directlyManages(self $employee): bool
    {
        return app(ManagerHierarchyService::class)->directlyManages($this, $employee);
    }
}
