<?php

namespace App\Http\Requests\LeaveType;

use App\Enums\LeaveTypeStatus;
use App\Models\LeaveType;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * PATCH semantics: every field optional. Slug is never auto-
     * regenerated from a changed name here — same reasoning as
     * UpdateDocumentCategoryRequest.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;
        /** @var LeaveType $leaveType */
        $leaveType = $this->route('leaveType');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('leave_types', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($leaveType->id),
            ],
            'slug' => [
                'sometimes', 'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('leave_types', 'slug')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($leaveType->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_paid' => ['sometimes', 'boolean'],
            'requires_approval' => ['sometimes', 'boolean'],
            'requires_document' => ['sometimes', 'boolean'],
            'max_days_per_year' => ['nullable', 'integer', 'min:0', 'max:365'],
            'status' => ['sometimes', new Enum(LeaveTypeStatus::class)],
        ];
    }
}
