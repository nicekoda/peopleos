<?php

namespace App\Http\Requests\Role;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Checkpoint 28 — name/description only, same as StoreRoleRequest.
 * Slug is deliberately absent here too: slug is set once, at creation,
 * and never editable afterward (the controller never assigns it from
 * this request's validated data at all). The controller independently
 * rejects the update entirely for a system/platform/cross-tenant role
 * before this request's fields are even applied — this request only
 * controls *which fields* a permitted update may change.
 */
class UpdateRoleRequest extends FormRequest
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
        $roleId = $this->route('role')?->id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'name')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'))->ignore($roleId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
