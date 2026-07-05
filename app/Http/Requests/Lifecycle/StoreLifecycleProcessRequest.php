<?php

namespace App\Http\Requests\Lifecycle;

use App\Enums\LifecycleProcessType;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreLifecycleProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * status/completed_at/created_by/updated_by are deliberately absent —
     * a new process always starts as draft, set by the controller, never
     * accepted from request input.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'employee_id' => [
                'required', 'string',
                Rule::exists('employees', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')),
            ],
            'type' => ['required', new Enum(LifecycleProcessType::class)],
            'started_at' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
        ];
    }
}
