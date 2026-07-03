<?php

namespace App\Http\Requests\LeaveType;

use App\Enums\LeaveTypeStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Auto-generate slug from name if not explicitly provided — same
     * pattern as StoreDocumentCategoryRequest.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('slug') && $this->filled('name')) {
            $this->merge(['slug' => Str::slug($this->input('name'))]);
        }
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
                Rule::unique('leave_types', 'name')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('leave_types', 'slug')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_paid' => ['nullable', 'boolean'],
            'requires_approval' => ['nullable', 'boolean'],
            'requires_document' => ['nullable', 'boolean'],
            'max_days_per_year' => ['nullable', 'integer', 'min:0', 'max:365'],
            'status' => ['nullable', new Enum(LeaveTypeStatus::class)],
        ];
    }
}
