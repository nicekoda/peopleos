<?php

namespace App\Http\Requests\Location;

use App\Enums\LocationStatus;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Checkpoint 32 — name/description/status only. See
 * App\Http\Requests\Department\UpdateDepartmentRequest for the full
 * "slug never reassigned here" reasoning — identical here.
 */
class UpdateLocationRequest extends FormRequest
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
        /** @var Location $location */
        $location = $this->route('location');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('locations', 'name')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($location->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', new Enum(LocationStatus::class)],
        ];
    }
}
