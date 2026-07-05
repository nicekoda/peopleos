<?php

namespace App\Http\Requests\Location;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Checkpoint 32 — name/description only. See
 * App\Http\Requests\Department\StoreDepartmentRequest for the full
 * "why these fields only" reasoning — identical here.
 */
class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('locations', 'name')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
