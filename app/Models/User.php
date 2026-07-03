<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasPermissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RuntimeException;

// email_verified_at added in Checkpoint 11 — found missing during the
// required $fillable quality review. Never accepted from request input
// (no FormRequest validates it), but UserSeeder's mass-assignment call
// silently dropped it, same bug class as Employee/DocumentCategory's
// created_by/updated_by in Checkpoint 10.
#[Fillable(['name', 'email', 'password', 'tenant_id', 'status', 'is_platform_admin', 'email_verified_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPermissions, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->is_platform_admin && $user->tenant_id !== null) {
                throw new RuntimeException('Platform admin users must not belong to a tenant.');
            }

            if (! $user->is_platform_admin && $user->tenant_id === null) {
                throw new RuntimeException('Tenant users must belong to a tenant.');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The employee record this user account is linked to, if any. See
     * app/Http/Controllers/Api/V1/EmployeeUserLinkController.php for how
     * the link is created — never automatic, always an explicit,
     * permission-gated, audited action.
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function hasLinkedEmployee(): bool
    {
        return $this->employee()->exists();
    }
}
