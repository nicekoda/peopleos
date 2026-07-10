<?php

namespace App\Http\Requests\LifecycleTaskTemplate;

use App\Enums\LifecycleProcessType;
use App\Models\LifecycleTaskTemplate;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Checkpoint 42 — type/title/description/due_in_days/sort_order only.
 * Changing `type` after creation is allowed (unlike Department's slug,
 * there's no derived/immutable field here) — the uniqueness check
 * re-evaluates against whichever type ends up in the merged request,
 * falling back to the record's current type when type isn't part of
 * this particular update.
 */
class UpdateLifecycleTaskTemplateRequest extends FormRequest
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
        /** @var LifecycleTaskTemplate $template */
        $template = $this->route('lifecycleTaskTemplate');
        $type = $this->input('type', $template->type->value);

        return [
            'type' => ['sometimes', new Enum(LifecycleProcessType::class)],
            'title' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('lifecycle_task_templates', 'title')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('type', $type))
                    ->ignore($template->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
