<?php

namespace App\Http\Requests\CustomFields;

use Illuminate\Foundation\Http\FormRequest;

/**
 * role_id's tenant/platform/Tenant-Admin re-verification, and the
 * can_edit-requires-can_view invariant, all live in
 * CustomFieldVisibilityRuleService — this class only enforces request
 * shape.
 */
class StoreCustomFieldVisibilityRuleRequest extends FormRequest
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
        return [
            'role_id' => ['required'],
            'can_view' => ['required', 'boolean'],
            'can_edit' => ['required', 'boolean'],
        ];
    }
}
