<?php

namespace App\Models;

use App\Enums\LocationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * tenant_id stays fillable — see Department for the full reasoning
     * (DemoDataSeeder's firstOrCreate() runs outside a bound-Tenant
     * context, so the BelongsToTenant creating-event auto-fill never
     * fires there).
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
            'status' => LocationStatus::class,
        ];
    }
}
