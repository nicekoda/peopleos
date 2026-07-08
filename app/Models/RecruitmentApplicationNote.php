<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecruitmentApplicationNote extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * Internal-only this checkpoint — 'visibility' is always 'internal',
     * never accepted from request input (see StoreApplicationNoteRequest).
     * Fillable only so the controller's explicit server-side default can
     * persist, same reasoning as every other status/system field in this
     * app.
     */
    protected $fillable = [
        'recruitment_application_id',
        'note',
        'visibility',
        'created_by',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class, 'recruitment_application_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
