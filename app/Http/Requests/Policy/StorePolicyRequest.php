<?php

namespace App\Http\Requests\Policy;

use App\Enums\PolicyStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StorePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('slug') && $this->filled('title')) {
            $this->merge(['slug' => Str::slug($this->input('title'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'title' => [
                'required', 'string', 'max:255',
                Rule::unique('policies', 'title')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('policies', 'slug')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'code' => [
                'nullable', 'string', 'max:100',
                Rule::unique('policies', 'code')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:255'],
            'owner_user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'status' => ['nullable', new Enum(PolicyStatus::class)],
            'effective_date' => ['nullable', 'date'],
            'review_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
        ];
    }
}
