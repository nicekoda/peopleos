<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The module key itself is validated in the controller (422 for
 * unknown/core keys — see TenantModuleController::update()), not here
 * and not via route-model-binding, per your explicit approved choice:
 * this is configuration management, not object lookup, so an unknown
 * key gets a clean validation-style 422, never a 404.
 */
class UpdateTenantModuleRequest extends FormRequest
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
            'enabled' => ['required', 'boolean'],
        ];
    }
}
