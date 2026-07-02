<?php

namespace App\Http\Requests\Policy;

use App\Enums\PolicyStatus;
use App\Models\Policy;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdatePolicyRequest extends FormRequest
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
        /** @var Policy $policy */
        $policy = $this->route('policy');

        return [
            'title' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('policies', 'title')->where(fn ($q) => $q->where('tenant_id', $tenantId))->ignore($policy->id),
            ],
            'slug' => [
                'sometimes', 'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('policies', 'slug')->where(fn ($q) => $q->where('tenant_id', $tenantId))->ignore($policy->id),
            ],
            'code' => [
                'nullable', 'string', 'max:100',
                Rule::unique('policies', 'code')->where(fn ($q) => $q->where('tenant_id', $tenantId))->ignore($policy->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:255'],
            'owner_user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            // Archiving (status -> archived) is additionally gated by
            // policies.archive in the controller — a route-level
            // middleware can't inspect the request body value.
            'status' => ['sometimes', new Enum(PolicyStatus::class)],
            'effective_date' => ['nullable', 'date'],
            'review_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
        ];
    }
}
