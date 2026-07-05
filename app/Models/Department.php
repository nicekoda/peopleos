<?php

namespace App\Models;

use App\Enums\DepartmentStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * tenant_id stays fillable (unlike Employee's stricter exclusion) —
     * DemoDataSeeder creates these rows via firstOrCreate() outside a
     * real HTTP request, where no Tenant is bound in the container (see
     * docs/architecture.md's CLI/tinker gotcha note), so
     * BelongsToTenant's creating-event auto-fill never fires there;
     * tenant_id must be mass-assignable for the seeder's explicit value
     * to persist at all. DepartmentController never accepts tenant_id
     * from *request* input regardless (not in
     * StoreDepartmentRequest/UpdateDepartmentRequest's rules) — it's
     * always set explicitly server-side from the resolved tenant.
     * created_by/updated_by are fillable for the same "controller's
     * trusted, explicit assignment" reason.
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DepartmentStatus::class,
        ];
    }
}
