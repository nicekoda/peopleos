<?php

namespace App\Http\Requests\LifecycleTaskTemplate;

use App\Enums\LifecycleProcessType;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Checkpoint 42 — type/title/description/due_in_days/sort_order only.
 * tenant_id/created_by/updated_by are structurally absent, same
 * "silently dropped, not merely hidden from the frontend" rule every
 * other Store*Request in this app follows.
 */
class StoreLifecycleTaskTemplateRequest extends FormRequest
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
            'type' => ['required', new Enum(LifecycleProcessType::class)],
            'title' => [
                'required', 'string', 'max:255',
                Rule::unique('lifecycle_task_templates', 'title')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('type', $this->input('type'))),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
