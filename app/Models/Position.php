<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'name'];
}
