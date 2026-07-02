<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Concerns\BelongsToTenant;
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
     * are likewise set explicitly by the controller from the acting user,
     * not accepted as request input.
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
}
