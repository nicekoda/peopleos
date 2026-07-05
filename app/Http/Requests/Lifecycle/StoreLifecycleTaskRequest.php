<?php

namespace App\Http\Requests\Lifecycle;

use App\Models\LifecycleProcess;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLifecycleTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * process_id/completed_at/completed_by/created_by/updated_by are
     * deliberately absent — process_id always comes from the route, the
     * rest are controller-only.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'assigned_to_user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('is_platform_admin', false)
                    ->where('status', User::STATUS_ACTIVE)),
            ],
            'due_date' => ['nullable', 'date'],
        ];
    }

    /**
     * A completed/cancelled process is a closed book — no new tasks.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var LifecycleProcess $process */
            $process = $this->route('lifecycleProcess');

            if ($process->status->isTerminal()) {
                $validator->errors()->add('title', 'Cannot add tasks to a completed or cancelled process.');
            }
        });
    }
}
